/**
 * Highlights the scenario-builder inputs whose value differs from the base plan, when
 * editing a what-if (a delta-child). Pure progressive enhancement: the form works without
 * this; it only rings the changed fields so the user sees, at a glance, what this what-if
 * changes from its base.
 *
 * The server renders the form with [data-builder-diff] and a JSON object in [data-changes]
 * mapping each changed wire:model path (index-based, matching each input's wire:model) to
 * the base value it diverged from, formatted for display. This rings each changed input and
 * shows its base value ("was £18,000") under the field, so the user sees both what this
 * what-if changes and what it changed from.
 *
 * The base value is shown via the field wrapper's `::after` (a data attribute), not an
 * injected node, so it never confuses Livewire's morph. Re-applied after Livewire updates
 * (which morph the inputs and strip the client-set class/attribute). Bundled (not inline)
 * so it is CSP-safe, and a no-op when the attributes are absent.
 */
function wireModelPath(el) {
    for (const attr of el.attributes) {
        if (attr.name === 'wire:model' || attr.name.startsWith('wire:model.')) {
            return attr.value
        }
    }
    return null
}

function clearBuilderDiff() {
    document.querySelectorAll('.builder-diff-changed').forEach((el) => el.classList.remove('builder-diff-changed'))
    document.querySelectorAll('.builder-diff-field').forEach((el) => {
        el.classList.remove('builder-diff-field')
        el.removeAttribute('data-original')
    })
}

function applyBuilderDiff() {
    // Clear any previous marks first (a re-render may have changed the set, or left the what-if).
    clearBuilderDiff()

    const form = document.querySelector('[data-builder-diff]')
    if (!form) {
        return
    }

    let changes
    try {
        changes = JSON.parse(form.getAttribute('data-changes') || '{}')
    } catch (e) {
        return
    }
    if (!changes || typeof changes !== 'object' || Object.keys(changes).length === 0) {
        return
    }

    form.querySelectorAll('input, select, textarea').forEach((el) => {
        const path = wireModelPath(el)
        if (!path || !Object.prototype.hasOwnProperty.call(changes, path)) {
            return
        }
        // Ring the changed input...
        el.classList.add('builder-diff-changed')
        // ...and show the base value on its wrapping field via a ::after (no injected node).
        const field = el.closest('div')
        if (field) {
            field.classList.add('builder-diff-field')
            field.setAttribute('data-original', changes[path])
        }
    })
}

let timer = null
function schedule() {
    // Debounce: a Livewire update can morph many nodes; re-apply once it settles.
    clearTimeout(timer)
    timer = setTimeout(applyBuilderDiff, 100)
}

document.addEventListener('DOMContentLoaded', applyBuilderDiff)
document.addEventListener('livewire:navigated', applyBuilderDiff)

// After an in-page Livewire update (a step change, adding a row, typing into a synced field),
// re-apply: the morph rewrites inputs from the server HTML, which does not carry the ring class.
// Guarded so it is a no-op if the hook API differs; the form still works regardless.
document.addEventListener('livewire:init', () => {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('commit', ({ succeed }) => {
            if (typeof succeed === 'function') {
                succeed(schedule)
            }
        })
    }
})

// The bundle is deferred, so DOMContentLoaded may already have fired.
if (document.readyState !== 'loading') {
    applyBuilderDiff()
}
