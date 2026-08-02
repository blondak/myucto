import { ref, onMounted, onBeforeUnmount, type Ref } from 'vue'

/**
 * Klávesové ovládání seznamů: j/k pohyb, Enter otevřít, x označit, Esc zrušit výběr.
 *
 * Why: účetní projede za den stovky řádků a mezi klávesnicí a myší přepíná u každého.
 * Vzor j/k/Enter je zavedený (mail klienti, issue trackery) a nevyžaduje modifikátory,
 * takže nekoliduje s prohlížečem.
 *
 * Zkratky se ignorují, když uživatel píše do pole nebo je otevřený dialog —
 * jinak by „x" v hledání označovalo řádky. Bez toho je klávesové ovládání
 * v aplikaci plné formulářů nepoužitelné.
 */

interface Options {
  /** Počet řádků; při změně se index ořízne, aby neukazoval mimo seznam. */
  count: () => number
  /** Otevřít aktuální řádek (Enter). */
  open: (index: number) => void
  /** Přepnout výběr aktuálního řádku (x). Volitelné. */
  toggle?: (index: number) => void
  /** Zrušit výběr (Esc). Volitelné. */
  clear?: () => void
}

export function useListKeyboard(opts: Options): { activeIndex: Ref<number> } {
  const activeIndex = ref(-1)

  function isTyping(): boolean {
    const el = document.activeElement as HTMLElement | null
    if (!el) return false
    if (el.isContentEditable) return true
    const tag = el.tagName
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
  }

  /** Dialogy si klávesnici řídí samy (Esc zavírá, šipky vybírají v seznamu). */
  function inDialog(): boolean {
    return document.querySelector('[role="dialog"], [aria-modal="true"]') !== null
  }

  function scrollActiveIntoView(): void {
    document.querySelector('[data-row-active="true"]')?.scrollIntoView({ block: 'nearest' })
  }

  function move(delta: number): void {
    const n = opts.count()
    if (n === 0) return
    const next = activeIndex.value < 0
      ? (delta > 0 ? 0 : n - 1)
      : Math.min(Math.max(activeIndex.value + delta, 0), n - 1)
    activeIndex.value = next
    requestAnimationFrame(scrollActiveIntoView)
  }

  function onKey(e: KeyboardEvent): void {
    if (e.ctrlKey || e.metaKey || e.altKey) return
    if (isTyping() || inDialog()) return

    switch (e.key) {
      case 'j':
      case 'ArrowDown':
        e.preventDefault()
        move(1)
        break
      case 'k':
      case 'ArrowUp':
        e.preventDefault()
        move(-1)
        break
      case 'Enter':
        if (activeIndex.value >= 0) {
          e.preventDefault()
          opts.open(activeIndex.value)
        }
        break
      case 'x':
        if (activeIndex.value >= 0 && opts.toggle) {
          e.preventDefault()
          opts.toggle(activeIndex.value)
        }
        break
      case 'Escape':
        if (opts.clear) {
          activeIndex.value = -1
          opts.clear()
        }
        break
    }
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

  return { activeIndex }
}
