import ApexCharts from 'apexcharts'
import './charts'
import './toc'

// Make ApexCharts available to the Alpine chart component registered in charts.js.
// Livewire 4 bundles Alpine, so we only register our own pieces here.
window.ApexCharts = ApexCharts
