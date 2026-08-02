<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { useChartColors, withAlpha } from '@/composables/useTheme'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler)

/**
 * Kumulativní (nabíhající) km YTD vs. stejné období minulého roku — „souhrn" za rok.
 * Vstup = měsíční km (current[12] / previous[12]). U aktuálního roku se budoucí měsíce
 * nezobrazují (NaN), u minulých roků se vykreslí celých 12 měsíců.
 */
const props = defineProps<{
  current: number[]
  previous: number[]
  year: number
  prevYear: number
}>()

const MONTHS = ['Led', 'Úno', 'Bře', 'Dub', 'Kvě', 'Čvn', 'Čvc', 'Srp', 'Zář', 'Říj', 'Lis', 'Pro']

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

const series = computed(() => {
  const now = new Date()
  // Budoucí měsíce schovat jen když zobrazujeme aktuální rok.
  const cutoff = props.year === now.getFullYear() ? now.getMonth() + 1 : 12
  const thisCum: number[] = []
  const prevCum: number[] = []
  let tAcc = 0
  let pAcc = 0
  for (let m = 1; m <= 12; m++) {
    tAcc += props.current[m - 1] || 0
    pAcc += props.previous[m - 1] || 0
    thisCum.push(m > cutoff ? NaN : tAcc)
    prevCum.push(pAcc)
  }
  return { thisCum, prevCum }
})

// Prázdno = ani aktuální, ani loňský rok nemá jediný najetý km — obě kumulativní
// křivky by jinak byly ploché nuly.
const isEmpty = computed(() => props.current.every(v => !v) && props.previous.every(v => !v))

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const { thisCum, prevCum } = series.value
  const hasPrev = props.previous.some((v) => v > 0)

  chart = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels: MONTHS,
      datasets: [
        {
          label: String(props.year),
          data: thisCum,
          borderColor: colors.value.primary,
          backgroundColor: withAlpha(colors.value.primary, 0.15),
          borderWidth: 2.5,
          tension: 0.3,
          pointRadius: 3,
          pointBackgroundColor: colors.value.primary,
          fill: true,
          spanGaps: false,
        },
        ...(hasPrev ? [{
          label: String(props.prevYear),
          data: prevCum,
          borderColor: colors.value.primarySoft,
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [5, 4],
          tension: 0.3,
          pointRadius: 2,
          pointBackgroundColor: colors.value.primarySoft,
        }] : []),
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: hasPrev, position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(Number(ctx.parsed.y || 0))} km`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => `${Number(v) >= 1000 ? (Number(v) / 1000).toFixed(0) + 'k' : v}` },
          grid: { color: colors.value.grid },
        },
        x: { ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.current, props.previous, props.year, props.prevYear], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-56">
    <ChartEmptyState v-if="isEmpty" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
