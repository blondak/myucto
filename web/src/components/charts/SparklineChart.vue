<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

/**
 * Mini sparkline (bar) — bez os, mřížek, legendy. Pro vložení do KPI tile pod částku.
 * Tooltip ukáže label + hodnotu.
 */
const props = defineProps<{
  labels: string[]
  values: number[]
  /** Volitelný formátovač hodnoty v tooltipu (např. peníze) */
  format?: (v: number) => string
  color?: string
  height?: number
  /**
   * Vykreslit čárkovanou linku průměru? Výchozí ano.
   * Vypni ji tam, kde karta nenabízí žádné srovnání (chybí loňská data) — bez
   * kontextu si uživatel linku vyloží jako „loňská úroveň" a to není pravda.
   */
  showAverage?: boolean
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

// Prázdno = žádná hodnota v řadě. V praxi volající kartu už dnes v tomto stavu
// sparkline vůbec nevykreslí (viz Dashboard.vue), ale komponenta ať je bezpečná i sama o sobě.
const isEmpty = computed(() => props.values.length === 0 || props.values.every(v => !v))

/** #RRGGBB → rgba(). Canvas neumí spolehlivě color-mix(), alfu si musíme spočítat. */
function withAlpha(color: string, alpha: number): string {
  const m = /^#([0-9a-f]{6})$/i.exec(color.trim())
  if (!m) return color
  const n = parseInt(m[1], 16)
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`
}

/**
 * Čárkovaná linka průměru přes sparkline.
 * Why: dvanáct sloupců bez měřítka říká jen „nahoru/dolů". Průměr je ta jediná
 * referenční hodnota, kterou uživatel při pohledu na obrat hledá — jestli je
 * aktuální měsíc nad, nebo pod ním.
 *
 * Kreslí se ale JEN když je „průměrný měsíc" vůbec smysluplný pojem: aspoň dvě
 * třetiny zobrazených měsíců musí mít data. U rozjeté firmy (pár měsíců s obratem,
 * zbytek nul) by linka byla průměrem převážně prázdné řady — vizuálně vypadá jako
 * reference k něčemu, ale ve skutečnosti jen dělí nuly a mate. Radši nic.
 */
const MIN_FILLED_RATIO = 2 / 3

const averageLine = {
  id: 'sparklineAverage',
  afterDatasetsDraw(c: Chart) {
    if (props.showAverage === false) return
    const values = (c.data.datasets[0]?.data ?? []) as number[]
    const usable = values.filter(v => typeof v === 'number' && isFinite(v))
    if (usable.length < 3) return
    const filled = usable.filter(v => v !== 0).length
    if (filled < Math.ceil(usable.length * MIN_FILLED_RATIO)) return
    const avg = usable.reduce((a, b) => a + b, 0) / usable.length
    if (avg <= 0) return
    const y = c.scales.y.getPixelForValue(avg)
    const { left, right } = c.chartArea
    const ctx = c.ctx
    ctx.save()
    ctx.setLineDash([3, 3])
    ctx.lineWidth = 1
    ctx.strokeStyle = withAlpha(colors.value.primary, 0.35)
    ctx.beginPath()
    ctx.moveTo(left, y)
    ctx.lineTo(right, y)
    ctx.stroke()
    ctx.restore()
  },
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const formatter = props.format ?? ((v: number) => String(v))
  const base = props.color ?? colors.value.primary
  const last = props.values.length - 1

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [{
        data: props.values,
        // Dvoutónově: uzavřené měsíce tlumeně, běžící měsíc plnou barvou.
        // Jednolitá řada stejně sytých sloupců nedávala vědět, který je „teď".
        backgroundColor: (ctx: { dataIndex: number }) =>
          ctx.dataIndex === last ? base : withAlpha(base, 0.42),
        hoverBackgroundColor: base,
        borderRadius: { topLeft: 3, topRight: 3, bottomLeft: 0, bottomRight: 0 },
        barPercentage: 0.82,
        categoryPercentage: 0.95,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          displayColors: false,
          callbacks: { label: (ctx) => ` ${ctx.label}: ${formatter(Number(ctx.parsed.y || 0))}` },
        },
      },
      scales: {
        x: { display: false },
        y: { display: false, beginAtZero: true },
      },
    },
    plugins: [averageLine],
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.color], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative overflow-hidden" :style="{ height: (height ?? 40) + 'px' }">
    <ChartEmptyState v-if="isEmpty" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
