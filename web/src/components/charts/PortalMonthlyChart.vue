<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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
import { formatCompactNumber, formatNumber } from '@/composables/useFormat'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

/** Portál (F6): grouped bary fakturace vs. náklady za 12 měsíců. */
const props = defineProps<{
  labels: string[]        // "2026-03"
  invoiced: number[]
  costs: number[]
  currency: string
  invoicedLabel: string
  costsLabel: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { locale } = useI18n()
const colors = useChartColors()

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [
        {
          label: props.invoicedLabel,
          data: props.invoiced,
          backgroundColor: colors.value.primary,
          borderRadius: 4,
        },
        {
          label: props.costsLabel,
          data: props.costs,
          backgroundColor: colors.value.primarySoft,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => ` ${ctx.dataset.label}: ${formatVal(ctx.parsed.y ?? 0)} ${props.currency}`,
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
watch(() => [props.labels, props.invoiced, props.costs, props.currency, locale.value], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-64 min-w-[560px]">
    <canvas ref="canvas"></canvas>
  </div>
</template>
