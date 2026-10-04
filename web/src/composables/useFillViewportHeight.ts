import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue'

/**
 * Výška posuvného seznamu podle okna, ne podle pevného `calc(100vh - N rem)`: lišta
 * filtrů se na různých šířkách zalamuje a pevná hodnota pak nechá dole díru nebo
 * posune celou stránku.
 *
 * Seznam je vysoký jako celé okno mezi horní a spodní lištou aplikace. Nadpis a filtry
 * nad ním proto zůstanou vidět, jen dokud uživatel seznam neposune: jakmile začne
 * posouvat řádky, stránka odjede tak, aby seznam vyplnil obrazovku; návrat na první
 * řádek nadpis zase ukáže.
 */
export function useFillViewportHeight(
  target: Readonly<Ref<HTMLElement | null>>,
  options: { gap?: number; min?: number; enabled?: Ref<boolean> } = {},
): void {
  const gap = options.gap ?? 16
  const min = options.min ?? 240
  let timer: ReturnType<typeof setTimeout> | null = null
  let observer: ResizeObserver | null = null
  let bound: HTMLElement | null = null

  function barEdges(): { top: number; bottom: number } {
    const header = document.querySelector<HTMLElement>('header.sticky')
    const footer = document.querySelector<HTMLElement>('footer.sticky')
    return {
      top: header ? header.getBoundingClientRect().bottom : 0,
      bottom: footer ? footer.getBoundingClientRect().top : window.innerHeight,
    }
  }

  function pageScrolls(el: HTMLElement): boolean {
    let node = el.parentElement
    while (node && node !== document.body) {
      const overflowY = getComputedStyle(node).overflowY
      if (overflowY === 'auto' || overflowY === 'scroll') return false
      node = node.parentElement
    }
    return true
  }

  function listTopOnPage(el: HTMLElement): number {
    return el.getBoundingClientRect().top + window.scrollY
  }

  function apply(): void {
    timer = null
    const el = target.value
    if (!el) return
    if (options.enabled && !options.enabled.value) {
      el.style.maxHeight = ''
      return
    }
    const bars = barEdges()
    const height = pageScrolls(el)
      ? bars.bottom - bars.top - 2 * gap
      : bars.bottom - el.getBoundingClientRect().top - gap
    el.style.maxHeight = `${Math.max(min, Math.floor(height))}px`
  }

  function onListScroll(): void {
    const el = target.value
    if (!el || (options.enabled && !options.enabled.value) || !pageScrolls(el)) return
    const collapseTo = Math.max(0, Math.round(listTopOnPage(el) - barEdges().top - gap))
    if (el.scrollTop > 0 && window.scrollY + 1 < collapseTo) {
      window.scrollTo({ top: collapseTo, behavior: 'smooth' })
    } else if (el.scrollTop === 0 && window.scrollY > 0) {
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  function schedule(): void {
    if (timer) return
    timer = setTimeout(apply, 0)
  }

  function bind(el: HTMLElement | null): void {
    if (bound === el) return
    bound?.removeEventListener('scroll', onListScroll)
    bound = el
    bound?.addEventListener('scroll', onListScroll, { passive: true })
  }

  onMounted(() => {
    window.addEventListener('resize', schedule)
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(schedule)
      observer.observe(document.body)
    }
    bind(target.value)
    schedule()
  })
  watch(target, (el) => {
    bind(el)
    schedule()
  })
  if (options.enabled) watch(options.enabled, schedule)
  onBeforeUnmount(() => {
    window.removeEventListener('resize', schedule)
    observer?.disconnect()
    bind(null)
    if (timer) clearTimeout(timer)
  })
}
