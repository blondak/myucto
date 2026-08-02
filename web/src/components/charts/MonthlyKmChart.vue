<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import {
  Chart,
  BarController,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

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

// Prázdno = ani aktuální, ani loňský rok nemá jediný najetý km v žádném měsíci.
const isEmpty = computed(() => props.current.every(v => !v) && props.previous.every(v => !v))

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const hasPrev = props.previous.some((v) => v > 0)

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: MONTHS,
      datasets: [
        ...(hasPrev ? [{
          label: String(props.prevYear),
          // `grid` je barva mřížky — loňský rok v ní vypadal jako pozadí, ne jako
          // datová řada. Všude jinde nese předchozí období `primarySoft`.
          data: props.previous,
          backgroundColor: colors.value.primarySoft,
          borderRadius: 4,
        }] : []),
        {
          label: String(props.year),
          data: props.current,
          backgroundColor: colors.value.primary,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        // Legenda dole jako u všech ostatních grafů — tohle byl jediný, co ji měl nahoře.
        legend: { display: hasPrev, position: 'bottom', labels: { color: colors.value.tick, font: { size: 11 }, boxWidth: 12 } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(ctx.parsed.y ?? 0)} km`,
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
