/**
 * Highlights the scenario-builder inputs whose value differs from the base plan, when
 * editing a what-if (a delta-child). Pure progressive enhancement: the form works without
 * this; it only rings the changed fields so the user sees, at a glance, what this what-if
 * changes from its base.
 *
 * The server renders the form with [data-builder-diff] and a JSON list of changed
 * wire:model paths in [data-changed-paths] (index-based, matching each input's wire:model).
 * Re-applied after Livewire updates (which morph the inputs and may strip the ring class).
 * Bundled (not inline) so it is CSP-safe, and a no-op when the attributes are absent.
 */
function wireModelPath(el) {
    for (const attr of el.attributes) {
        if (attr.name === 'wire:model' || attr.name.startsWith('wire:model.')) {
            return attr.value
        }
    }
    return null
}

function applyBuilderDiff() {
    // Clear any previous rings first (a re-render may have changed the set, or left the what-if).
    document.querySelectorAll('.builder-diff-changed').forEach((el) => el.classList.remove('builder-diff-changed'))

    const form = document.querySelector('[data-builder-diff]')
    if (!form) {
        return
    }

    let changed
    try {
        changed = JSON.parse(form.getAttribute('data-changed-paths') || '[]')
    } catch (e) {
        return
    }
    if (!Array.isArray(changed) || changed.length === 0) {
        return
    }

    const set = new Set(changed)
    form.querySelectorAll('input, select, textarea').forEach((el) => {
        const path = wireModelPath(el)
        if (path && set.has(path)) {
            el.classList.add('builder-diff-changed')
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
