/**
 * A small Alpine wrapper around ApexCharts.
 *
 * The chart is never the source of truth: every figure it plots is also rendered as
 * text and inside a visually-hidden <table> next to it (see the Blade partials), so
 * the canvas is a progressive enhancement. This component just mounts the chart from
 * a JSON options blob and respects the user's reduced-motion preference.
 *
 * Usage in Blade:
 *   <div x-data="chart(@js($options))" x-ref="canvas" wire:ignore></div>
 */
function chart(options) {
    return {
        instance: null,

        init() {
            if (typeof window.ApexCharts === 'undefined') {
                return
            }

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            const merged = {
                ...options,
                chart: {
                    ...(options.chart ?? {}),
                    animations: { enabled: !reduceMotion },
                    fontFamily: 'inherit',
                },
            }

            // The chart is a progressive enhancement: every figure it plots is also in the
            // accessible table beside it. So if a chart ever fails to render, fail loud in the
            // console and degrade gracefully rather than leaving a silent blank box.
            try {
                this.instance = new window.ApexCharts(this.$el, merged)
                this.instance.render()
            } catch (error) {
                console.error('Chart failed to render; the figures remain in the table below.', error)
                this.$el.innerHTML =
                    '<p class="text-sm text-gray-500">This chart could not be drawn. The figures are in the table below.</p>'
                return
            }

            // Tear the chart down cleanly when Livewire removes the element, so a
            // re-render does not leak detached canvases.
            this.$el.addEventListener('livewire:navigating', () => this.destroy())
        },

        destroy() {
            if (this.instance) {
                this.instance.destroy()
                this.instance = null
            }
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chart', chart)
})
