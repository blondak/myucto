import { computed, ref } from 'vue'
import type { BankTransactionSortKey } from '@/api/bank'

export interface BankTransactionSortState {
  key: BankTransactionSortKey
  dir: 'asc' | 'desc'
}

/**
 * Řazení seznamu bankovních pohybů podle sloupce (detail výpisu, „K zaúčtování",
 * „Všechny pohyby"). Seznam je stránkovaný na serveru, takže stav jen skládá
 * query `sort`/`direction` a řadí backend (whitelist BankTransactionSort).
 *
 * Klik na hlavičku cykluje vzestupně → sestupně → výchozí pořadí stránky, stejně
 * jako `useTablePrefs.toggleSort`. Mobilní karty hlavičku nemají, proto
 * `selectValue` pro jeden výběr „sloupec a směr".
 */
export function useBankTransactionSort(keys: readonly BankTransactionSortKey[]) {
  const sort = ref<BankTransactionSortState | null>(null)

  function toggle(key: string): void {
    if (!keys.includes(key as BankTransactionSortKey)) return
    const cur = sort.value
    const k = key as BankTransactionSortKey
    if (!cur || cur.key !== k) sort.value = { key: k, dir: 'asc' }
    else if (cur.dir === 'asc') sort.value = { key: k, dir: 'desc' }
    else sort.value = null
  }

  /** Query parametry pro API; prázdné = výchozí pořadí serveru. */
  const params = computed<{ sort?: BankTransactionSortKey; direction?: 'asc' | 'desc' }>(() =>
    sort.value ? { sort: sort.value.key, direction: sort.value.dir } : {})

  /** `key:dir`, '' = výchozí pořadí — hodnota pro <select> na mobilu. */
  const selectValue = computed<string>({
    get: () => (sort.value ? `${sort.value.key}:${sort.value.dir}` : ''),
    set: (v: string) => {
      const [key, dir] = v.split(':')
      sort.value = key && keys.includes(key as BankTransactionSortKey) && (dir === 'asc' || dir === 'desc')
        ? { key: key as BankTransactionSortKey, dir }
        : null
    },
  })

  return { sort, toggle, params, selectValue, keys }
}
