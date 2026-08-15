import type { PayrollEmploymentStatus } from '@/api/payroll'

export interface EmploymentTransitionPresentation {
  target: PayrollEmploymentStatus
  variant: 'primary' | 'success' | 'warning' | 'danger' | 'neutral'
  tier: 'primary' | 'secondary' | 'overflow' | 'advanced'
  icon: 'check' | 'play' | 'pause' | 'archive' | 'x'
}

const PRESENTATION: Record<PayrollEmploymentStatus, Omit<EmploymentTransitionPresentation, 'target'>> = {
  planned: { variant: 'neutral', tier: 'secondary', icon: 'play' },
  preregistered: { variant: 'primary', tier: 'primary', icon: 'check' },
  active: { variant: 'success', tier: 'primary', icon: 'play' },
  suspended: { variant: 'warning', tier: 'secondary', icon: 'pause' },
  ended: { variant: 'danger', tier: 'overflow', icon: 'x' },
  archived: { variant: 'neutral', tier: 'advanced', icon: 'archive' },
  no_show: { variant: 'danger', tier: 'advanced', icon: 'x' },
}

export function transitionPresentation(
  allowed: PayrollEmploymentStatus[],
): EmploymentTransitionPresentation[] {
  return allowed.map(target => ({ target, ...PRESENTATION[target] }))
}

/**
 * Kód vztahu se ukazuje jen tehdy, když něco znamená.
 *
 * Vztahy převzaté z původní evidence dostaly při materializaci kód `legacy`
 * (migrace 1188, `is_legacy_projection = 1`). Je to interní značka, ne údaj
 * zaměstnavatele — na kartě člověka vypadá jako název pracovního poměru a mate.
 * Kdo si kód vyplní sám, uvidí ho beze změny.
 *
 * Sdílené místo záměrně: kartu zaměstnance i přehled karet to musí zobrazovat
 * stejně, jinak se to potřetí rozejde.
 */
export function employmentCodeLabel(code: string | null | undefined): string {
  const trimmed = (code ?? '').trim()
  return trimmed === '' || trimmed.toLowerCase() === 'legacy' ? '' : trimmed
}

/**
 * Poznámka k události časové osy. Technické poznámky vložené migrací nejsou
 * text pro uživatele — „Legacy projekce" (migrace 1196) je značka převodu,
 * ne informace. Databázi neupravujeme, aby se neztratila stopa; filtrujeme
 * až při zobrazení.
 */
const INTERNAL_EVENT_NOTES = new Set(['legacy projekce'])

export function employmentEventNote(note: string | null | undefined): string {
  const trimmed = (note ?? '').trim()
  return INTERNAL_EVENT_NOTES.has(trimmed.toLowerCase()) ? '' : trimmed
}

export function todayIso(now = new Date()): string {
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
