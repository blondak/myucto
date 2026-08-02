<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Chart,
  BarController,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatCompactNumber, formatNumber } from '@/composables/useFormat'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  labels: string[]   // např. "2024-03"
  values: number[]
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { locale } = useI18n()
const colors = useChartColors()

// Prázdno = žádný měsíc s nenulovým obratem.
const isEmpty = computed(() => props.values.length === 0 || props.values.every(v => !v))

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [
        {
          data: props.values,
          backgroundColor: colors.value.primary,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${formatVal(ctx.parsed.y ?? 0)} ${props.currency}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        x: {
          ticks: { color: colors.value.tick, font: { size: 10 }, maxRotation: 45, minRotation: 45 },
          grid: { display: false },
        },
      },
    },
  })
}

function formatVal(n: number): string {
  return formatNumber(n, { maximumFractionDigits: 0 })
}

function formatTick(n: number): string {
  return formatCompactNumber(n)
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.currency, locale.value], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-56">
    <ChartEmptyState v-if="isEmpty" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
