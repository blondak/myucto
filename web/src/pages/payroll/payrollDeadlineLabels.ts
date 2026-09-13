import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import type {
  PayrollDeadlineGroup,
  PayrollDeadlineItem,
  PayrollDeadlinePhase,
  PayrollDeadlineSource,
} from '@/api/payroll'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
import { formatMoneyMinor } from '@/composables/useFormat'

/**
 * Popisky a prokliky přehledu termínů. Sdílí je panel na přehledu mezd
 * i rozbalený seznam lidí jedné skupiny, aby o téže povinnosti neříkaly
 * dvě různé věci.
 */

/**
 * Pořadí je pořadím naléhavosti. `awaiting_result` a `action_required` posílá
 * posuzovač podání navíc; ztratit se nesmí, takže mají vlastní místo na konci.
 */
export const PHASE_ORDER: PayrollDeadlinePhase[] = [
  'overdue',
  'due_today',
  'due_soon',
  'action_required',
  'awaiting_result',
  'open',
]

export const PHASE_TONE: Record<PayrollDeadlinePhase, string> = {
  overdue: 'border-danger-500/40 bg-danger-50',
  due_today: 'border-warning-500/40 bg-warning-50',
  due_soon: 'border-warning-500/25 bg-warning-50/50',
  action_required: 'border-warning-500/25 bg-warning-50/50',
  awaiting_result: 'border-neutral-200 bg-neutral-50',
  open: 'border-neutral-200 bg-neutral-50',
}

export const PHASE_BADGE: Record<PayrollDeadlinePhase, string> = {
  overdue: 'bg-danger-50 text-danger-700',
  due_today: 'bg-warning-50 text-warning-800',
  due_soon: 'bg-warning-50 text-warning-700',
  action_required: 'bg-warning-50 text-warning-700',
  awaiting_result: 'bg-neutral-100 text-neutral-600',
  open: 'bg-neutral-100 text-neutral-600',
}

export function usePayrollDeadlineLabels() {
  const { t, te } = useI18n()
  const { submissionAgendaLabel } = usePayrollLabels()

  /**
   * Agendové kódy jdou přes sdílený slovník podání; ostatní prameny mají
   * vlastní číselníky a nepřeložený kód se ukáže tak, jak je.
   */
  function titleFor(source: PayrollDeadlineSource, title: string): string {
    if (source === 'submission' || source === 'sickness_case') {
      return submissionAgendaLabel(title)
    }
    const path = source === 'levy'
      ? `payroll.payments.kind.${title}`
      : source === 'registration_change'
        ? `payroll.people.registration.changes.duty_short.${title}`
        : source === 'tax_statement'
          ? `payroll.dashboard.deadlines.tax_statement.form.${title}`
          : `payroll.people.checklist.${title}`
    return te(path) ? t(path) : title
  }

  function itemTitle(item: PayrollDeadlineItem): string {
    return titleFor(item.source, item.title)
  }

  /** Roční vyúčtování nemá jméno, zato rok; `period` by lhal jako měsíc. */
  function itemSubject(item: PayrollDeadlineItem): string {
    if (item.source === 'tax_statement' && item.statement_year !== undefined) {
      return t('payroll.dashboard.deadlines.tax_statement.subject', {
        year: item.statement_year,
      })
    }
    return item.subject
  }

  /**
   * Kam se to řeší. Pojmenované routy místo serverové `path` — přežijí
   * přesun cesty a stránka lidí umí rovnou konečný tvar s dotazem.
   */
  function itemLink(item: PayrollDeadlineItem): RouteLocationRaw {
    if (item.source === 'levy') return { name: 'payroll-payments' }
    if ((item.source === 'checklist' || item.source === 'registration_change')
      && item.employee_id !== undefined
    ) {
      return { name: 'payroll-people', query: { person: String(item.employee_id) } }
    }
    if (item.source === 'checklist') return { name: 'payroll-people' }
    if (item.source === 'tax_statement') {
      return {
        name: 'payroll-dashboard',
        query: item.statement_year === undefined
          ? {}
          : { taxStatementYear: String(item.statement_year) },
        hash: '#payroll-tax-statement',
      }
    }
    if (item.source === 'sickness_case') {
      return { name: 'payroll-submissions-tab', params: { tab: 'sickness' } }
    }
    return { name: 'payroll-submissions' }
  }

  /** „Po termínu o 3 dny" / „Zbývají 3 dny" — znaménko drží backend. */
  function daysLabel(daysToDue: number): string {
    const days = Math.abs(daysToDue)
    if (daysToDue < 0) return t('payroll.dashboard.deadlines.overdue_by', days)
    if (daysToDue === 0) return t('payroll.dashboard.deadlines.due_today_label')
    return t('payroll.dashboard.deadlines.due_in', days)
  }

  function dueLabel(item: PayrollDeadlineItem): string {
    return daysLabel(item.days_to_due)
  }

  function amountLabel(item: PayrollDeadlineItem): string {
    if (item.remaining_minor === undefined) return ''
    return t('payroll.dashboard.deadlines.remaining', {
      amount: formatMoneyMinor(item.remaining_minor),
    })
  }

  /** U skupiny se ukazuje nejstarší prodlení; „nejstarší" jen když se liší. */
  function groupDueLabel(group: PayrollDeadlineGroup): string {
    const label = daysLabel(group.min_days_to_due)
    return group.min_days_to_due === group.max_days_to_due
      ? label
      : t('payroll.dashboard.deadlines.oldest', { label })
  }

  function groupCountLabel(group: PayrollDeadlineGroup): string {
    return group.per_person
      ? t('payroll.dashboard.deadlines.people_count', group.count)
      : t('payroll.dashboard.deadlines.items_count', group.count)
  }

  return {
    titleFor,
    itemTitle,
    itemSubject,
    itemLink,
    daysLabel,
    dueLabel,
    amountLabel,
    groupDueLabel,
    groupCountLabel,
  }
}
