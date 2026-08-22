/**
 * Makes the assistant side panel behave as a modal while it is open, which is what WCAG 2.2 AA
 * 2.4.11 "Focus Not Obscured (Minimum)" requires of it.
 *
 * The panel is `position: fixed` over the page — a full-height 24rem column on a desktop, and
 * essentially the whole screen on a phone — but the page behind it stays in the tab order. So a
 * keyboard user tabs into controls that are ENTIRELY hidden underneath it, which is the failure
 * 2.4.11 names (partial cover passes at AA; total cover does not). Measured, not assumed:
 * `npm run a11y:focus` found three such stops before this existed.
 *
 * The fix is one attribute. While the panel is open, every element that is NOT an ancestor of it
 * gets `inert`, so nothing behind can take focus and nothing focused can be obscured. The panel
 * lives inside <main>, so we cannot simply inert the landmarks: we walk up from the panel and
 * inert each level's siblings instead.
 *
 * Bundled, no inline JS (CSP-safe), and wrapped in try/catch: if any of it fails the panel is
 * merely non-modal again, never broken. Re-synced on every Livewire morph, because opening and
 * closing the panel IS a morph (the edge tab and the panel replace each other server-side).
 */

const PANEL = '[data-assistant-open]'
const EDGE_TAB = '[data-assistant-tab]'

let inerted = []
let wasOpen = false

function clear() {
    inerted.forEach((el) => {
        el.inert = false
    })
    inerted = []
}

function sync() {
    try {
        clear()

        const panel = document.querySelector(PANEL)
        if (!panel) {
            // Just closed by the user: put focus back on the tab that opened it, rather than
            // dropping it on <body> and sending the next Tab to the top of the document.
            if (wasOpen) {
                document.querySelector(EDGE_TAB)?.focus()
            }
            wasOpen = false
            return
        }

        for (let el = panel; el && el.parentElement && el !== document.body; el = el.parentElement) {
            Array.from(el.parentElement.children).forEach((sibling) => {
                if (sibling !== el) {
                    sibling.inert = true
                    inerted.push(sibling)
                }
            })
        }

        wasOpen = true
    } catch (e) {
        clear()
    }
}

// childList only, deliberately: `el.inert = true` writes an attribute, so observing attributes
// would make this re-enter itself on every pass.
const observer = new MutationObserver(sync)

function start() {
    sync()
    observer.observe(document.body, { childList: true, subtree: true })
}

document.addEventListener('DOMContentLoaded', start)
document.addEventListener('livewire:navigated', sync)

if (document.readyState !== 'loading') {
    start()
}
