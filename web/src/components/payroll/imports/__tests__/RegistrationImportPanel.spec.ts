import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RegistrationApplyResult, RegistrationPreview, RegistrationRecord } from '@/api/payrollImports'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  apply: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
}))

vi.mock('vue-router', async () => {
  const { defineComponent: define, h: render } = await import('vue')
  return {
    RouterLink: define({
      name: 'RouterLink',
      props: { to: { type: [String, Object], required: true } },
      setup(props, { slots, attrs }) {
        return () => render('a', { ...attrs, 'data-to': JSON.stringify(props.to) }, slots.default?.())
      },
    }),
  }
})
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    te: () => true,
    t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))
vi.mock('@/api/client', () => ({ api: {} }))
vi.mock('@/api/payrollImports', () => ({
  payrollImportsApi: { previewRegistrations: m.preview, applyRegistrations: m.apply },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, warning: m.warning, error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_: unknown, fallback: string) => fallback }))

import RegistrationImportPanel from '../RegistrationImportPanel.vue'

function record(key: string, overrides: Partial<RegistrationRecord> = {}): RegistrationRecord {
  return {
    key,
    file: 'jmhz-01.xml',
    sequence: 1,
    document_type: 'JMHZ',
    action_code: 0,
    action_label: 'Měsíční hlášení 2026-01',
    prepared_on: null,
    effective_on: '2026-01-01',
    person: { full_name: 'Syntetická osoba', first_name: null, last_name: null, birth_date: null, birth_number_masked: null, has_oic: true },
    employment: {
      start_on: null,
      end_on: null,
      activity_code: null,
      relation_type: null,
      relation_type_options: [],
      start_estimated: false,
      position_name: null,
      has_id_ppv: true,
    },
    match: { status: 'not_found', matched_by: null, employee_id: null, employee_name: null, employment_id: null, employment_code: null, candidates: [] },
    operation: 'none',
    changes: [],
    warnings: [],
    blocker: null,
    selectable: true,
    period: '2026-01',
    form_id: null,
    history: null,
    ...overrides,
  }
}

const concurrentForm = record('aaaaaaaaaaaaaaaa:2', {
  operation: 'create_employment',
  match: { status: 'new', matched_by: null, employee_id: 50, employee_name: 'Syntetická osoba', employment_id: null, employment_code: null, candidates: [] },
})
const derived = record('bbbbbbbbbbbbbbbb:1', {
  document_type: 'JMHZ_DERIVED',
  action_label: 'Vztah doložený měsíčními hlášeními JMHZ',
  operation: 'create_employment',
  period: null,
  employment: {
    start_on: '2026-01-01',
    end_on: null,
    activity_code: null,
    relation_type: 'dpp',
    relation_type_options: ['dpp', 'dpc'],
    start_estimated: true,
    position_name: null,
    has_id_ppv: true,
  },
})

function preview(records: RegistrationRecord[]): RegistrationPreview {
  return {
    environment: 'production',
    files: [],
    records,
    summary: { total: records.length, create: 2, update: 0, terminate: 0, none: 0, blocked: 0, pair_required: 0 },
    employment_options: [],
    opening_balances: [],
    averages: [],
    takeover: {
      start_period: '2026-04',
      months: [{
        period: '2026-01',
        status: 'partial',
        reason: 'Převezme se jen část měsíce.',
        ready_count: 1,
        gross_minor: 4_000_000,
        net_minor: 3_000_000,
        advance_tax_minor: 200_000,
        blocked: [{ label: 'Syntetická osoba', reason: 'Formulář není spárovaný s pracovním vztahem v evidenci.' }],
      }],
      relations: [],
    },
  }
}

const DropzoneStub = defineComponent({
  name: 'ImportFilesDropzoneStub',
  props: { files: { type: Array, default: () => [] } },
  emits: ['update:files'],
  setup: () => () => h('div'),
})

async function mountWithPreview() {
  const wrapper = mount(RegistrationImportPanel, {
    props: { canWrite: true },
    global: { stubs: { ImportFilesDropzone: DropzoneStub } },
  })
  wrapper.findComponent(DropzoneStub).vm.$emit('update:files', [new File(['<x/>'], 'jmhz-01.xml', { type: 'text/xml' })])
  await flushPromises()
  await wrapper.get('[data-testid="registration-preview"]').trigger('click')
  // Soubory se čtou přes FileReader — náhled dorazí až po skutečném čtení.
  await vi.waitFor(() => expect(wrapper.find('[data-testid="registration-confirm"]').exists()).toBe(true))
  return wrapper
}

