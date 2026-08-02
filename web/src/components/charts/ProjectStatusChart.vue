<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import { useI18n } from 'vue-i18n'
import { useChartColors } from '@/composables/useTheme'
import { formatPercent } from '@/composables/useFormat'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

const { t, locale } = useI18n()
const props = defineProps<{ counts: Record<string, number> }>()
const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

/** Stavy zakázky sémanticky, ze sdílených chart tokenů (viz StatusDoughnutChart).
 *  `paused` mělo navíc odstín #E5A23B, který neodpovídal žádnému tokenu —
 *  vypadá to na starou hodnotu warning, ne na záměr. */
const palette = computed<Record<string, string>>(() => ({
  active: colors.value.success,
  paused: colors.value.warning,
  closed: colors.value.neutral,
}))

const slice = computed(() => {
  const order = ['active', 'paused', 'closed']
  const labelArr: string[] = []
  const valueArr: number[] = []
  const colorArr: string[] = []
  for (const k of order) {
    const v = props.counts?.[k] ?? 0
    if (v > 0) {
      labelArr.push(k === 'active' ? t('common.active') : k === 'paused' ? t('project.status_paused') : t('project.status_closed'))
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
    data: { labels: labelArr, datasets: [{ data: valueArr, backgroundColor: colorArr, borderWidth: 1, borderColor: colors.value.border }] },
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
              return ` ${ctx.label}: ${v} (${formatPercent(pct, 0)})`
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
  <div v-if="slice.labelArr.length === 0" class="text-sm text-neutral-400 text-center py-12">—</div>
  <div v-else class="relative h-56"><canvas ref="canvas"></canvas></div>
</template>
