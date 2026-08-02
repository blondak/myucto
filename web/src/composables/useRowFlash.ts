import { ref } from 'vue'

/**
 * Krátké podbarvení řádků, kterých se právě týkala akce.
 *
 * Why: po hromadné akci nebo po návratu z editoru se seznam překreslí a vypadá
 * úplně stejně jako předtím. Uživatel má toast („3 faktury označeny"), ale nevidí
 * KTERÉ řádky to byly — u dvaceti řádků na obrazovce musí dohledávat očima.
 * Krátký záblesk na dotčených řádcích tu vazbu udělá za něj.
 *
 * Stav je modul-level singleton, protože se zapisuje na jedné obrazovce
 * (editor uloží) a čte na jiné (seznam se vykreslí po návratu).
 */

interface FlashMark {
  kind: string
  ids: number[]
  at: number
}

/**
 * Jak dlouho je značka platná. Bez expirace by se řádek podbarvil i za hodinu,
 * až se uživatel na seznam náhodou vrátí — a záblesk by nic neznamenal.
 */
const MAX_AGE_MS = 30_000

const mark = ref<FlashMark | null>(null)

/** Zapíše, čeho se akce týkala. `kind` odlišuje agendy (invoice, purchase_invoice…). */
export function markRowsTouched(kind: string, ids: number[]): void {
  if (ids.length === 0) return
  mark.value = { kind, ids, at: Date.now() }
}

/**
 * Vrátí ID k probliknutí pro danou agendu a značku ZAHODÍ — záblesk je
 * jednorázový, při druhém vykreslení téhož seznamu už se opakovat nemá.
 */
export function consumeFlashedRows(kind: string): Set<number> {
  const m = mark.value
  if (!m || m.kind !== kind || Date.now() - m.at > MAX_AGE_MS) return new Set()
  mark.value = null
  return new Set(m.ids)
}
