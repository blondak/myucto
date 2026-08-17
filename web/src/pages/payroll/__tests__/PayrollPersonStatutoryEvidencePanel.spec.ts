import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollStatutoryEvidence } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  statutoryEvidence: vi.fn(),
  saveStatutoryEvidence: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    statutoryEvidence: mocks.statutoryEvidence,
    saveStatutoryEvidence: mocks.saveStatutoryEvidence,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollPersonStatutoryEvidencePanel from '@/pages/payroll/PayrollPersonStatutoryEvidencePanel.vue'

function emptyEvidence(overrides: Partial<PayrollStatutoryEvidence> = {}): PayrollStatutoryEvidence {
  return {
    employee_id: 17,
    effective_on: '2026-08-31',
    frozen_through: null,
    sections: {
      tax_declarations: [],
      tax_residences: [],
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
    },
    other_employer_bases: [],
    blockers: [
      'tax_declaration_evidence_missing',
      'tax_residence_evidence_missing',
      'social_jurisdiction_evidence_missing',
      'working_pensioner_discount_evidence_missing',
      'health_coverage_evidence_missing',
    ],
    ...overrides,
  }
}

function filledEvidence(): PayrollStatutoryEvidence {
  return emptyEvidence({
    frozen_through: '2026-04-30',
    blockers: [],
    sections: {
      tax_declarations: [{
        id: 5,
        row_version: 2,
        status: 'signed',
        evidence_reference: 'document:tax-declaration-2026',
        evidence_note: 'Papír ve složce',
        effective_from: '2026-01-01',
        effective_to: null,
      }],
      tax_residences: [],
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
    },
  })
}

async function mounted(canWrite = true) {
  const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
    props: { personId: 17, canWrite },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonStatutoryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    mocks.saveStatutoryEvidence.mockResolvedValue(filledEvidence())
  })

  it('pojmenuje konkrétně, co chybí, a co se stane, když to zůstane nevyplněné', async () => {
    const wrapper = await mounted()

    const blockers = wrapper.get('[data-test="statutory-evidence-blockers"]')
    expect(blockers.findAll('li')).toHaveLength(5)
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blocker.tax_declaration_evidence_missing',
    )
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blockers_consequence',
    )
    expect(wrapper.find('[data-test="statutory-evidence-complete"]').exists()).toBe(false)
  })

  it('má jediné společné Uložit, žádné tlačítko na jednotlivý záznam', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')

    expect(wrapper.findAll('[data-test="statutory-evidence-save"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test^="save-"]')).toHaveLength(0)
  })

  it('bez práva zápisu nenabídne ani úpravu, ani uložení', async () => {
    const wrapper = await mounted(false)

    expect(wrapper.find('[data-test="start-statutory-evidence"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="statutory-evidence-save"]').exists()).toBe(false)
  })

  it('pošle celé kolekce jako cílový stav včetně nově přidaného záznamu', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    await wrapper.get('[data-test="tax_declarations-0-effective_from"]').setValue('2026-01-01')
    await wrapper.get('[data-test="tax_declarations-0-status"]').setValue('signed')
    await wrapper.get('[data-test="tax_declarations-0-evidence_reference"]')
      .setValue('document:tax-declaration-2026')
    await wrapper.get('[data-test="tax_declarations-0-evidence_note"]')
      .setValue('Papír ve složce')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    const [personId, payload] = mocks.saveStatutoryEvidence.mock.calls[0]!
    expect(personId).toBe(17)
    expect(payload.sections.tax_declarations).toEqual([expect.objectContaining({
      status: 'signed',
      evidence_reference: 'document:tax-declaration-2026',
      evidence_note: 'Papír ve složce',
      effective_from: '2026-01-01',
      effective_to: null,
    })])
    // Nedotčené kolekce musí odejít taky — tělo popisuje cílový stav.
    expect(payload.sections.health_coverages).toEqual([])
    expect(mocks.success).toHaveBeenCalled()
  })

  it('neověřená varianta je nabídnutá jako plnohodnotná volba', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    const options = wrapper.get('[data-test="tax_declarations-0-status"]')
      .findAll('option')
      .map(option => option.attributes('value'))
    expect(options).toEqual(['signed', 'not-signed', 'unverified'])
  })

  it('u uzavřeného období nedovolí posunout začátek ani záznam odebrat', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()
    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')

    expect(
      wrapper.get('[data-test="tax_declarations-0-effective_from"]').attributes('disabled'),
    ).toBeDefined()
    expect(wrapper.find('[data-test="remove-tax_declarations-0"]').exists()).toBe(false)
    // Ukončit jde — to historii nepřepisuje.
    expect(
      wrapper.get('[data-test="tax_declarations-0-effective_to"]').attributes('disabled'),
    ).toBeUndefined()
    expect(wrapper.find('[data-test="statutory-evidence-frozen"]').exists()).toBe(true)
  })

  it('ponechá na obrazovce konkrétní hlášku serveru, ne obecný text', async () => {
    mocks.saveStatutoryEvidence.mockRejectedValue({
      response: { data: { error: { message: 'Evidence „tax_declarations“ musí na sebe navazovat.' } } },
    })
    const wrapper = await mounted()
    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="statutory-evidence-error"]').text())
      .toContain('musí na sebe navazovat')
  })

  it('při přepnutí osoby načte evidenci znovu', async () => {
    const wrapper = await mounted()
    expect(mocks.statutoryEvidence).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ personId: 42 })
    await flushPromises()

    expect(mocks.statutoryEvidence).toHaveBeenCalledTimes(2)
    expect(mocks.statutoryEvidence.mock.calls[1]![0]).toBe(42)
  })
})
