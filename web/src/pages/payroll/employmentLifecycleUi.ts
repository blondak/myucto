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

export function todayIso(now = new Date()): string {
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
