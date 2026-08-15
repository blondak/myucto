import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  context: vi.fn(),
  absences: vi.fn(),
  averages: vi.fn(),
  leaveLedger: vi.fn(),
  decide: vi.fn(),
  createAbsence: vi.fn(),
  createAverage: vi.fn(),
  createEntitlement: vi.fn(),
  createLeaveEntry: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    context: m.context,
    absences: m.absences,
    averages: m.averages,
    leaveLedger: m.leaveLedger,
    decide: m.decide,
    createAbsence: m.createAbsence,
    cancel: vi.fn(),
    createAverage: m.createAverage,
    approveAverage: vi.fn(),
    createLeaveEntry: m.createLeaveEntry,
    createEntitlement: m.createEntitlement,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: ref('cs-CZ'),
  }),
}))

import AbsenceManagement from '@/pages/payroll/AbsenceManagement.vue'

describe('AbsenceManagement', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    m.context.mockResolvedValue([{
      id: 12,
      employee_id: 5,
      code: 'SYNTH-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }, {
      id: 13,
      employee_id: 6,
      code: 'SYNTH-DPC',
      relation_type: 'dpc',
      status: 'active',
      full_name: 'Druhá syntetická osoba',
    }])
    m.absences.mockResolvedValue([{
      id: 44,
      employment_id: 12,
      full_name: 'Syntetická osoba',
      employment_code: 'SYNTH-HPP',
      absence_type: 'dpn',
      date_from: '2026-06-15',
      date_to: '2026-06-28',
      partial_first_minutes: null,
      partial_last_minutes: null,
      average_snapshot_id: 8,
      average_hourly_minor: 50_000,
      note: null,
      support_status: 'manual_review',
      status: 'requested',
      correction_pending: false,
      row_version: 1,
    }])
    m.averages.mockResolvedValue([{
      id: 8,
      employment_id: 12,
      applicable_year: 2026,
      applicable_quarter: 2,
      source_kind: 'actual',
      average_hourly_minor: 50_000,
      rationale: null,
      support_status: 'manual_review',
      status: 'approved',
      row_version: 2,
    }])
    m.leaveLedger.mockResolvedValue({ entries: [], balance_minutes: 0 })
    m.decide.mockResolvedValue({ id: 44, status: 'approved' })
    m.createAbsence.mockResolvedValue({ id: 45 })
    m.createAverage.mockResolvedValue({ id: 9 })
    m.createEntitlement.mockResolvedValue({ id: 10 })
    m.createLeaveEntry.mockResolvedValue({ id: 11 })
  })

  it('offers a retry instead of an empty state when the absences fail to load', async () => {
    m.absences.mockRejectedValue(new Error('network'))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll_absence.messages.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll_absence.absences.empty')

    m.absences.mockResolvedValue([])
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the relation genuinely has no absence', async () => {
    m.absences.mockResolvedValue([])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll_absence.absences.empty')
    expect(wrapper.text()).not.toContain('payroll_absence.messages.load_failed_hint')
  })

  /*
   * Zašedlé „Vytvořit" u dovolené dřív mlčelo. Když navíc pro vztah není
   * spočítaný žádný průměr, uživatel neměl jak zjistit, že se počítá jinde.
   */
  it('points to the Averages tab when no average exists for the relation', async () => {
    m.averages.mockResolvedValue([])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const button = wrapper.get('[data-test="absence-create"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title'))
      .toBe('payroll_absence.absences.average_missing_for_relation')
    expect(wrapper.get('[data-test="absence-create-blocked"]').text())
      .toBe('payroll_absence.absences.average_missing_for_relation')

    await wrapper.get('[data-test="go-to-averages"]').trigger('click')
    expect(wrapper.text()).toContain('payroll_absence.averages.create')
  })

  it('asks for a pick when an average exists but none is selected', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="go-to-averages"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="absence-create"]').attributes('title'))
      .toBe('payroll_absence.absences.average_required_hint')
  })

  it('renders a responsive DPN card and sends explicit review flags', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll_absence.types.dpn')
    expect(wrapper.text()).toContain('Syntetická osoba')
    const checks = wrapper.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(3)
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    const approve = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))
    await approve!.trigger('click')
    await flushPromises()

    expect(m.decide).toHaveBeenCalledWith(44, {
      row_version: 1,
      decision: 'approved',
      first_day_fully_worked: false,
      insurance_eligibility_confirmed: true,
      conflicting_benefit_excluded: true,
    })
    wrapper.unmount()
  })

  it('exposes all three agenda tabs on the same mobile-safe page', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(wrapper.text()).toContain('payroll_absence.tabs.absences')
    expect(wrapper.text()).toContain('payroll_absence.tabs.averages')
    expect(wrapper.text()).toContain('payroll_absence.tabs.leave')
    const activeTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.absences')
    expect(activeTab!.classes()).toContain('border-payroll-600')
    expect(activeTab!.classes()).not.toContain('bg-payroll-600')
    wrapper.unmount()
  })

  it('uses searchable selectors and visibly bordered controls in forms', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.findAll('[role="combobox"]').length).toBeGreaterThan(0)
    const averagesTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.averages')
    await averagesTab!.trigger('click')

    const formInputs = wrapper.findAll('input[type="number"], input[type="date"], input[type="text"]')
    expect(formInputs.length).toBeGreaterThan(0)
    for (const input of formInputs) {
      expect(input.classes()).toContain('border-neutral-300')
      expect(input.classes()).toContain('bg-surface')
    }
    wrapper.unmount()
  })

  it('does not submit an absence requiring an approved average without one', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const create = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.absences.create'))
    expect(create!.attributes('disabled')).toBeDefined()
    await create!.trigger('click')

    expect(m.createAbsence).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('loads through the actual last local day of the month', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const today = new Date()
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0)
    const expected = `${lastDay.getFullYear()}-${String(lastDay.getMonth() + 1).padStart(2, '0')}-${String(lastDay.getDate()).padStart(2, '0')}`
    expect(m.absences.mock.calls[0][1]).toBe(expected)
    wrapper.unmount()
  })

  it('converts human money and time units to the unchanged average API contract', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    await wrapper.find('[data-test="average-gross-czk"]').setValue('12345.67')
    await wrapper.find('[data-test="average-allocated-czk"]').setValue('10.05')
    await wrapper.find('[data-test="average-worked-hours"]').setValue('160.5')
    await wrapper.find('[data-test="average-worked-days"]').setValue('20')
    await wrapper.find('[data-test="average-probable-czk"]').setValue('250.25')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({
      gross_earnings_minor: 1_234_567,
      longer_period_allocated_minor: 1_005,
      worked_minutes: 9_630,
      worked_days: 20,
      probable_hourly_minor: 25_025,
    }))
    wrapper.unmount()
  })

  it('converts absence and leave hours to whole minutes at the API boundary', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const selectors = wrapper.findAllComponents({ name: 'SearchableSelect' })
    selectors[1].vm.$emit('update:modelValue', 8)
    await wrapper.find('[data-test="absence-partial-first-hours"]').setValue('2.5')
    await wrapper.find('[data-test="absence-partial-last-hours"]').setValue('1.25')
    await wrapper.find('[data-test="absence-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAbsence).toHaveBeenCalledWith(expect.objectContaining({
      partial_first_minutes: 150,
      partial_last_minutes: 75,
    }))

    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    await wrapper.find('[data-test="leave-weekly-hours"]').setValue('37.5')
    await wrapper.find('[data-test="leave-worked-hours"]').setValue('1040')
    await wrapper.find('[data-test="leave-rationale"]').setValue('Syntetické ruční posouzení')
    await wrapper.find('[data-test="leave-entitlement-form"]').trigger('submit')
    await flushPromises()
    expect(m.createEntitlement).toHaveBeenCalledWith(expect.objectContaining({
      weekly_minutes: 2_250,
      worked_equivalent_minutes: 62_400,
    }))

    await wrapper.find('[data-test="leave-entry-hours"]').setValue('-7.5')
    await wrapper.find('[data-test="leave-entry-reason"]').setValue('Syntetická oprava')
    await wrapper.find('[data-test="leave-entry-form"]').trigger('submit')
    await flushPromises()
    expect(m.createLeaveEntry).toHaveBeenCalledWith(expect.objectContaining({
      minutes_delta: -450,
    }))
    wrapper.unmount()
  })

  it('rejects excessive precision locally and renders the exact API error inline', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    await wrapper.find('[data-test="average-gross-czk"]').setValue('1.001')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('payroll_absence.validation.money_precision')

    m.createAverage.mockRejectedValueOnce({
      response: { data: { error: { message: 'Přesná zpráva validační chyby z API.' } } },
    })
    await wrapper.find('[data-test="average-gross-czk"]').setValue('100')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('Přesná zpráva validační chyby z API.')
    expect(m.toastError).not.toHaveBeenCalledWith('Přesná zpráva validační chyby z API.')
    wrapper.unmount()
  })

  it('uses a rolling year range instead of freezing form controls at 2026', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    const currentYear = new Date().getFullYear()
    const yearInput = wrapper.find('[data-test="average-year"]')
    expect(Number(yearInput.attributes('min'))).toBeLessThanOrEqual(currentYear - 5)
    expect(Number(yearInput.attributes('max'))).toBeGreaterThanOrEqual(currentYear + 1)

    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    const leaveYearInput = wrapper.find('[data-test="leave-year"]')
    expect(Number(leaveYearInput.attributes('min'))).toBeLessThanOrEqual(currentYear - 5)
    expect(Number(leaveYearInput.attributes('max'))).toBeGreaterThanOrEqual(currentYear + 1)
    wrapper.unmount()
  })

  it('preselects the employment and absence type coming from the card link', async () => {
    m.routeQuery.employment = '13'
    m.routeQuery.type = 'dpn'
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(m.absences.mock.calls[0][2]).toBe(13)
    const selectors = wrapper.findAllComponents({ name: 'SearchableSelect' })
    expect(selectors[0].props('modelValue')).toBe(13)
    expect(selectors[1].props('modelValue')).toBe('dpn')
    wrapper.unmount()
  })

  it('ignores an unknown employment in the query instead of breaking the page', async () => {
    m.routeQuery.employment = '999'
    m.routeQuery.type = 'not-an-absence-type'
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(m.absences.mock.calls[0][2]).toBe(12)
    const selectors = wrapper.findAllComponents({ name: 'SearchableSelect' })
    expect(selectors[1].props('modelValue')).toBe('vacation')
    wrapper.unmount()
  })
})
