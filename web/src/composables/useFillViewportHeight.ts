import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue'

/**
 * Nastaví seznamu max-height tak, aby jeho spodní okraj skončil nad spodní lištou
 * aplikace s mezerou `gap` px. Pevné `calc(100vh - N rem)` na různých šířkách okna
 * nesedí, protože se lišta filtrů zalamuje a mění výšku; výška se proto dopočítává
 * z pozice horního okraje seznamu v jeho posuvném kontejneru.
 */
export function useFillViewportHeight(
  target: Readonly<Ref<HTMLElement | null>>,
  options: { gap?: number; min?: number; enabled?: Ref<boolean> } = {},
): void {
  const gap = options.gap ?? 24
  const min = options.min ?? 240
  let timer: ReturnType<typeof setTimeout> | null = null
  let observer: ResizeObserver | null = null

  function scrollParent(el: HTMLElement): HTMLElement | null {
    let node = el.parentElement
    while (node && node !== document.body) {
      const overflowY = getComputedStyle(node).overflowY
      if ((overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight) return node
      node = node.parentElement
    }
    return null
  }

  function apply(): void {
    timer = null
    const el = target.value
    if (!el) return
    if (options.enabled && !options.enabled.value) {
      el.style.maxHeight = ''
      return
    }
    const parent = scrollParent(el)
    const rect = el.getBoundingClientRect()
    const scrolled = parent ? parent.scrollTop : window.scrollY
    const viewTop = parent ? parent.getBoundingClientRect().top : 0
    const footer = document.querySelector<HTMLElement>('footer.sticky')
    const bottomLimit = Math.min(
      parent ? parent.getBoundingClientRect().bottom : window.innerHeight,
      footer ? footer.getBoundingClientRect().top : window.innerHeight,
    )
    const topInView = rect.top + scrolled - viewTop
    const available = bottomLimit - viewTop - topInView - gap
    const height = Math.max(min, Math.floor(available))
    el.style.maxHeight = `${height}px`
    // Obsah pod seznamem (okraje, prázdný řádek „načíst další“) jinak posune celou
    // stránku o pár pixelů a vedle vnitřního posuvníku by se objevil druhý.
    const scroller = parent ?? document.documentElement
    const excess = scroller.scrollHeight - scroller.clientHeight
    if (!parent && excess > 0 && el.scrollHeight > el.clientHeight) {
      el.style.maxHeight = `${Math.max(min, height - excess)}px`
    }
  }

  function schedule(): void {
    if (timer) return
    timer = setTimeout(apply, 0)
  }

  onMounted(() => {
    window.addEventListener('resize', schedule)
    observer = new ResizeObserver(schedule)
    observer.observe(document.body)
    schedule()
  })
  watch(target, (el) => {
    if (el && observer) observer.observe(el.parentElement ?? el)
    schedule()
  })
  if (options.enabled) watch(options.enabled, schedule)
  onBeforeUnmount(() => {
    window.removeEventListener('resize', schedule)
    observer?.disconnect()
    if (timer) clearTimeout(timer)
  })
}
