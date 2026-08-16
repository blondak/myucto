import { describe, expect, it } from 'vitest'
import {
  employmentDiffFields,
  employmentDiffValue,
  todayIso,
  transitionPresentation,
} from '@/pages/payroll/employmentLifecycleUi'

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

  describe('employmentDiffValue', () => {
    it('stav a typ vztahu překládá, místo aby vypsal databázovou hodnotu', () => {
      expect(employmentDiffValue('status', 'ended'))
        .toEqual({ kind: 'key', key: 'payroll.people.employment_status.ended' })
      expect(employmentDiffValue('relation_type', 'partner_dependent'))
        .toEqual({ kind: 'key', key: 'payroll.people.relations.partner_dependent' })
    })

    it('u položky checklistu překládá stav splnění, ne název položky', () => {
      expect(employmentDiffValue('social_jmhz_registration', 'completed'))
        .toEqual({ kind: 'key', key: 'payroll.people.checklist_status.completed' })
    })

    it('pojištění, daňový režim a JMHZ příznaky mají vlastní slovníky', () => {
      expect(employmentDiffValue('health_insurance_participation', 'excluded'))
        .toEqual({ kind: 'key', key: 'payroll.people.insurance_mode.excluded' })
      expect(employmentDiffValue('tax_regime', 'withholding'))
        .toEqual({ kind: 'key', key: 'payroll.people.tax_regime.withholding' })
      expect(employmentDiffValue('jmhz_temporary_assignment_status', 'yes'))
        .toEqual({ kind: 'key', key: 'payroll.people.jmhz_evidence.state.yes' })
    })

    it('booleany čte i v podobě, v jaké je vrací databáze', () => {
      expect(employmentDiffValue('is_primary', 1)).toEqual({ kind: 'key', key: 'common.yes' })
      expect(employmentDiffValue('is_primary', 0)).toEqual({ kind: 'key', key: 'common.no' })
      expect(employmentDiffValue('risky_work', true)).toEqual({ kind: 'key', key: 'common.yes' })
    })

    it('prázdnou hodnotu odliší od nuly', () => {
      expect(employmentDiffValue('planned_start_on', null)).toEqual({ kind: 'empty' })
      expect(employmentDiffValue('planned_start_on', '')).toEqual({ kind: 'empty' })
      expect(employmentDiffValue('workload_basis_points', 0)).toEqual({ kind: 'text', text: '0 %' })
    })

    it('data vrací k naformátování, ne jako text', () => {
      expect(employmentDiffValue('actual_start_on', '2025-04-01'))
        .toEqual({ kind: 'date', iso: '2025-04-01' })
    })

    it('úvazek přepočítá z bazických bodů na procenta', () => {
      expect(employmentDiffValue('workload_basis_points', 10000))
        .toEqual({ kind: 'text', text: '100 %' })
    })

    /**
     * Neznámá hodnota nesmí projít jako překladový klíč — `t()` by vrátil sám klíč
     * a uživatel by četl „payroll.people.tax_regime.neco" místo původního textu.
     */
    it('hodnotu mimo výčet vypíše syrově místo neexistujícího klíče', () => {
      expect(employmentDiffValue('tax_regime', 'neco_noveho'))
        .toEqual({ kind: 'text', text: 'neco_noveho' })
    })
  })

  describe('employmentDiffFields', () => {
    it('u události se stavovou hlavičkou stav v diffu vynechá, aby nebyl dvakrát', () => {
      expect(employmentDiffFields({ status: {}, is_primary: {} }, true)).toEqual(['is_primary'])
    })

    it('bez stavové hlavičky stav v diffu ponechá — jinde by ho uživatel nenašel', () => {
      expect(employmentDiffFields({ status: {}, is_primary: {} }, false))
        .toEqual(['status', 'is_primary'])
    })

    it('chybějící diff nespadne', () => {
      expect(employmentDiffFields(null, true)).toEqual([])
    })
  })
})
