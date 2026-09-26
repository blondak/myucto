import type { PayrollObstacleKindRule } from '@/api/payrollAbsences'

/**
 * Sazba náhrady mzdy u druhu překážky pro nabídku: pevná „100 %", nebo zákonné
 * minimum „nejméně 80 %" (zaměstnavatel smí přidat až na průměr).
 */
export function obstacleRateLabel(rule: PayrollObstacleKindRule): string {
  const minimum = formatPercent(rule.min_rate_basis_points)
  return rule.min_rate_basis_points === rule.max_rate_basis_points
    ? `${minimum} %`
    : `≥ ${minimum} %`
}

export function formatPercent(basisPoints: number): string {
  return (basisPoints / 100).toLocaleString('cs-CZ', { maximumFractionDigits: 2 })
}

/** Procento z formuláře na bazické body serveru; prázdné = tabulková sazba. */
export function percentToBasisPoints(percent: number | null | undefined): number | null {
  if (percent === null || percent === undefined || Number.isNaN(Number(percent))) return null
  return Math.round(Number(percent) * 100)
}

/**
 * Důvod je povinný, když se sazba liší od tabulkové nebo když ho druh vyžaduje
 * vždy (částečná nezaměstnanost podle § 209 ZP, jiná placená překážka).
 * Server pravidlo vynucuje stejně; formulář ho jen ukáže dřív.
 */
export function obstacleRateReasonRequired(
  rule: PayrollObstacleKindRule | null,
  percent: number | null | undefined,
): boolean {
  if (rule === null) return false
  if (rule.requires_reason) return true
  const basisPoints = percentToBasisPoints(percent)
  return basisPoints !== null && basisPoints !== rule.default_rate_basis_points
}
