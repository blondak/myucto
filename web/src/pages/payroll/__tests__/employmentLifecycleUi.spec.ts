import { describe, expect, it } from 'vitest'
import { todayIso, transitionPresentation } from '@/pages/payroll/employmentLifecycleUi'

describe('employmentLifecycleUi', () => {
  it('ke stavovým přechodům přiřadí jednu hlavní a bezpečně skryté destruktivní akce', () => {
    expect(transitionPresentation(['preregistered', 'no_show'])).toEqual([
      {
        target: 'preregistered',
        variant: 'primary',
        tier: 'primary',
        icon: 'check',
      },
      {
        target: 'no_show',
        variant: 'danger',
        tier: 'advanced',
        icon: 'x',
      },
    ])
  })

  it('datum pro mutaci skládá v místním kalendářním dni bez UTC posunu', () => {
    expect(todayIso(new Date(2026, 7, 3, 23, 59))).toBe('2026-08-03')
  })
})
