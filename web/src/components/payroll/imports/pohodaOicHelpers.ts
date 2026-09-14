import type { PohodaOicResultStatus, PohodaOicRow, PohodaOicRowStatus } from '@/api/payrollImports'

export type PohodaOicApplyBlock = 'no_preview' | 'no_selection' | 'no_confirmation' | null

/** Řádky bez OIČ import přeskakuje, tabulka z nich ukáže jen počet. */
export function visibleOicRows(rows: PohodaOicRow[]): PohodaOicRow[] {
  return rows.filter(row => row.status !== 'no_oic')
}

export function selectableOicKeys(rows: PohodaOicRow[]): string[] {
  return rows.filter(row => row.selectable).map(row => row.key)
}

/** Výběr z předchozího náhledu smí obsahovat jen řádky, které jdou zapsat i teď. */
export function pruneOicSelection(selected: string[], rows: PohodaOicRow[]): string[] {
  const selectable = new Set(selectableOicKeys(rows))
  return selected.filter(key => selectable.has(key))
}

export function oicApplyBlock(state: {
  hasPreview: boolean
  selectedCount: number
  confirmed: boolean
}): PohodaOicApplyBlock {
  if (!state.hasPreview) return 'no_preview'
  if (state.selectedCount === 0) return 'no_selection'
  if (!state.confirmed) return 'no_confirmation'
  return null
}

export function oicStatusClass(status: PohodaOicRowStatus): string {
  switch (status) {
    case 'ready':
      return 'bg-success-50 text-success-700'
    case 'already_stored':
    case 'no_oic':
      return 'bg-neutral-100 text-neutral-600'
    case 'conflict':
    case 'duplicate':
    case 'ambiguous':
    case 'no_employment':
      return 'bg-warning-50 text-warning-700'
    default:
      return 'bg-danger-50 text-danger-600'
  }
}

export function oicResultClass(status: PohodaOicResultStatus): string {
  return {
    applied: 'bg-success-50 text-success-700',
    failed: 'bg-danger-50 text-danger-600',
    skipped: 'bg-neutral-100 text-neutral-600',
  }[status]
}
