import type { Directive } from 'vue'

/**
 * Vstup do pole (klik i Tab) označí celou hodnotu, napsané číslo ji rovnou přepíše.
 * Klik by výběr hned zrušil (mouseup posadí kurzor), proto se mouseup po kliku, který
 * pole teprve aktivoval, potlačí. Klik do už aktivního pole kurzor posadí normálně.
 */
export function selectOnFocus(el: HTMLInputElement): void {
  let focusingClick = false
  el.addEventListener('mousedown', () => { focusingClick = document.activeElement !== el })
  el.addEventListener('focus', () => el.select())
  el.addEventListener('mouseup', (e) => {
    if (focusingClick) e.preventDefault()
    focusingClick = false
  })
}

export const vSelectOnFocus: Directive<HTMLInputElement> = {
  mounted: selectOnFocus,
}
