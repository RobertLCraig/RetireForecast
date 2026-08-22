/**
 * Collapsible results sections — progressive enhancement that turns each results-page card
 * heading into a disclosure toggle, so a long page is scannable instead of one flat wall.
 *
 * Pure enhancement: without JS every section is fully visible (nothing is hidden server-side),
 * so the page is never dependent on this. Bundled (CSP-safe). Open/closed state is remembered
 * per section in localStorage. Re-applied after a Livewire morph (which re-renders sections from
 * server HTML that has no collapsed state), like toc.js. The whole thing is wrapped in try/catch
 * so any failure simply leaves the page un-collapsed — never broken.
 *
 * Each results section is `[id^="sec-"]` with a heading (`h2`). The section's direct child that
 * contains the heading stays visible; its other direct children are the collapsible body.
 */

// Detail / show-your-working sections collapsed by default; the headline outputs start open.
const DEFAULT_COLLAPSED = new Set([
    'sec-assumptions',
    'sec-plsa',
    'sec-sensitivity',
    'sec-sale',
    'sec-shock',
])

const STORAGE_PREFIX = 'rf-collapse:'

function stored(id) {
    try {
        return localStorage.getItem(STORAGE_PREFIX + id)
    } catch (e) {
        return null
    }
}

function remember(id, collapsed) {
    try {
        localStorage.setItem(STORAGE_PREFIX + id, collapsed ? '1' : '0')
    } catch (e) {
        // ignore (private mode / storage disabled)
    }
}

// The section's direct child that contains the heading (often the heading itself).
function headingHost(section, heading) {
    let host = heading
    while (host.parentElement && host.parentElement !== section) {
        host = host.parentElement
    }
    return host
}

// The control the reader actually operates. It is a real <button> INSIDE the <h2>, not the
// <h2> itself wearing role="button": that role replaces the heading semantics, so a long
// results page loses every entry from the screen-reader heading list (axe aria-allowed-role).
// Falls back to the heading while the button is being created.
function controlFor(heading) {
    return heading.querySelector('[data-collapse-toggle]') || heading
}

function apply(section, heading, collapsed) {
    const host = headingHost(section, heading)
    const control = controlFor(heading)
    Array.from(section.children).forEach((child) => {
        if (child !== host) {
            child.hidden = collapsed
        }
    })
    section.dataset.collapsed = collapsed ? 'true' : 'false'
    control.setAttribute('aria-expanded', collapsed ? 'false' : 'true')

    let chevron = control.querySelector('[data-collapse-chevron]')
    if (!chevron) {
        chevron = document.createElement('span')
        chevron.setAttribute('data-collapse-chevron', '')
        chevron.setAttribute('aria-hidden', 'true')
        chevron.className = 'mr-2 inline-block text-gray-600'
        control.prepend(chevron)
    }
    chevron.textContent = collapsed ? '▸' : '▾' // ▸ / ▾
}

function enhance(section) {
    const heading = section.querySelector('h2')
    if (!heading) {
        return
    }

    const id = section.id
    const saved = stored(id)
    const collapsed = saved === null ? DEFAULT_COLLAPSED.has(id) : saved === '1'

    if (!heading.querySelector('[data-collapse-toggle]')) {
        // Move the heading's own content into a real button, so the h2 stays a heading and the
        // control gets native Enter/Space, focus and role. Tailwind's preflight makes a button
        // inherit the heading's font, so this is invisible apart from the pointer cursor.
        const button = document.createElement('button')
        button.type = 'button'
        button.setAttribute('data-collapse-toggle', '')
        button.className = 'flex w-full cursor-pointer select-none items-center text-left'
        while (heading.firstChild) {
            button.appendChild(heading.firstChild)
        }
        heading.appendChild(button)

        button.addEventListener('click', () => {
            const next = section.dataset.collapsed !== 'true'
            apply(section, heading, next)
            remember(id, next)
        })
        section.dataset.collapseReady = 'true'
    }

    apply(section, heading, collapsed)
}

function initCollapse() {
    try {
        document.querySelectorAll('[id^="sec-"]').forEach(enhance)
    } catch (e) {
        // no-op: leave the page fully expanded
    }
}

// Open a section when it is navigated to via an anchor (the on-page nav / "new in this build"
// links), so a jump never lands on a collapsed heading.
function openTarget() {
    try {
        const id = location.hash.replace('#', '')
        const section = id ? document.getElementById(id) : null
        const heading = section ? section.querySelector('h2') : null
        if (section && heading && section.dataset.collapsed === 'true') {
            apply(section, heading, false)
            remember(id, false)
        }
    } catch (e) {
        // ignore
    }
}

document.addEventListener('DOMContentLoaded', initCollapse)
document.addEventListener('livewire:navigated', initCollapse)
window.addEventListener('hashchange', openTarget)

let reinitTimer = null
document.addEventListener('livewire:init', () => {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('commit', ({ succeed }) => {
            if (typeof succeed === 'function') {
                succeed(() => {
                    clearTimeout(reinitTimer)
                    reinitTimer = setTimeout(initCollapse, 150)
                })
            }
        })
    }
})

// The bundle is deferred, so DOMContentLoaded may already have fired.
if (document.readyState !== 'loading') {
    initCollapse()
}
