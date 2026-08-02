const STORAGE_USED = 'myinvoice.tips.used'

/**
 * Označí tip za „už objevený" — volá se z místa, kde uživatel danou funkci
 * skutečně použil (stisk zkratky, otevření palety…), ne po čase.
 *
 * Why: tip, který visí v patičce i poté, co uživatel funkci dávno ovládá, je
 * jen šum. Tohle je jediný způsob, jak ho spolehlivě umlčet — časovač ani
 * počítadlo zobrazení nevědí, jestli se to uživatel opravdu naučil.
 */
export function markTipUsed(key: string): void {
  try {
    const raw = localStorage.getItem(STORAGE_USED)
    const used: string[] = raw ? JSON.parse(raw) : []
    if (!used.includes(key)) {
      used.push(key)
      localStorage.setItem(STORAGE_USED, JSON.stringify(used))
    }
  } catch {
    // Soukromý režim / plné úložiště — tip se ukáže znovu, nic horšího.
  }
}
