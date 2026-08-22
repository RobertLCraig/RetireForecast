/**
 * WCAG 2.2 AA 2.4.11 "Focus Not Obscured (Minimum)" check, which no a11y linter automates:
 * tab through a page and report any control that, once focused, is ENTIRELY covered by
 * author-created content (a fixed/sticky overlay). Partial cover passes at AA; only total
 * cover fails.
 *
 * The page that matters here is the results page with the assistant side panel open, because
 * that panel is `position: fixed` and, on a phone, is as wide as the viewport.
 *
 * Usage (from the project root, with the app served and a demo scenario seeded):
 *   $env:PUPPETEER_EXECUTABLE_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe"
 *   npm run a11y:focus -- --base=http://127.0.0.1:8000 --scenario=1
 */
import puppeteer from 'puppeteer';

const arg = (name, fallback) => {
    const found = process.argv.find(a => a.startsWith(`--${name}=`));
    return found ? found.slice(name.length + 3) : fallback;
};

const base = arg('base', 'http://127.0.0.1:8000');
const scenario = arg('scenario', '1');
const email = arg('email', 'demo@example.com');
const password = arg('password', 'password');

const viewports = [
    ['desktop', { width: 1280, height: 900 }],
    ['mobile', { width: 375, height: 667, isMobile: true, deviceScaleFactor: 2 }],
];

/**
 * Is the focused element entirely hidden behind something else? Sample a grid over its box
 * and ask the document what actually paints at each point; if the element (or a descendant)
 * is never the topmost thing, nothing of it is visible.
 */
const describeFocus = () => {
    const el = document.activeElement;
    if (!el || el === document.body) return null;

    const rect = el.getBoundingClientRect();
    const label = `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} "${(el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 40)}"`;
    if (rect.width === 0 || rect.height === 0) return { label, skipped: 'zero-size' };

    let visible = false;
    let cover = null;
    for (const fx of [0.1, 0.5, 0.9]) {
        for (const fy of [0.1, 0.5, 0.9]) {
            const x = rect.left + rect.width * fx;
            const y = rect.top + rect.height * fy;
            if (x < 0 || y < 0 || x > window.innerWidth || y > window.innerHeight) continue;
            const top = document.elementFromPoint(x, y);
            if (!top) continue;
            if (top === el || el.contains(top) || top.contains(el)) { visible = true; }
            else if (!cover) { cover = `${top.tagName.toLowerCase()}.${(top.className || '').toString().split(' ').slice(0, 3).join('.')}`; }
        }
    }
    return { label, obscured: !visible && cover !== null, cover };
};

const browser = await puppeteer.launch({
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH,
    args: ['--no-sandbox', '--disable-gpu'],
});

let failures = 0;

try {
    const page = await browser.newPage();
    await page.setViewport(viewports[0][1]);

    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);

    for (const [label, viewport] of viewports) {
        await page.setViewport(viewport);
        await page.goto(`${base}/scenarios/${scenario}/results`, { waitUntil: 'networkidle0' });
        await new Promise(resolve => setTimeout(resolve, 3000));

        // Open the assistant, which is the one fixed overlay big enough to hide a control.
        const opened = await page.evaluate(() => {
            const tab = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Ask about this forecast'));
            if (!tab) return false;
            tab.click();
            return true;
        });
        if (!opened) {
            console.log(`skip ${label.padEnd(7)} assistant edge tab not present (ASSISTANT_ENABLED off?)`);
            continue;
        }
        await page.waitForSelector('[data-assistant-open]', { timeout: 10000 });
        await new Promise(resolve => setTimeout(resolve, 500));

        await page.evaluate(() => document.body.focus());
        const obscured = [];
        const seen = new Set();
        for (let i = 0; i < 250; i++) {
            await page.keyboard.press('Tab');
            const found = await page.evaluate(describeFocus);
            if (!found) break;
            if (seen.has(found.label)) break;
            seen.add(found.label);
            if (found.obscured) obscured.push(found);
        }

        failures += obscured.length;
        console.log(`${obscured.length === 0 ? 'ok  ' : 'FAIL'} ${label.padEnd(7)} results + assistant open: ${obscured.length} of ${seen.size} focus stops entirely obscured`);
        for (const o of obscured.slice(0, 5)) console.log(`        ${o.label} — covered by ${o.cover}`);
        if (obscured.length > 5) console.log(`        …and ${obscured.length - 5} more`);

        // Closing must undo all of it. An `inert` left behind would silently freeze the page,
        // which is a worse fault than the one this check exists for.
        await page.evaluate(() => document.querySelector('[data-assistant-open] button[aria-label="Close the assistant"]').click());
        await page.waitForSelector('[data-assistant-tab]', { timeout: 10000 });
        await new Promise(resolve => setTimeout(resolve, 500));
        const afterClose = await page.evaluate(() => ({
            stillInert: document.querySelectorAll('[inert]').length,
            focusReturned: document.activeElement?.hasAttribute('data-assistant-tab') === true,
        }));
        const restored = afterClose.stillInert === 0 && afterClose.focusReturned;
        failures += restored ? 0 : 1;
        console.log(`${restored ? 'ok  ' : 'FAIL'} ${label.padEnd(7)} assistant closed: ${afterClose.stillInert} element(s) left inert, focus back on the tab: ${afterClose.focusReturned}`);
    }
} finally {
    await browser.close();
}

console.log(failures === 0 ? '\nNo focus stop is entirely obscured (WCAG 2.2 AA 2.4.11).' : `\n${failures} obscured focus stop(s).`);
process.exit(failures === 0 ? 0 : 1);