async function applyAll(wrapper: Awaited<ReturnType<typeof mountWithPreview>>) {
  await wrapper.get('[data-testid="registration-confirm"]').setValue(true)
  await wrapper.get('[data-testid="registration-apply"]').trigger('click')
  await vi.waitFor(() => expect(wrapper.find('[data-testid="registration-result"]').exists()).toBe(true))
  await flushPromises()
}

describe('import JMHZ — souběžný vztah, druh vztahu, odhadnutý nástup, neúplný import', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(preview([concurrentForm, derived]))
  })

  it('náhled ukáže souběžný vztah, volbu druhu vztahu, odhadnutý nástup a částečný měsíc', async () => {
    const wrapper = await mountWithPreview()

    expect(wrapper.find('[data-testid="registration-concurrent-hint"]').text())
      .toContain('payroll_imports.registration.import_review.concurrent_employment_hint')
    expect(wrapper.find('[data-testid="registration-start-estimated"]').exists()).toBe(true)
    const select = wrapper.get('[data-testid="registration-relation-choice"]')
    expect(select.findAll('option').map(option => option.attributes('value'))).toEqual(['dpp', 'dpc'])
    expect(wrapper.text()).toContain('payroll_imports.registration.takeover.month_status.partial')
    expect((wrapper.get('[data-testid="registration-apply-takeover"]').element as HTMLInputElement).disabled,
      'Částečně převzatý měsíc jde převzít.').toBe(false)
  })

  it('zvolený druh vztahu přepočítá náhled a odejde i při použití', async () => {
    const wrapper = await mountWithPreview()
    await wrapper.get('[data-testid="registration-relation-choice"]').setValue('dpc')
    await vi.waitFor(() => expect(m.preview).toHaveBeenCalledTimes(2))
    await flushPromises()

    expect(m.preview).toHaveBeenLastCalledWith(expect.objectContaining({
      relation_types: [{ key: derived.key, relation_type: 'dpc' }],
    }))

    m.apply.mockResolvedValue({
      results: [], summary: { applied: 0, failed: 0, skipped: 0 }, opening_balances: { saved: 0, skipped: [] },
      averages: { created: 0, approved: 0, skipped: [] }, takeover: null, change_checklist: { completed: 0, failed: [] },
      outcome: 'complete', unresolved: [],
    } satisfies RegistrationApplyResult)
    await applyAll(wrapper)

    expect(m.apply).toHaveBeenCalledWith(expect.objectContaining({
      relation_types: [{ key: derived.key, relation_type: 'dpc' }],
    }))
  })

  it('neúplný import ohlásí formuláře bez vztahu s proklikem a odhadnutý nástup s odkazem na kartu', async () => {
    const wrapper = await mountWithPreview()
    m.apply.mockResolvedValue({
      results: [{
        key: derived.key, status: 'applied', message: null, employee_id: 50, employment_id: 7,
        operations: ['employment_created'], start_estimated: true,
      }],
      summary: { applied: 1, failed: 0, skipped: 0 },
      opening_balances: { saved: 0, skipped: [] },
      averages: { created: 0, approved: 0, skipped: [] },
      takeover: null,
      change_checklist: { completed: 0, failed: [] },
      outcome: 'incomplete',
      unresolved: [{
        key: concurrentForm.key, file: 'jmhz-01.xml', period: '2026-01', label: 'Syntetická osoba',
        reason: 'Pracovní vztah formuláře v evidenci není — nezaložila ho žádná věta dávky.',
      }],
    } satisfies RegistrationApplyResult)
    await applyAll(wrapper)

    const alert = wrapper.get('[data-testid="registration-result-incomplete"]')
    expect(alert.text()).toContain('payroll_imports.registration.import_review.incomplete_title')
    expect(alert.text()).toContain('nezaložila ho žádná věta dávky')
    expect(alert.find('button').text()).toContain('payroll_imports.registration.import_review.fix_in_preview')
    expect(m.warning).toHaveBeenCalledWith(expect.stringContaining('import_review.incomplete_toast'))
    expect(m.success).not.toHaveBeenCalled()
    const link = wrapper.get('[data-testid="registration-result-start-estimated"] a')
    expect(JSON.parse(link.attributes('data-to') ?? '{}')).toEqual({ name: 'payroll-person', params: { id: 50 } })
  })
})
