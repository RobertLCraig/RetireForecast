/**
 * Accessibility sweep of the SIGNED-IN pages, at both a desktop and a phone viewport.
 *
 * Why this exists alongside .pa11yci.json: Pa11y CI covers the public pages, but the forecast
 * itself lives behind a login and needs a saved scenario, and its Livewire panels take a moment
 * to settle. Scanning too early reports a page-wide wall of contrast "failures" that are really
 * a transient loading overlay, so this waits for the network to go idle and then pauses before
 * running axe. It drives current axe-core with NO tag filter, which is what brings the WCAG 2.2
 * AA target-size rule into scope (Pa11y's `standard` option only reaches WCAG 2.1).
 *
 * It is a check, not a gate: exits non-zero when any violation is found, so it can be run by
 * hand or wired into a job later. A real-browser pass with axe DevTools is still the authority
 * for the WCAG 2.2 criteria no tool can automate (focus not obscured, dragging movements,
 * consistent help, redundant entry, accessible authentication) and for the chart canvases.
 *
 * Usage (from the project root, with the app served and a demo scenario seeded):
 *   php artisan db:seed --class="Database\Seeders\DemoScenarioSeeder"
 *   php artisan serve --port=8000                                        # one shell
 *   $env:PUPPETEER_EXECUTABLE_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe"
 *   npm run a11y:auth -- --base=http://127.0.0.1:8000 --scenario=1       # another
 */
import fs from 'node:fs';
import { createRequire } from 'node:module';
import puppeteer from 'puppeteer';

const require = createRequire(import.meta.url);
const axeSource = fs.readFileSync(require.resolve('axe-core'), 'utf8');

const arg = (name, fallback) => {
    const found = process.argv.find(a => a.startsWith(`--${name}=`));
    return found ? found.slice(name.length + 3) : fallback;
};

const base = arg('base', 'http://127.0.0.1:8000');
const scenario = arg('scenario', '1');
const email = arg('email', 'demo@example.com');
const password = arg('password', 'password');

const paths = [
    '/dashboard',
    '/scenarios/create',
    `/scenarios/${scenario}/edit`,
    `/scenarios/${scenario}/results`,
    `/scenarios/${scenario}/compare`,
    `/scenarios/${scenario}/afford`,
    '/account/security',
];

const viewports = [
    ['desktop', { width: 1280, height: 900 }],
    ['mobile', { width: 375, height: 667, isMobile: true, deviceScaleFactor: 2 }],
];

const browser = await puppeteer.launch({
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH,
    args: ['--no-sandbox', '--disable-gpu'],
});

let violations = 0;

/** Run current axe over whatever is on screen now, print every failure, and count the nodes. */
async function scan(page, label, path) {
    await page.evaluate(axeSource);
    const result = await page.evaluate(() => window.axe.run(document, { resultTypes: ['violations'] }));

    const count = result.violations.reduce((n, v) => n + v.nodes.length, 0);
    violations += count;
    console.log(`${count === 0 ? 'ok  ' : 'FAIL'} ${label.padEnd(7)} ${path} (${count} violation node(s))`);

    for (const v of result.violations) {
        console.log(`      [${v.impact}] ${v.id}: ${v.help}`);
        for (const node of v.nodes) {
            console.log(`        ${JSON.stringify(node.target)}`);
            console.log(`        ${node.html.slice(0, 180).replace(/\s+/g, ' ')}`);
            console.log(`        ${(node.failureSummary || '').replace(/\s+/g, ' ')}`);
        }
    }
}

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

        for (const path of paths) {
            await page.goto(base + path, { waitUntil: 'networkidle0' });

            // A secured page (/account/security) bounces through Fortify's password-confirmation
            // interstitial. Nothing else ever reaches that screen, so scan it on the way past,
            // then confirm and carry on to the page we actually asked for.
            if (page.url().includes('confirm-password')) {
                await scan(page, label, '/user/confirm-password');
                await page.type('#password', password);
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle0' }),
                    page.click('button[type=submit]'),
                ]);
            }

            await new Promise(resolve => setTimeout(resolve, 3000));
            await scan(page, label, path);
        }
    }
} finally {
    await browser.close();
}

console.log(violations === 0 ? '\nAll signed-in pages passed.' : `\n${violations} violation node(s).`);
process.exit(violations === 0 ? 0 : 1);
