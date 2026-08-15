import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PayrollPeopleFilter, PayrollPersonListItem } from '@/api/payroll'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  createPerson: vi.fn(),
  createEmployment: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
  routerReplace: vi.fn(),
  deletePerson: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    createPerson: m.createPerson,
    createEmployment: m.createEmployment,
    deletePerson: m.deletePerson,
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

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PeopleList from '@/pages/payroll/PeopleList.vue'

/** Velikost stránky, se kterou obrazovka chodí na server. */
const PAGE_SIZE = 25

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
    can_delete: true,
    delete_blocker: null,
    delete_cascade: { employments: 0, profile: 1 },
  }
}

interface PageParams {
  limit: number
  offset: number
  filter?: PayrollPeopleFilter
  q?: string
}

/**
 * Náhradní server: zužuje a stránkuje SÁM, přesně jak to dělá `GET /payroll/people`.
 * Kdyby obrazovka zužovala u sebe, testy s ním neprojdou — a přesně to je ta
 * chyba, kterou hlídají (hledání jen v načtené stránce).
 */
let roster: PayrollPersonListItem[] = []

function serveRoster(params: PageParams) {
  const filter = params.filter ?? 'all'
  const needle = (params.q ?? '').toLowerCase()
  const matched = roster.filter((item) => {
    const passesFilter = filter === 'all'
      || (filter === 'active' && item.is_active)
      || (filter === 'needs_setup' && item.needs_setup)
    return passesFilter && (needle === '' || item.full_name.toLowerCase().includes(needle))
  })

  return Promise.resolve({
    items: matched.slice(params.offset, params.offset + params.limit),
    total: matched.length,
    limit: params.limit,
    offset: params.offset,
  })
}

/** Hledání je odložené — test musí počkat, než odklad doběhne. */
async function settleSearch() {
  await new Promise(resolve => setTimeout(resolve, 350))
  await flushPromises()
}

