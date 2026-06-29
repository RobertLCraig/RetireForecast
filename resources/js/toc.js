/**
 * "On this page" floating side-nav scroll-spy for the results page.
 *
 * Pure progressive enhancement: the nav links are real anchors that work without any JS
 * (and without this, the page is simply a long scroll). This only highlights the section
 * currently in view. Bundled (not inline) so it is CSP-safe, and a no-op where there is no
 * nav or no IntersectionObserver.
 *
 * The nav is `[data-results-toc]`; each link is `[data-toc-link="<section id>"]` and points
 * at a `#<section id>` on the page. Re-initialised after a Livewire SPA navigation; in-page
 * Livewire updates morph the same nodes in place, so the observer keeps firing.
 */
let observer = null

function highlight(links, activeId) {
    links.forEach((link) => {
        const on = link.dataset.tocLink === activeId
        link.classList.toggle('border-blue-600', on)
        link.classList.toggle('text-blue-700', on)
        link.classList.toggle('font-medium', on)
        link.classList.toggle('border-transparent', !on)
        if (on) {
            link.setAttribute('aria-current', 'true')
        } else {
            link.removeAttribute('aria-current')
        }
    })
}

function initToc() {
    if (observer) {
        observer.disconnect()
        observer = null
    }

    const nav = document.querySelector('[data-results-toc]')
    if (!nav || typeof IntersectionObserver === 'undefined') {
        return
    }

    const links = Array.from(nav.querySelectorAll('[data-toc-link]'))
    const sections = links
        .map((link) => document.getElementById(link.dataset.tocLink))
        .filter((el) => el !== null)
    if (sections.length === 0) {
        return
    }

    const inView = new Set()
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    inView.add(entry.target.id)
                } else {
                    inView.delete(entry.target.id)
                }
            })
            // The first in-view section in document order is the active one.
            const current = sections.find((section) => inView.has(section.id))
            if (current) {
                highlight(links, current.id)
            }
        },
        // A section becomes active once it reaches the top third of the viewport.
        { rootMargin: '0px 0px -66% 0px', threshold: 0 },
    )
    sections.forEach((section) => observer.observe(section))
}

let reinitTimer = null
function scheduleReinit() {
    // Debounce: a Livewire update can patch many nodes; re-init once it settles.
    clearTimeout(reinitTimer)
    reinitTimer = setTimeout(initToc, 150)
}

document.addEventListener('DOMContentLoaded', initToc)
document.addEventListener('livewire:navigated', initToc)

// After an in-page Livewire update (e.g. running a preview reveals the results sections, or
// switching the ladder strategy), re-observe so the highlight tracks any new/changed sections.
// Guarded: if the hook API isn't present this is simply a no-op, and the anchor links work
// regardless. (Livewire morphs existing section nodes in place, so this only fills the gap
// for sections that appear or disappear.)
document.addEventListener('livewire:init', () => {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('commit', ({ succeed }) => {
            if (typeof succeed === 'function') {
                succeed(scheduleReinit)
            }
        })
    }
})

// The bundle is deferred, so DOMContentLoaded may already have fired.
if (document.readyState !== 'loading') {
    initToc()
}
