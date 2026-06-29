/**
 * Abbreviated GBP for chart axes and tooltips (£0, £25k, £1.2m). Defined here, not in
 * the options blob, because an ApexCharts formatter is a function and cannot travel
 * through the JSON the server renders. Presenters opt in with `moneyAxis: true`.
 */
function gbpAxis(value) {
    if (value === null || value === undefined || isNaN(value)) {
        return ''
    }
    const n = Number(value)
    const abs = Math.abs(n)
    if (abs >= 1e6) {
        return '£' + (n / 1e6).toFixed(abs >= 1e7 ? 0 : 1) + 'm'
    }
    if (abs >= 1e3) {
        return '£' + Math.round(n / 1e3) + 'k'
    }
    return '£' + Math.round(n)
}

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

            // moneyAxis is our flag, not an ApexCharts option: attach the £ formatter to the
            // y-axis labels and the tooltip, then drop the flag so ApexCharts never sees it.
            if (merged.moneyAxis === true && !Array.isArray(merged.yaxis)) {
                merged.yaxis = {
                    ...(merged.yaxis ?? {}),
                    labels: { ...((merged.yaxis ?? {}).labels ?? {}), formatter: gbpAxis },
                }
                merged.tooltip = {
                    ...(merged.tooltip ?? {}),
                    y: { ...((merged.tooltip ?? {}).y ?? {}), formatter: gbpAxis },
                }
            }
            delete merged.moneyAxis

            // ageByYear is our flag, not an ApexCharts option: relabel the calendar-year axis
            // as two lines — the year, then the people's ages that year (e.g. "2040" / "age
            // 82 / 84"). Returning an array makes ApexCharts stack the lines. Drop the flag.
            const ageByYear = merged.ageByYear
            delete merged.ageByYear
            if (ageByYear) {
                merged.xaxis = {
                    ...(merged.xaxis ?? {}),
                    labels: {
                        ...((merged.xaxis ?? {}).labels ?? {}),
                        formatter: (value) => {
                            const year = Math.round(Number(value))
                            const ages = ageByYear[year]
                            return ages ? [String(year), 'age ' + ages] : String(year)
                        },
                    },
                }
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
