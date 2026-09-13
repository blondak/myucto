import { toRaw } from 'vue'
import { codeFromName } from '@/utils/slugifyCode'

/**
 * Kód odvozený z názvu pro řádky editorů s polem (skupiny a volby virtuálního setu):
 * stejná pravidla jako {@link codeFromName}, jen malými písmeny (`[a-z0-9_]`),
 * protože takové kódy řádky už mají (`group_1`, `option_2`). Prázdný výsledek
 * (název bez písmen a číslic) nahradí `fallback`.
 */
export function rowCodeFromName(name: string, taken: Iterable<string>, fallback: string, maxLen = 50): string {
  const code = codeFromName(name, taken, maxLen).toLowerCase()
  return code !== '' ? code : fallback
}

/**
 * Sledování „kód se generuje z názvu" per řádek pole — obdoba useAutoSlug pro
 * editory, kde řádků je víc. Automaticky se kód odvozuje jen u řádků založených
 * v této relaci (`track`), dokud do kódu uživatel nesáhne; smazání kódu
 * generování zase zapne. Načtené řádky se nesledují, jejich kód se sám nemění.
 *
 * Klíčem je surový objekt řádku, takže funguje přes reaktivní proxy i po přesunu.
 */
export function createRowAutoCode<T extends { code: string }>(generate: (name: string, row: T) => string) {
  const auto = new WeakSet<object>()
  const key = (row: T) => toRaw(row) as object

  return {
    track(row: T): void { auto.add(key(row)) },
    isAuto(row: T): boolean { return auto.has(key(row)) },
    onName(row: T, name: string): void {
      if (auto.has(key(row))) row.code = generate(name, row)
    },
    onCode(row: T, code: string): void {
      row.code = code
      if (code.trim() === '') auto.add(key(row))
      else auto.delete(key(row))
    },
  }
}
