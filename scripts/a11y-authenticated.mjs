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
 * `/account/security` is swept in all three of its states, not just the one it loads in.
 * The enrolment panel (QR code + setup key) and the enabled panel (recovery-code list) only
 * render after a click that mutates the user, so this drives the whole enrolment: it clicks
 * Turn on, scans, reads the setup key off the page, computes the authenticator code itself
 * (see `totp` below), confirms, scans again, and then turns 2FA back off so the user is left
 * exactly as it found them. That last step is what makes the sweep safe to re-run.
 *
 * Usage (from the project root, with the app served and a demo scenario seeded):
 *   php artisan db:seed --class="Database\Seeders\DemoScenarioSeeder"
 *   php artisan serve --port=8000                                        # one shell
 *   $env:PUPPETEER_EXECUTABLE_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe"
 *   npm run a11y:auth -- --base=http://127.0.0.1:8000 --scenario=1       # another
 */
import { createHmac } from 'node:crypto';
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
    // Check we are actually on the page we are about to call green. A scan of the wrong page
    // is worse than a failing one: it reads as coverage. This sweep called /account/security
    // and every mobile page green for a while when it had in fact logged itself out and was
    // looking at the landing and login pages.
    const expected = path.split(' ')[0];
    const actual = new URL(page.url()).pathname;

    if (actual !== expected) {
        violations += 1;
        console.log(`FAIL ${label.padEnd(7)} ${path} — the browser is on ${actual}, so this page was never scanned`);

        return;
    }

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

/**
 * RFC 6238 TOTP for a base32 secret: SHA-1, 6 digits, 30-second step, which is what
 * google2fa (the library behind Fortify's two-factor feature) issues and verifies.
 * Written out rather than pulled in as a dependency because it is fifteen lines of
 * node's own crypto, and this is the only caller.
 */
function totp(base32Secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const character of base32Secret.toUpperCase().replace(/=+$/, '')) {
        bits += alphabet.indexOf(character).toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map(byte => parseInt(byte, 2)));

    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));

    const digest = createHmac('sha1', key).update(counter).digest();
    const offset = digest[digest.length - 1] & 0xf;

    return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

/**
 * Type into a field and check the value actually landed. Puppeteer typing into a freshly
 * loaded page can silently go nowhere (it did here on the autofocused password-confirmation
 * field), and a sweep that then submits an empty form just sits there until it times out. One
 * retry after a settle fixes it; failing loudly beats a mystery timeout either way.
 */
async function fill(page, selector, value) {
    for (const attempt of [0, 1]) {
        await page.focus(selector);
        await page.keyboard.type(value);

        if (await page.$eval(selector, element => element.value) === value) {
            return;
        }

        await page.$eval(selector, element => { element.value = ''; });
        await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
    }

    throw new Error(`could not type into ${selector}: the field stayed empty`);
}

/** Click a Livewire control by the action it calls, so no styling class is load-bearing here. */
const clickWire = (page, action) => page.evaluate(name => {
    const control = document.querySelector(`[wire\\:click="${name}"]`);
    if (! control) {
        throw new Error(`no control on this page calls wire:click="${name}"`);
    }
    control.click();
}, action);

/**
 * Sweep the two states of `/account/security` that a plain page load never shows, then put
 * the user back. Assumes 2FA starts off, which holds because this always turns it back off.
 */
async function scanTwoFactorEnrolment(page, label) {
    await clickWire(page, 'enable');
    await page.waitForSelector('#code');
    await scan(page, label, '/account/security (2FA enrolment: QR + setup key)');

    const setupKey = await page.$eval('code', element => element.textContent.trim());
    await fill(page, '#code', totp(setupKey));
    // Via the field's own form, because the header's Log out button is the first submit here.
    await page.evaluate(() => document.querySelector('#code').form.querySelector('button[type=submit]').click());

    // Only a valid code stamps two_factor_confirmed_at, so the recovery codes appearing is
    // the proof the confirm landed; a wrong code would leave us on the enrolment panel.
    await page.waitForFunction(() => document.body.innerText.includes('Recovery codes'), { timeout: 15000 });
    await scan(page, label, '/account/security (2FA on: recovery codes)');

    await clickWire(page, 'disable');
    await page.waitForSelector('[wire\\:click="enable"]');
}

try {
    const page = await browser.newPage();
    await page.setViewport(viewports[0][1]);

    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
    await fill(page, '#email', email);
    await fill(page, '#password', password);
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
                await fill(page, '#password', password);
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle0' }),
                    // Scoped to the form on purpose: this page renders inside the signed-in
                    // layout, whose header carries a Log out button that comes first in the
                    // DOM, so a bare `button[type=submit]` signs the sweep out instead.
                    page.click('form[action*="confirm-password"] button[type=submit]'),
                ]);
            }

            await new Promise(resolve => setTimeout(resolve, 3000));
            await scan(page, label, path);

            // Only drive the enrolment if we really got to the page; if we did not, the scan
            // above has already reported it, and clicking blind would bury that under a crash.
            if (path === '/account/security' && new URL(page.url()).pathname === path) {
                await scanTwoFactorEnrolment(page, label);
            }
        }
    }
} finally {
    await browser.close();
}

console.log(violations === 0 ? '\nAll signed-in pages passed.' : `\n${violations} violation node(s).`);
process.exit(violations === 0 ? 0 : 1);
