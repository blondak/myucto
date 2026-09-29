<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, type ChartConfiguration } from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatCompactNumber, formatNumber } from '@/composables/useFormat'

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

export interface GroupChartSeries { label: string; values: (number | null)[]; tone: 'primary' | 'success' | 'warning' | 'danger' | 'neutral' }
const props = withDefaults(defineProps<{
  labels: string[]; series: GroupChartSeries[]; currency?: string; title: string
  type?: 'bar' | 'line'; horizontal?: boolean
}>(), { type: 'bar', horizontal: false, currency: '' })
const { t, locale } = useI18n()
const colors = useChartColors()
const canvas = ref<HTMLCanvasElement | null>(null)
const hasData = computed(() => props.labels.length > 0 && props.series.some(series => series.values.some(value => value !== null)))
const height = computed(() => props.horizontal ? Math.max(280, props.labels.length * 46) : 300)
let chart: Chart | null = null

async function build() {
  await nextTick()
  chart?.destroy()
  chart = null
  if (!canvas.value || !hasData.value) return
  const config: ChartConfiguration<'bar' | 'line', (number | null)[], string> = {
    type: props.type,
    data: {
      labels: props.labels,
      datasets: props.series.map(series => ({
        label: series.label, data: series.values,
        backgroundColor: colors.value[series.tone], borderColor: colors.value[series.tone],
        borderWidth: props.type === 'line' ? 2 : 0, borderRadius: 3, pointRadius: 3, spanGaps: false,
      })),
    },
    options: {
      responsive: true, maintainAspectRatio: false, indexAxis: props.horizontal ? 'y' : 'x',
      plugins: {
        legend: { position: 'bottom', labels: { color: colors.value.tick, usePointStyle: true, boxWidth: 9 } },
        tooltip: { backgroundColor: colors.value.tooltipBg, callbacks: {
          label: context => {
            const value = props.horizontal ? context.parsed.x : context.parsed.y
            return `${context.dataset.label}: ${value === null ? '-' : formatNumber(value)} ${props.currency}`
          },
        } },
      },
      scales: {
        x: { ticks: { color: colors.value.tick, ...(props.horizontal ? { callback: value => formatCompactNumber(Number(value)) } : {}) }, grid: { color: colors.value.grid } },
        y: { beginAtZero: true, ticks: { color: colors.value.tick, ...(!props.horizontal ? { callback: value => formatCompactNumber(Number(value)) } : {}) }, grid: { color: colors.value.grid } },
      },
    },
  }
  chart = new Chart(canvas.value, config)
}
onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.series, props.currency, props.type, props.horizontal, locale.value, colors.value], build, { deep: true })
</script>

<template>
  <div v-if="hasData" class="relative w-full" :style="{ height: `${height}px` }">
    <canvas ref="canvas" role="img" :aria-label="title"></canvas>
  </div>
  <p v-else class="py-16 text-center text-sm text-neutral-500">{{ t('group_stats.no_data') }}</p>
</template>
