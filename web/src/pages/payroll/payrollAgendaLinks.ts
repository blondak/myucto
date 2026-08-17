import type { LocationQuery, RouteLocationRaw } from 'vue-router'
import type { ActionIcon, ActionVariant } from '@/components/ui/buttonStyles'
import type { PayrollAgendaKey } from '@/api/payroll'
import type { PermissionKey } from '@/security/permissions'

/**
 * Rozcestník z karty zaměstnance do navazujících mzdových agend.
 *
 * Why: většina toho, co se u člověka pořizuje, nebydlí na jeho kartě — visí to
 * na pracovním vztahu v samostatné agendě. Karta o tom dosud mlčela, takže
 * uživatel odešel do menu, našel agendu a v ní zaměstnance hledal znovu.
 *
 * Katalog je JEDEN pro tlačítka i pro souhrn, aby se nemohly rozejít: pořadí,
 * popisek, ikona i cíl odkazu jsou tady, ne rozsypané po šabloně. Pořadí zrcadlí
 * `PayrollEmploymentAgendaSummaryRepository::AGENDAS` na backendu.
 *
 * `permission` musí sedět na `routePermissions` v `web/src/router/index.ts` —
 * kdyby se rozešly, tlačítko svítí a routa ho zahodí na homepage.
 */
export interface PayrollAgendaDefinition {
  key: PayrollAgendaKey
  /** Podle čeho se agenda zužuje: `employment` = vztah, `person` = osoba. */
  scope: 'employment' | 'person'
  permission: PermissionKey
  icon: ActionIcon
  variant: ActionVariant
  /** Cíl odkazu i s předvyplněným zúžením na daného člověka. */
  to: (employmentId: number, employeeId: number) => RouteLocationRaw
}

export const payrollAgendas: readonly PayrollAgendaDefinition[] = [
  {
    key: 'time',
    scope: 'employment',
    permission: 'payroll',
    icon: 'clipboardCheck',
    variant: 'primary',
    to: employmentId => ({ name: 'payroll-time', query: { employment: String(employmentId) } }),
  },
  {
    key: 'absences',
    scope: 'employment',
    permission: 'payroll',
    icon: 'calendar',
    variant: 'success',
    to: employmentId => ({ name: 'payroll-absences', query: { employment: String(employmentId) } }),
  },
  {
    key: 'quick_inputs',
    scope: 'employment',
    permission: 'payroll',
    icon: 'coin',
    variant: 'primary',
    to: employmentId => ({
      name: 'payroll-quick-inputs',
      query: { employment: String(employmentId) },
    }),
  },
  {
    key: 'travel',
    scope: 'employment',
    permission: 'payroll',
    icon: 'swap',
    variant: 'primary',
    to: employmentId => ({ name: 'payroll-travel', query: { employment: String(employmentId) } }),
  },
  {
    key: 'components',
    scope: 'employment',
    permission: 'payroll',
    icon: 'cycle',
    variant: 'primary',
    to: employmentId => ({
      name: 'payroll-components',
      query: { employment: String(employmentId), tab: 'recurring' },
    }),
  },
  {
    // Průměrný výdělek nemá vlastní routu — je to záložka nepřítomností, protože
    // se z něj počítá náhrada mzdy. Odkaz proto míří tam a rovnou přepne záložku.
    key: 'average_earnings',
    scope: 'employment',
    permission: 'payroll',
    icon: 'chart',
    variant: 'neutral',
    to: employmentId => ({
      name: 'payroll-absences',
      query: { employment: String(employmentId), tab: 'averages' },
    }),
  },
  {
    key: 'deduction_agreements',
    scope: 'person',
    permission: 'payroll',
    icon: 'tag',
    variant: 'warning',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-deduction-agreements',
      query: { person: String(employeeId) },
    }),
  },
  {
    key: 'enforcement',
    scope: 'person',
    permission: 'payroll.enforcement',
    icon: 'lock',
    variant: 'warning',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-enforcement',
      query: { person: String(employeeId) },
    }),
  },
  {
    key: 'documents',
    scope: 'person',
    permission: 'payroll.documents',
    icon: 'doc',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-documents',
      query: { person: String(employeeId), tab: 'annual' },
    }),
  },
  {
    key: 'annual_settlement',
    scope: 'person',
    permission: 'payroll.documents',
    icon: 'archive',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-annual-settlement',
      query: { person: String(employeeId) },
    }),
  },
]

/**
 * Jedna hodnota z query stringu. Vue Router u opakovaného klíče vrací pole —
 * bez tohohle by `?employment=1&employment=2` skončilo na `NaN` a stránka by
 * mlčky ukázala neselektovaný seznam.
 */
export function payrollQueryValue(query: LocationQuery, name: string): string | null {
  const value = query[name]
  const raw = Array.isArray(value) ? value[0] : value
  return typeof raw === 'string' && raw !== '' ? raw : null
}

/** Kladné celé id z query stringu; cokoli jiného je slepý odkaz, ne chyba. */
export function payrollQueryId(query: LocationQuery, name: string): number | null {
  const raw = payrollQueryValue(query, name)
  if (raw === null) return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}
