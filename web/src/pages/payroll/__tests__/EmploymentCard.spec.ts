import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { payrollApi, type PayrollEmployment } from '@/api/payroll'

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    transitionEmployment: vi.fn(),
    addEmploymentTerms: vi.fn(),
    updateEmploymentChecklist: vi.fn(),
    employmentJmhzEvidenceOptions: vi.fn().mockResolvedValue({
      package_key: 'synthetic',
      manifest_sha256: 'a'.repeat(64),
      external_codebooks: {
        overlay_key: 'synthetic-overlay',
        manifest_sha256: 'b'.repeat(64),
        snapshot_date: '2026-08-13',
        effective_from: '2026-01-01',
        verified_through: '2026-08-13',
        base_spec_manifest_sha256: 'a'.repeat(64),
      },
      apz_instruments: [{ code: '1', label: 'VPP' }],
      activity_codes: [
        { code: '1', label: 'Pracovní poměr' },
        { code: 'A', label: 'Dohoda' },
      ],
      relationship_detail_codes: [{ code: '1', label: 'Žádné' }],
      countries: [{ code: 'CZ', label: 'Česko' }],
    }),
    searchJmhzMunicipalities: vi.fn().mockResolvedValue([
      { code: '554782', label: 'Hlavní město Praha' },
    ]),
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmploymentCard from '@/pages/payroll/EmploymentCard.vue'

function employment(): PayrollEmployment {
  return {
    id: 10,
    employee_id: 20,
    office_id: null,
    office_code: null,
    office_name: null,
    code: 'HPP-1',
    relation_type: 'employment',
    status: 'planned',
    is_primary: true,
    start_date: '2026-01-01',
    actual_start_date: null,
    end_date: null,
    archived_at: null,
    is_legacy_projection: false,
    monthly_gross_minor: 4000000,
    row_version: 1,
    allowed_transitions: ['preregistered', 'no_show'],
    accounting: {
      gross_debit: '521',
      gross_credit: '331',
      employer_insurance_debit: '524',
      employer_insurance_credit: '336',
    },
    terms: [{
      id: 1,
      office_id: null,
      office_code: null,
      effective_from: '2026-01-01',
      effective_to: null,
      contract_signed_on: null,
      planned_start_on: '2026-01-01',
      actual_start_on: null,
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: null,
      regular_workplace: null,
      jmhz_workplace_municipality_code: null,
      jmhz_workplace_country_code: null,
      jmhz_apz_contribution_status: 'unverified',
      jmhz_apz_instrument_code: null,
      jmhz_functional_benefits_status: 'unverified',
      jmhz_temporary_assignment_status: 'unverified',
      cz_isco_code: null,
      activity_code: null,
      jmhz_relationship_detail_code: null,
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      tax_declaration_signed: false,
      is_primary: true,
      change_reason: 'Initial',
      row_version: 1,
      created_at: '2026-01-01 00:00:00',
    }],
    checklist: [{
      id: 1,
      phase: 'onboarding',
      item_key: 'employment_contract',
      status: 'pending',
      due_date: '2026-01-01',
      completed_at: null,
      note: null,
      row_version: 1,
    }],
    timeline: [{
      id: 1,
      event_type: 'created',
      from_status: null,
      to_status: 'planned',
      effective_on: '2026-01-01',
      note: null,
      diff: { relation_type: { from: null, to: 'employment' } },
      created_at: '2026-01-01 00:00:00',
    }],
  }
}

describe('EmploymentCard', () => {
  it('read-only uživateli ukáže historii a checklist, ale žádné mutace', () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: false },
    })

    expect(wrapper.text()).toContain('payroll.people.timeline_title')
    expect(wrapper.text()).toContain('payroll.people.checklist.employment_contract')
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.people.transition.preregistered')
  })

  it('oprávněnému uživateli sestaví stavové ActionBar akce a datum účinnosti', () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
      global: {
        stubs: {
          ActionBar: {
            props: ['actions'],
            template: '<div data-test="actions"><span v-for="action in actions" v-show="action.show">{{ action.label }}</span></div>',
          },
        },
      },
    })

    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.transition.preregistered')
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.transition.no_show')
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.new_terms')
  })

  it('edituje JMHZ evidenci jako tri-state a čte APZ z připnutých možností', async () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-evidence"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('yes')
    expect(wrapper.get('[data-test="jmhz-apz-instrument"]').text()).toContain('1 · VPP')
    await wrapper.get('[data-test="jmhz-apz-instrument"]').setValue('1')
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('no')
    expect(wrapper.find('[data-test="jmhz-apz-instrument"]').exists()).toBe(false)
  })

  it('vyžádá 10502 jen pro druh činnosti 1 až 9 a při změně jej vyčistí', async () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('1')
    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-relationship-detail"]').setValue('1')
    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('A')
    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(false)
  })

  it('vybere obec atomicky z připnutého CISOB a odešle kanonický název i kód', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const municipality = wrapper.findComponent({ name: 'SearchableSelect' })
    municipality.vm.$emit('search', 'Praha')
    await flushPromises()
    municipality.vm.$emit('update:modelValue', '554782')
    await flushPromises()
    await wrapper.get('textarea').setValue('Ověření pracoviště')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_workplace_municipality_code).toBe('554782')
    expect(payload?.work_place).toBe('Hlavní město Praha')
    expect(payload?.jmhz_workplace_country_code).toBe('CZ')

    const reopen = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await reopen!.trigger('click')
    await flushPromises()
    const reopenedMunicipality = wrapper.findComponent({ name: 'SearchableSelect' })
    reopenedMunicipality.vm.$emit('search', 'Praha')
    await flushPromises()
    reopenedMunicipality.vm.$emit('update:modelValue', '554782')
    await flushPromises()
    reopenedMunicipality.vm.$emit('update:modelValue', null)
    await flushPromises()
    await wrapper.get('textarea').setValue('Vymazání pracoviště')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const cleared = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(cleared?.jmhz_workplace_municipality_code).toBeNull()
    expect(cleared?.work_place).toBeNull()
    expect(cleared?.jmhz_workplace_country_code).toBeNull()
  })
})
