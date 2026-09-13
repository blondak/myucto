import { describe, expect, it } from 'vitest'
import type { PayrollRunValidation, PayrollStatutoryBulkPerson } from '@/api/payroll'
import {
  bulkApplyEmployeeIds,
  bulkCodeKey,
  discountObstacleCounts,
  effectiveBasisCounts,
  evidenceRefreshCommand,
  firstStatutoryReviewId,
  statutoryReviewEmployeeIds,
} from '../statutoryBulkDefaults'

function person(overrides: Partial<PayrollStatutoryBulkPerson> = {}): PayrollStatutoryBulkPerson {
  return {
    employee_id: 1,
    full_name: 'Jana Testovací',
    status: 'ready',
    reasons: [],
    foreign_elements: [],
    effective_from: '2026-08-01',
    effective_from_basis: 'run_month',
    sections: {
      tax_residences: { state: 'add', reason: null },
      social_jurisdictions: { state: 'add', reason: null },
      social_discount_claims: { state: 'add', reason: null },
      tax_declarations: { state: 'missing', reason: null },
    },
    health_insurer_missing: false,
    unsigned_declaration_withholding_risk: false,
    withholding_employment_ids: [],
    ...overrides,
  }
}

function validation(overrides: Partial<PayrollRunValidation>): PayrollRunValidation {
  return {
    id: 1,
    code: 'statutory_calculation_manual_review',
    severity: 'blocker',
    entity_type: 'employee',
    entity_id: 10,
    message: 'Zákonný výpočet nebyl dokončen.',
    remediation_path: null,
    requires_override: false,
    overridden_at: null,
    overridden_by_name: null,
    override_reason: null,
    ...overrides,
  } as PayrollRunValidation
}

describe('bulkApplyEmployeeIds', () => {
  const preview = { ready_employee_ids: [3, 1], declaration_missing_employee_ids: [1, 7] }

  it('bez potvrzení posílá jen připravené osoby', () => {
    expect(bulkApplyEmployeeIds(preview, false)).toEqual([3, 1])
  })

  it('s potvrzením přidá osoby bez prohlášení, každou jednou', () => {
    expect(bulkApplyEmployeeIds(preview, true)).toEqual([3, 1, 7])
  })
})

describe('bulkCodeKey', () => {
  it('známý kód převede na klíč překladu', () => {
    expect(bulkCodeKey('foreign', 'a1_certificate')).toBe('payroll.statutory_bulk.foreign.a1_certificate')
    expect(bulkCodeKey('reason', 'period_frozen')).toBe('payroll.statutory_bulk.reason.period_frozen')
  })

  it('neznámý kód nechá na volajícím', () => {
    expect(bulkCodeKey('reason', 'something_new')).toBeNull()
    expect(bulkCodeKey('discount', 'foreign_permit')).toBeNull()
  })
})

describe('discountObstacleCounts', () => {
  it('sečte důvody vynechané slevy důchodce jen u nevyřazených osob', () => {
    const blocked = (reason: string, status: PayrollStatutoryBulkPerson['status'] = 'ready') => person({
      status,
      sections: { social_discount_claims: { state: 'excluded', reason } },
    })
    expect(discountObstacleCounts({
      people: [
        blocked('age_60_or_more'),
        blocked('age_60_or_more'),
        blocked('birth_date_missing', 'nothing_to_add'),
        blocked('birth_date_invalid', 'excluded'),
        person(),
      ],
    })).toEqual([
      { reason: 'age_60_or_more', count: 2 },
      { reason: 'birth_date_missing', count: 1 },
    ])
  })
})

describe('effectiveBasisCounts', () => {
  it('ukáže jen odchylky od prvního dne měsíce u připravených osob', () => {
    expect(effectiveBasisCounts({
      people: [
        person(),
        person({ effective_from_basis: 'employment_start' }),
        person({ effective_from_basis: 'after_frozen_period' }),
        person({ effective_from_basis: 'after_frozen_period' }),
        person({ status: 'excluded', effective_from_basis: 'employment_start' }),
      ],
    })).toEqual([
      { basis: 'employment_start', count: 1 },
      { basis: 'after_frozen_period', count: 2 },
    ])
  })
})

describe('validace běhu', () => {
  const validations = [
    validation({ id: 5, code: 'draft_inputs_present', entity_id: 99 }),
    validation({ id: 6, entity_id: 10 }),
    validation({ id: 7, entity_id: 10 }),
    validation({ id: 8, entity_type: 'employment', entity_id: 44 }),
    validation({ id: 9, entity_id: 11 }),
  ]

  it('spočítá osoby s nedokončeným zákonným výpočtem', () => {
    expect(statutoryReviewEmployeeIds(validations)).toEqual([10, 11])
  })

  it('akci kreslí u první validace skupiny', () => {
    expect(firstStatutoryReviewId(validations)).toBe(6)
    expect(firstStatutoryReviewId([validations[0]])).toBeNull()
  })
})

describe('evidenceRefreshCommand', () => {
  it('koncept stačí spočítat, zámek vezme čerstvou evidenci', () => {
    expect(evidenceRefreshCommand({ available_commands: ['lock_inputs', 'lock_and_calculate', 'cancel'] }))
      .toBe('lock_and_calculate')
  })

  it('spočítaný běh nepřepočítává ze starého snímku, ale nabídne zrušení', () => {
    expect(evidenceRefreshCommand({ available_commands: ['calculate', 'review', 'approve', 'cancel'] }))
      .toBe('cancel')
  })

  it('zrušený nebo opravný běh otevře novou revizi', () => {
    expect(evidenceRefreshCommand({ available_commands: ['reopen'] })).toBe('reopen')
  })

  it('schválený běh vrátí k opravě', () => {
    expect(evidenceRefreshCommand({ available_commands: ['post', 'request_correction'] }))
      .toBe('request_correction')
  })

  it('bez vhodného příkazu nic nenabízí', () => {
    expect(evidenceRefreshCommand({ available_commands: ['close'] })).toBeNull()
  })
})
