import { ref, computed } from 'vue'
import { ensurePrefsLoaded, getNavOrder, patchNavOrder, resetNavOrder } from '@/composables/useUserPrefs'

/**
 * §10 — přeuspořádání sidebar menu (drag & drop). Aplikuje uložené `nav.order`
 * na navigační sekce/položky a poskytuje nativní HTML5 drag handlery.
 *
 * Sémantika (R9): klíče NEuvedené v uloženém pořadí se řadí na konec v defaultním
 * pořadí (nový upstream item se prostě objeví); neznámé klíče se ignorují.
 * Persistence běží přes useUserPrefs (debounced PUT 800 ms); reset = DELETE klíče.
 *
 * Přesun jen UVNITŘ bloku (položky) nebo mezi bloky (celé bloky) — položka mezi
 * bloky se NEpřesouvá. AppLayout drží jen aplikaci pořadí + drag atributy.
 */

export interface OrderableItem { to: string }
export interface OrderableSection { key: string; items: OrderableItem[] }

type DragRef =
  | { kind: 'section'; sectionKey: string }
  | { kind: 'item'; sectionKey: string; itemKey: string }
  | null

export function useNavOrder() {
  const order = getNavOrder()
  const dragging = ref<DragRef>(null)
  const overTarget = ref<DragRef>(null)

  // Poslední aplikované (zobrazené) pořadí — drag handlery z něj počítají nové.
  let cached: OrderableSection[] = []

  void ensurePrefsLoaded()

  /** Doplní účetní nastavení hned za Účetnictví do staršího uloženého pořadí. */
  function migrateAccountingTools(saved: string[]): string[] {
    const accountingAt = saved.indexOf('accounting')
    const toolsAt = saved.indexOf('accounting_tools')
    if (accountingAt === -1 || (toolsAt !== -1 && toolsAt !== saved.indexOf('taxes') + 1)) return saved
    const next = saved.filter(key => key !== 'accounting_tools')
    next.splice(next.indexOf('accounting') + 1, 0, 'accounting_tools')
    return next
  }

  /** Nový mzdový bounded context patří v uloženém pořadí bezprostředně za Daně. */
  function migratePayroll(saved: string[]): string[] {
    const taxesAt = saved.indexOf('taxes')
    const payrollAt = saved.indexOf('payroll')
    if (taxesAt === -1 || payrollAt === taxesAt + 1) return saved
    const next = saved.filter(key => key !== 'payroll')
    next.splice(next.indexOf('taxes') + 1, 0, 'payroll')
    return next
  }

  /** Vrátí sekce v uloženém pořadí; neznámé klíče appenduje v defaultním pořadí. */
  function orderedSections<S extends OrderableSection>(sections: S[]): S[] {
    const savedSections = migratePayroll(migrateAccountingTools(order.value.sections ?? []))
    const hiddenItems = new Set(order.value.hidden ?? [])
    const byKey = new Map(sections.map(s => [s.key, s]))

    const result: S[] = []
    for (const k of savedSections) {
      const s = byKey.get(k)
      if (s) { result.push(s); byKey.delete(k) }
    }
    for (const s of sections) {           // Map drží defaultní pořadí zbytku
      if (byKey.has(s.key)) { result.push(s); byKey.delete(s.key) }
    }

    const savedItems = order.value.items ?? {}
    const out = result.map((s) => {
      const wanted = savedItems[s.key]
      const visibleItems = s.items.filter(it => !hiddenItems.has(it.to))
      if (!wanted || wanted.length === 0) return { ...s, items: visibleItems } as S
      const itemByKey = new Map(visibleItems.map(it => [it.to, it]))
      const items: OrderableItem[] = []
      for (const ik of wanted) {
        const it = itemByKey.get(ik)
        if (it) { items.push(it); itemByKey.delete(ik) }
      }
      for (const it of visibleItems) {
        if (itemByKey.has(it.to)) { items.push(it); itemByKey.delete(it.to) }
      }
      return { ...s, items } as S
    })

    cached = out
    return out
  }

  function reorder(list: string[], fromKey: string, toKey: string): string[] {
    if (fromKey === toKey) return list
    const without = list.filter(k => k !== fromKey)
    const idx = without.indexOf(toKey)
    if (idx === -1) return list
    without.splice(idx, 0, fromKey)     // vlož PŘED cílovou položku
    return without
  }

  function clearDrag(): void { dragging.value = null; overTarget.value = null }

  // ── bloky (sekce) ──────────────────────────────────────────────────────────
  function onSectionDragStart(e: DragEvent, sectionKey: string): void {
    dragging.value = { kind: 'section', sectionKey }
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move'
      e.dataTransfer.setData('text/plain', sectionKey)
    }
  }

  function onSectionDragOver(e: DragEvent, sectionKey: string): void {
    const d = dragging.value
    if (d?.kind !== 'section' || d.sectionKey === sectionKey) return
    e.preventDefault()
    overTarget.value = { kind: 'section', sectionKey }
  }

  function onSectionDrop(e: DragEvent, sectionKey: string): void {
    const d = dragging.value
    if (d?.kind !== 'section') return
    e.preventDefault()
    const next = reorder(cached.map(s => s.key), d.sectionKey, sectionKey)
    patchNavOrder({ ...order.value, sections: next })
    clearDrag()
  }

  function isSectionDropTarget(sectionKey: string): boolean {
    return overTarget.value?.kind === 'section'
      && overTarget.value.sectionKey === sectionKey
      && dragging.value?.kind === 'section'
      && dragging.value.sectionKey !== sectionKey
  }

  // ── položky (uvnitř bloku) ─────────────────────────────────────────────────
  function onItemDragStart(e: DragEvent, sectionKey: string, itemKey: string): void {
    dragging.value = { kind: 'item', sectionKey, itemKey }
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move'
      e.dataTransfer.setData('text/plain', itemKey)
    }
    e.stopPropagation()
  }

  function onItemDragOver(e: DragEvent, sectionKey: string, itemKey: string): void {
    const d = dragging.value
    if (d?.kind !== 'item' || d.sectionKey !== sectionKey || d.itemKey === itemKey) return
    e.preventDefault()   // jen uvnitř stejného bloku
    overTarget.value = { kind: 'item', sectionKey, itemKey }
  }

  function onItemDrop(e: DragEvent, sectionKey: string, itemKey: string): void {
    const d = dragging.value
    if (d?.kind !== 'item' || d.sectionKey !== sectionKey) return
    e.preventDefault()
    e.stopPropagation()
    const currentItems = cached.find(s => s.key === sectionKey)?.items.map(it => it.to) ?? []
    const next = reorder(currentItems, d.itemKey, itemKey)
    patchNavOrder({ ...order.value, items: { ...(order.value.items ?? {}), [sectionKey]: next } })
    clearDrag()
  }

  function isItemDropTarget(sectionKey: string, itemKey: string): boolean {
    return overTarget.value?.kind === 'item'
      && overTarget.value.sectionKey === sectionKey
      && overTarget.value.itemKey === itemKey
      && dragging.value?.kind === 'item'
      && dragging.value.itemKey !== itemKey
  }

  function onDragEnd(): void { clearDrag() }

  function reset(): void { void resetNavOrder() }

  function hideItem(itemKey: string): void {
    if (order.value.hidden?.includes(itemKey)) return
    patchNavOrder({ ...order.value, hidden: [...(order.value.hidden ?? []), itemKey] })
  }

  function isHidden(itemKey: string): boolean {
    return order.value.hidden?.includes(itemKey) ?? false
  }

  return {
    orderedSections,
    isDragging: computed(() => dragging.value !== null),
    onSectionDragStart, onSectionDragOver, onSectionDrop, isSectionDropTarget,
    onItemDragStart, onItemDragOver, onItemDrop, isItemDropTarget,
    onDragEnd, reset, hideItem, isHidden,
  }
}