function mountPage() {
  return mount(PeopleList, {
    global: {
      stubs: {
        ActionBar: {
          props: ['actions'],
          template: '<div data-test="person-actions"><button v-for="action in actions" v-show="action.show" :key="action.key" type="button" :data-test="`action-${action.key}`" @click="action.run && action.run()">{{ action.label }}</button></div>',
        },
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
    roster = [
      person(1, 'Alfa Aktivní', true, false),
      person(2, 'Beta Neaktivní', false, false),
      person(3, 'Gama K doplnění', true, true),
    ]
    m.peoplePage.mockImplementation(serveRoster)
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

  /*
   * Selhání načtení a prázdná agenda jsou dva různé stavy, které tahle
   * obrazovka dřív kreslila stejně — „Zatím tu nikdo není" po výpadku sítě.
   */
  it('offers a retry instead of an empty state when the people fail to load', async () => {
    m.peoplePage.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.people.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll.people.empty_title')

    roster = []
    m.peoplePage.mockImplementation(serveRoster)
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the company genuinely has nobody', async () => {
    roster = []

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.people.empty_title')
    expect(wrapper.text()).not.toContain('payroll.people.load_failed_hint')
  })

  /*
   * Zúžení nesmí zůstat v prohlížeči: kdyby hledal jen v načtené stránce,
   * o člověku ze třetí stránky by obrazovka tvrdila, že neexistuje.
   */
  it('sends the search term and the filter to the server instead of narrowing the page', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'active',
      q: '',
    })
    expect(wrapper.text()).toContain('Alfa Aktivní')
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')

    const callsBeforeTyping = m.peoplePage.mock.calls.length
    await wrapper.get('[data-test="people-search"]').setValue('Gama')
    // Na každé písmeno požadavek nejde — odklad ho musí spojit do jednoho.
    expect(m.peoplePage.mock.calls.length).toBe(callsBeforeTyping)

    await settleSearch()
    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'active',
      q: 'Gama',
    })
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')

    await wrapper.get('[data-test="people-search"]').setValue('')
    await settleSearch()

    const filter = wrapper.get('[data-test="people-filter"]')
    await filter.get('input').trigger('focus')
    const allOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.all')
    expect(allOption).toBeDefined()
    await allOption!.trigger('click')
    await flushPromises()
    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'all' }))
    expect(wrapper.text()).toContain('Beta Neaktivní')

    await filter.get('input').trigger('focus')
    const needsSetupOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.needs_setup')
    expect(needsSetupOption).toBeDefined()
    await needsSetupOption!.trigger('click')
    await flushPromises()
    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'needs_setup' }))
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')
    expect(wrapper.get('[data-test="quick-inputs-link"]').classes())
      .toContain('border')
  })

  /*
   * Seznam osob je stránkovaný, takže musí mít čím listovat — a další stránku
   * si musí vyžádat na serveru, ne ukrojit z toho, co má načtené.
   */
  it('renders the shared pagination bar and asks the server for the next page', async () => {
    m.peoplePage.mockImplementation((params: PageParams) => Promise.resolve({
      items: [person(1, 'Alfa Aktivní', true, false)],
      total: 60,
      limit: params.limit,
      offset: params.offset,
    }))

    const wrapper = mountPage()
    await flushPromises()

    const pager = wrapper.get('[data-testid="payroll-people-pagination"]')
    expect(pager.text()).toContain('1 / 3')

    await pager.findAll('button')[1]!.trigger('click')
    await flushPromises()

    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({
      limit: PAGE_SIZE,
      offset: PAGE_SIZE,
    }))
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

  it('names the edited person in the header even without a structured name', async () => {
    // Osoba „test" má vyplněné jen zobrazované jméno — strukturované pole je
    // prázdné a formulář by bez hlavičky vypadal anonymně.
    m.person.mockResolvedValue({
      ...person(1, 'test', true, true),
      employment_count: 2,
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('test')
    expect(wrapper.get('[data-test="person-breadcrumbs"]').text()).toContain('test')
    expect(wrapper.get('[data-test="person-header-employments"]').text())
      .toContain('payroll.people.header_employments')
    expect(wrapper.text()).toContain('payroll.people.needs_setup')
  })

  it('hides the list while editing so no other person stays in view', async () => {
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Gama K doplnění')

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Gama K doplnění')
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Alfa Aktivní')
  })

  it('returns to the list from the breadcrumb and keeps the search and filter', async () => {
    m.person.mockResolvedValue({
      ...person(3, 'Gama K doplnění', true, true),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    const filter = wrapper.get('[data-test="people-filter"]')
    await filter.get('input').trigger('focus')
    const allOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.all')
    await allOption!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="people-search"]').setValue('Gama')
    await settleSearch()

    await wrapper.get('[data-test="edit-employee-3"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(false)

    await wrapper.get('[data-test="breadcrumb-people"]').trigger('click')
    await nextTick()

    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)
    // Návrat, který resetuje filtr, je horší než žádný.
    expect((wrapper.get('[data-test="people-search"]').element as HTMLInputElement).value)
      .toBe('Gama')
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
  })

  it('names what disappears before deleting the person and drops them from the list', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    m.deletePerson.mockResolvedValue({})
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="action-delete-person"]').trigger('click')
    await flushPromises()

    // Dialog musí předem říct, že kaskáda odklidí i vztahy osoby.
    expect(confirm).toHaveBeenCalledWith(
      expect.stringContaining('payroll.people.delete.person_confirm'),
    )
    expect(confirm.mock.calls[0]![0]).toContain('person_cascade.profile')
    expect(m.deletePerson).toHaveBeenCalledWith(1)
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    confirm.mockRestore()
  })

  it('explains why a person cannot be deleted instead of hiding the reason', async () => {
    roster = [{
      ...person(1, 'Alfa Aktivní', true, false),
      can_delete: false,
      delete_blocker: {
        code: 'payroll_employee_in_run',
        message: 'Zaměstnanec je zahrnutý v revizi mzdového běhu.',
        employment_id: null,
        employment_code: null,
      },
    }]
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="person-delete-blocker"]').text())
      .toContain('Zaměstnanec je zahrnutý v revizi mzdového běhu.')
    expect(wrapper.find('[data-test="action-delete-person"]').isVisible()).toBe(false)
  })

  it('creates the shared accounting employee, reloads payroll people and opens next-step detail', async () => {
    roster = []
    m.createPerson.mockImplementation(() => {
      roster = [person(4, 'Delta Nová', true, true)]
      return Promise.resolve({
        id: 4,
        full_name: 'Delta Nová',
        employments: [{ id: 44, employee_id: 4, relation_type: 'employment' }],
      })
    })
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
    // Nová osoba musí být vidět i tehdy, když ji předchozí zúžení schovalo.
    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'all',
      q: '',
    })
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
    roster = []
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
    const callsBeforeSubmit = m.peoplePage.mock.calls.length

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Delta Nová')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.createPerson).toHaveBeenCalledOnce()
    expect(m.createEmployment).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="new-employee-error"]').text())
      .toContain('nic nebylo uloženo')
    expect(m.peoplePage.mock.calls.length).toBe(callsBeforeSubmit)
  })

  it('shows the exact backend validation message', async () => {
    roster = []
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

  /*
   * Deep-link musí fungovat i na osobu, která na načtené stránce není —
   * neaktivní člověk ve výchozím filtru chybí a listovat kvůli odkazu celý
   * seznam by stálo tolik požadavků, kolik má firma stránek.
   */
  it('opens a person missing from the page by fetching that single detail', async () => {
    m.routeQuery.person = '2'
    m.person.mockResolvedValue({
      ...person(2, 'Beta Neaktivní', false, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.person).toHaveBeenCalledWith(2)
    expect(m.peoplePage).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Beta Neaktivní')
  })

  it('ignores an unknown person id in the query', async () => {
    m.routeQuery.person = '999'
    m.person.mockRejectedValue(new Error('not found'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(false)
    expect(m.toastError).not.toHaveBeenCalled()
  })
})
