<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatPercent } from '@/composables/useFormat'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

const props = defineProps<{ counts: Record<string, number> }>()
const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { t, locale } = useI18n()
const colors = useChartColors()

/**
 * Stavy dokladu jsou sémantika, ne kategorie — zelená = zaplaceno, červená =
 * stornováno. Barvy proto berou ze sdílených chart tokenů, aby se překlopily
 * s režimem; dřív tu byly natvrdo light hodnoty a v tmavém režimu segmenty
 * nesouhlasily se zbytkem aplikace.
 */
const palette = computed<Record<string, string>>(() => ({
  paid:      colors.value.success,
  sent:      colors.value.primary,
  reminded:  colors.value.warning,
  issued:    colors.value.primarySoft,
  cancelled: colors.value.danger,
  draft:     colors.value.neutral,
}))

function statusLabel(k: string): string {
  if (k === 'issued') return t('status.issued_not_sent')
  return t(`status.${k}`)
}

const slice = computed(() => {
  const order = ['paid', 'sent', 'reminded', 'issued', 'cancelled', 'draft']
  const labelArr: string[] = []
  const valueArr: number[] = []
  const colorArr: string[] = []
  for (const k of order) {
    const v = props.counts?.[k] ?? 0
    if (v > 0) {
      labelArr.push(statusLabel(k))
      valueArr.push(v)
      colorArr.push(palette.value[k] || colors.value.primarySoft)
    }
  }
  return { labelArr, valueArr, colorArr }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labelArr, valueArr, colorArr } = slice.value
  if (labelArr.length === 0) return
  const total = valueArr.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels: labelArr,
      datasets: [{ data: valueArr, backgroundColor: colorArr, borderWidth: 1, borderColor: colors.value.border }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? (v / total) * 100 : 0
              return ` ${ctx.label}: ${v} (${formatPercent(pct, 1)})`
            },
          },
        },
      },
      cutout: '55%',
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.counts, build, { deep: true })
watch(() => locale.value, build)
watch(colors, build)
</script>

<template>
  <div class="relative h-64">
    <ChartEmptyState v-if="slice.labelArr.length === 0" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
