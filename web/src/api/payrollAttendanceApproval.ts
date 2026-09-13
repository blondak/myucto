import type { RouteLocationRaw } from 'vue-router'
import { api } from './client'
import type { AttendanceApplyPayload, AttendanceApplyResult } from './payrollImports'
import type { ActionIcon } from '@/components/ui/buttonStyles'

/**
 * Souhrn docházky do pracovních měsíců a hromadné schválení čistých měsíců
 * z dávky importu docházky.
 *
 * Výjimka i varování nesou vztah a jméno, aby účetní u pěti set lidí věděla,
 * u koho a proč měsíc zůstal otevřený, a z výsledku se dostala rovnou k nápravě.
 */
export interface AttendanceTimeIssue {
  employment_id: number
  name: string
  employment_code: string
  code: string
  message: string
}

/** Výsledek `PayrollTimeImportApprovalService` (import s volbou schválení i dodatečné schválení dávky). */
export interface AttendanceTimeApproval {
  approved: number
  already_approved: number
  written: number
  replayed: number
  exceptions: AttendanceTimeIssue[]
  warnings: AttendanceTimeIssue[]
}

/** Výsledek samotného zápisu souhrnů (`PayrollTimeImportSummaryWriter::writeFromBatch`), bez jmen. */
export interface AttendanceTimeSummaryResult {
  written: number
  replayed: number
  calendars_created?: number
  exceptions: { employment_id: number; message: string }[]
  warnings: { employment_id: number; code: string; message: string }[]
}

export interface AttendanceApplyWithTimePayload extends AttendanceApplyPayload {
  write_time_summary: boolean
  approve_clean_time_months: boolean
}

export type AttendanceApplyWithTimeResult = Omit<AttendanceApplyResult, 'inputs'> & {
  inputs: AttendanceApplyResult['inputs'] & {
    /** Koncepty vstupů, jejichž hodnotu import aktualizoval. */
    updated?: number
    /** Hodnoty ručně přepsané v rychlém měsíčním vstupu; import je záměrně nepřepsal. */
    overridden?: number
  }
  time_summary?: AttendanceTimeSummaryResult | null
  time_approval?: AttendanceTimeApproval | null
}

export const payrollAttendanceApprovalApi = {
  applyAttendance: (payload: AttendanceApplyWithTimePayload) =>
    api.post<AttendanceApplyWithTimeResult>('/payroll/imports/attendance/apply', payload)
      .then(response => response.data),
  /** Dodatečné schválení čistých měsíců z už použité dávky (právo `payroll.approve`). */
  approveCleanTimeMonths: (batchId: number) =>
    api.post<{ time_approval: AttendanceTimeApproval }>(`/payroll/time/imports/attendance/${batchId}/apply`)
      .then(response => response.data.time_approval),
}

export interface AttendanceTimeIssueGroup {
  code: string
  /** Text jednou za skupinu; `null`, když se hlášky osob liší a patří ke každé osobě. */
  message: string | null
  items: AttendanceTimeIssue[]
}

/** Výjimky se stejným kódem pod sebou; pořadí skupin podle prvního výskytu. */
export function groupTimeIssues(issues: readonly AttendanceTimeIssue[]): AttendanceTimeIssueGroup[] {
  const groups = new Map<string, AttendanceTimeIssue[]>()
  for (const issue of issues) {
    const list = groups.get(issue.code)
    if (list) list.push(issue)
    else groups.set(issue.code, [issue])
  }
  return [...groups.entries()].map(([code, items]) => ({
    code,
    message: items.every(item => item.message === items[0].message) ? items[0].message : null,
    items,
  }))
}

/**
 * Zápis souhrnů bez schválení nevrací jména; doplní se z náhledu importu.
 * Výjimka zápisu nemá kód — je to vždy nezapsaný souhrn.
 */
export function summaryTimeIssues(
  summary: AttendanceTimeSummaryResult,
  labels: ReadonlyMap<number, { name: string; code: string }>,
): { exceptions: AttendanceTimeIssue[]; warnings: AttendanceTimeIssue[] } {
  const person = (employmentId: number) => ({
    employment_id: employmentId,
    name: labels.get(employmentId)?.name ?? '',
    employment_code: labels.get(employmentId)?.code ?? '',
  })
  return {
    exceptions: summary.exceptions.map(item => ({ ...person(item.employment_id), code: 'summary_not_written', message: item.message })),
    warnings: summary.warnings.map(item => ({ ...person(item.employment_id), code: item.code, message: item.message })),
  }
}

export interface AttendanceTimeFixLink {
  /** Klíč popisku v `payroll_imports.attendance_time.fix`. */
  label: 'terms' | 'absences' | 'time'
  icon: ActionIcon
  to: RouteLocationRaw
}

/**
 * Kam s výjimkou. Pracovní měsíc je vždy v Docházce (zúžené na vztah a období);
 * chybějící úvazek se opravuje na kartě vztahu, hodiny nepřítomnosti bez dat
 * v Absencích — tam míří hláška backendu, tak tam vede i odkaz.
 */
export function timeIssueFixLinks(code: string, employmentId: number, period: string): AttendanceTimeFixLink[] {
  const time: AttendanceTimeFixLink = {
    label: 'time',
    icon: 'clipboardCheck',
    to: { name: 'payroll-time', query: { employment: String(employmentId), period } },
  }
  if (code === 'weekly_hours_missing') {
    return [{
      label: 'terms',
      icon: 'user',
      to: { name: 'payroll-people', query: { employment: String(employmentId), panel: 'employment_terms' } },
    }, time]
  }
  if (code === 'absence_hours_without_dates') {
    return [{
      label: 'absences',
      icon: 'calendar',
      to: { name: 'payroll-absences', query: { employment: String(employmentId) } },
    }, time]
  }
  return [time]
}
