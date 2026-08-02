import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné, takže je tu zrcadlíme ručně podle režimu.
 * POZOR: hodnoty musí odpovídat tokenům v styles/main.css (.dark scope) — při změně palety
 * srovnej i tady. Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
/**
 * Kategorická paleta pro grafy — rozlišení kategorií, ne sémantika.
 *
 * Předchozí verze měla deset slotů, ale sedm z nich byly odstíny téhož fialového
 * tónu (#3B2D83 … #E5E0F4) a zbylé dvě oranžové se od sebe skoro nelišily.
 * V koláčovém grafu s pěti klienty pak nešlo poznat, který výsek je který —
 * odstíny jedné barvy nefungují jako kategorie, protože nesou představu pořadí
 * („tmavší = víc"), která tu ale žádná není.
 *
 * Nová paleta má sedm ROZDÍLNÝCH tónů (indigo → jantar → tyrkys → korál →
 * modrá → zelená → neutrální) poskládaných tak, aby sousední sloty byly co
 * nejdál od sebe; barvy vychází z brandových tokenů, jen v sytosti použitelné
 * pro grafy. Poslední slot je záměrně neutrální šedá vyhrazená pro „Ostatní" —
 * ta nemá být identitou, ale zbytkem.
 *
 * V dark režimu jsou tóny světlejší, ne tmavší: proti tmavému plátnu rozhoduje
 * kontrast vůči podkladu, takže ztmavení segmentu by ho utopilo, i kdyby se tím
 * lépe oddělil od sousedů.
 *
 * POZOR: konzumenti nesmí paletu cyklovat přes modulo — nad sedm kategorií se
 * zbytek skládá do „Ostatní" (viz TopClientsPieChart / RevenueCategoryPieChart).
 */
const CHART_PALETTE_LIGHT = ['#5C45A0', '#E8A547', '#00919A', '#D45B5B', '#3F89C5', '#3B9665', '#A7A0BA']
const CHART_PALETTE_DARK = ['#9B87E8', '#E8A547', '#3FBFC7', '#E07A7A', '#5BA8E0', '#5FBF8E', '#A8A1BE']

/**
 * Sémantické barvy pro grafy, kde výsek NENÍ kategorie, ale stav (zaplaceno,
 * stornováno, upomenuto…). Patří sem, ne do jednotlivých komponent: dokud si
 * je každý graf psal natvrdo, používaly light hodnoty i v tmavém režimu a
 * segment „odesláno" zůstal tmavě fialový, zatímco všude jinde primary svítí.
 */
const chartColors = computed(() =>
  isDark.value
    ? {
        border: '#1E1B2B', tick: '#A8A1BE', grid: '#2C2840', tooltipBg: '#322C4A',
        primary: '#7C68C4', primarySoft: '#A99CD8', palette: CHART_PALETTE_DARK,
        success: '#5FBF8E', warning: '#E8A547', danger: '#E07A7A', neutral: '#6B6488',
      }
    : {
        border: '#FFFFFF', tick: '#5A5470', grid: '#E7E3EE', tooltipBg: '#15131D',
        primary: '#5C45A0', primarySoft: '#A99CD8', palette: CHART_PALETTE_LIGHT,
        success: '#4CAF7A', warning: '#E8A547', danger: '#D45B5B', neutral: '#A7A0BA',
      },
)

export function useChartColors() {
  return chartColors
}

/**
 * Kolik skutečných kategorií se vejde, než se zbytek složí do „Ostatní".
 * Paleta má sedm slotů a poslední je vyhrazený pro zbytek — proto šest.
 */
export const CHART_MAX_CATEGORIES = CHART_PALETTE_LIGHT.length - 1

/**
 * Barvy výseků koláče. Kategorie berou sloty popořadě, „Ostatní" (pokud je)
 * dostane vždy poslední, neutrální slot.
 *
 * Why: dřívější `palette[i % palette.length]` znamenalo, že při dostatku
 * kategorií dostaly dva různé výseky tutéž barvu — a v koláči, kde legenda
 * mapuje barvu na jméno, je to přímo zavádějící. Radši hrubší slučování než
 * dvě „stejné" kategorie.
 */
/**
 * #RRGGBB → rgba(). Chart.js kreslí do canvasu, který neumí spolehlivě
 * `color-mix()`, takže alfu musíme spočítat sami.
 */
export function withAlpha(color: string, alpha: number): string {
  const m = /^#([0-9a-f]{6})$/i.exec(color.trim())
  if (!m) return color
  const n = parseInt(m[1], 16)
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`
}

export function categoryColors(count: number, hasOther: boolean, palette: string[]): string[] {
  const lastSlot = palette.length - 1
  return Array.from({ length: count }, (_, i) =>
    hasOther && i === count - 1 ? palette[lastSlot] : palette[Math.min(i, lastSlot - 1)],
  )
}
