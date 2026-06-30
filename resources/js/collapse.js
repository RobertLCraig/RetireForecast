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

function apply(section, heading, collapsed) {
    const host = headingHost(section, heading)
    Array.from(section.children).forEach((child) => {
        if (child !== host) {
            child.hidden = collapsed
        }
    })
    section.dataset.collapsed = collapsed ? 'true' : 'false'
    heading.setAttribute('aria-expanded', collapsed ? 'false' : 'true')

    let chevron = heading.querySelector('[data-collapse-chevron]')
    if (!chevron) {
        chevron = document.createElement('span')
        chevron.setAttribute('data-collapse-chevron', '')
        chevron.setAttribute('aria-hidden', 'true')
        chevron.className = 'mr-2 inline-block text-gray-400'
        heading.prepend(chevron)
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

    if (section.dataset.collapseReady !== 'true') {
        heading.setAttribute('role', 'button')
        heading.setAttribute('tabindex', '0')
        heading.classList.add('cursor-pointer', 'select-none')

        const toggle = () => {
            const next = section.dataset.collapsed !== 'true'
            apply(section, heading, next)
            remember(id, next)
        }
        heading.addEventListener('click', toggle)
        heading.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                toggle()
            }
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
