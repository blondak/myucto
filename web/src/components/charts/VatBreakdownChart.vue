<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatNumber, formatPercent } from '@/composables/useFormat'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

/**
 * Rozpad obratu (base bez DPH) podle sazby DPH. Vstup je již agregován per měna —
 * komponenta zobrazí jednu měnu (předanou v `currency` prop).
 */
const props = defineProps<{
  items: Array<{ label: string; base: number; currency: string }>
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { locale } = useI18n()
const colors = useChartColors()

/**
 * Sazba DPH je identita kategorie, ne stav — mapujeme ji proto na sloty
 * KATEGORIÁLNÍ palety, ne na sémantické barvy. Základní 21% sazba byla dřív
 * červená (danger token) a v grafu vypadala jako varování, přestože je to ta
 * nejběžnější sazba na světě.
 *
 * Pevné přiřazení slotu k sazbě (ne pořadí ve výsledku) je záměr: účetní pozná
 * „svou" sazbu podle barvy i mezi obdobími, kde se skladba sazeb liší.
 * Nulová sazba dostává neutrální slot — je to nepřítomnost daně, ne kategorie.
 */
const palette = computed<Record<string, string>>(() => {
  const p = colors.value.palette
  return {
    '21 %': p[0],
    '12 %': p[1],
    '15 %': p[2],
    '10 %': p[4],
    'RC (reverse charge)': p[3],
    '0 %':  p[p.length - 1],
  }
})

const filtered = computed(() => props.items.filter(i => i.currency === props.currency && i.base !== 0))

function formatVal(n: number): string {
  return formatNumber(n, { maximumFractionDigits: 0 })
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const items = filtered.value
  if (items.length === 0) return

  const total = items.reduce((s, i) => s + i.base, 0)
  const labels = items.map(i => i.label)
  const data = items.map(i => i.base)
  const segmentColors = items.map(i => palette.value[i.label] ?? colors.value.primary)

  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: segmentColors, borderWidth: 1, borderColor: colors.value.border }] },
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
              return ` ${ctx.label}: ${formatVal(v)} ${props.currency} (${formatPercent(pct, 1)})`
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
watch(() => [props.items, props.currency, locale.value], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-64">
    <ChartEmptyState v-if="!filtered.length" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
