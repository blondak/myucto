import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PayrollPersonListItem } from '@/api/payroll'

const m = vi.hoisted(() => ({
  people: vi.fn(),
  person: vi.fn(),
  createPerson: vi.fn(),
  createEmployment: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    people: m.people,
    person: m.person,
    createPerson: m.createPerson,
    createEmployment: m.createEmployment,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: m.toastSuccess,
    error: m.toastError,
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PeopleList from '@/pages/payroll/PeopleList.vue'

function person(
  id: number,
  fullName: string,
  isActive: boolean,
  needsSetup: boolean,
): PayrollPersonListItem {
  return {
    id,
    full_name: fullName,
    is_active: isActive,
    profile_status: needsSetup ? 'setup' : 'ready',
    legacy_taxpayer_type: 'employee',
    legacy_employment_type: 'hpp',
    employment_count: 0,
    relation_types: [],
    needs_setup: needsSetup,
  }
}

function mountPage() {
  return mount(PeopleList, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a data-test="router-link"><slot /></a>',
        },
        EmploymentCard: true,
        PayrollPersonQuickEdit: {
          props: ['personId', 'canWrite'],
          template: '<div data-test="quick-edit-stub">{{ personId }}</div>',
        },
        PayrollPersonProfilePanel: true,
      },
    },
  })
}

describe('PeopleList toolbar and shared employee creation', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    m.people.mockResolvedValue([
      person(1, 'Alfa Aktivní', true, false),
      person(2, 'Beta Neaktivní', false, false),
      person(3, 'Gama K doplnění', true, true),
    ])
    m.person.mockResolvedValue({
      ...person(4, 'Delta Nová', true, true),
      employments: [],
    })
    m.createPerson.mockResolvedValue({
      id: 4,
      full_name: 'Delta Nová',
      employments: [{
        id: 44,
        employee_id: 4,
        relation_type: 'employment',
      }],
    })
    m.createEmployment.mockResolvedValue({
      id: 44,
      employee_id: 4,
      relation_type: 'employment',
    })
  })

  it('searches the visible list and switches between active, all and setup filters', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Alfa Aktivní')
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')

    await wrapper.get('[data-test="people-search"]').setValue('Gama')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    expect(wrapper.text()).toContain('Gama K doplnění')

    await wrapper.get('[data-test="people-search"]').setValue('')
    const filter = wrapper.get('[data-test="people-filter"]')
    await filter.get('input').trigger('focus')
    const allOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.all')
    expect(allOption).toBeDefined()
    await allOption!.trigger('click')
    await nextTick()
    expect(wrapper.text()).toContain('Beta Neaktivní')

    await filter.get('input').trigger('focus')
    const needsSetupOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.needs_setup')
    expect(needsSetupOption).toBeDefined()
    await needsSetupOption!.trigger('click')
    await nextTick()
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')
    expect(wrapper.get('[data-test="quick-inputs-link"]').classes())
      .toContain('border')
  })

  it('opens the common editor first and keeps advanced history collapsed', async () => {
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="quick-edit-stub"]').text()).toBe('1')
    expect(wrapper.get('[data-test="advanced-person-profile"]').attributes('open')).toBeUndefined()
  })

  it('creates the shared accounting employee, reloads payroll people and opens next-step detail', async () => {
    m.people
      .mockResolvedValueOnce([])
      .mockResolvedValueOnce([person(4, 'Delta Nová', true, true)])
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    expect(wrapper.find('[data-test="new-employee-relation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="new-employee-planned-start"]').exists()).toBe(true)
    const relation = wrapper.get('[data-test="new-employee-relation"]')
    await relation.get('input').trigger('focus')
    expect(relation.findAll('[role="option"]').map(option => option.text())).toEqual([
      'payroll.people.relations.employment',
      'payroll.people.relations.small_scale_employment',
      'payroll.people.relations.dpp',
      'payroll.people.relations.dpc',
      'payroll.people.relations.partner_dependent',
      'payroll.people.relations.statutory_body',
    ])
    await wrapper.get('[data-test="new-employee-name"]').setValue(' Delta Nová ')
    await wrapper.get('[data-test="new-employee-birth-number"]').setValue('0001010009')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.createPerson).toHaveBeenCalledWith({
      full_name: 'Delta Nová',
      birth_date: null,
      birth_number: '0001010009',
      relation_type: 'employment',
      planned_start_on: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
      monthly_gross: null,
    })
    expect(m.people).toHaveBeenCalledTimes(2)
    expect(m.createEmployment).not.toHaveBeenCalled()
    expect(m.person).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="employee-created-next"]').text())
      .toContain('payroll.people.create.next_steps')
    expect(wrapper.text()).not.toContain('0001010009')
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(false)
    expect(m.toastSuccess).toHaveBeenCalledWith(
      'payroll.people.create.created',
    )
  })

  it('reports an exact atomic creation error without reloading a partial person', async () => {
    m.people.mockResolvedValue([])
    m.createPerson.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Zaměstnance a pracovní vztah nelze založit.',
            fields: {
              planned_start_on: ['Datum nástupu je mimo povolené období; nic nebylo uloženo.'],
            },
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Delta Nová')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.createPerson).toHaveBeenCalledOnce()
    expect(m.createEmployment).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="new-employee-error"]').text())
      .toContain('nic nebylo uloženo')
    expect(m.people).toHaveBeenCalledOnce()
  })

  it('shows the exact backend validation message', async () => {
    m.people.mockResolvedValue([])
    m.createPerson.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Zaměstnanec již existuje.',
            fields: {
              full_name: ['Použijte existujícího zaměstnance.'],
            },
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Duplicitní')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith(
      'Zaměstnanec již existuje.: Použijte existujícího zaměstnance.',
    )
    expect(wrapper.get('[data-test="new-employee-error"]').text())
      .toContain('Použijte existujícího zaměstnance.')
  })

  it('opens the person from ?person= so the card link lands on a detail', async () => {
    m.routeQuery.person = '2'
    m.person.mockResolvedValue({
      ...person(2, 'Beta Neaktivní', false, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.person).toHaveBeenCalledWith(2)
    // Neaktivní člověk se ve výchozím filtru nezobrazuje — deep-link proto
    // musí filtr přepnout, jinak by odkaz skončil na prázdném seznamu.
    expect(wrapper.text()).toContain('Beta Neaktivní')
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
  })

  it('ignores an unknown person id in the query', async () => {
    m.routeQuery.person = '999'
    const wrapper = mountPage()
    await flushPromises()

    expect(m.person).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(false)
  })
})
