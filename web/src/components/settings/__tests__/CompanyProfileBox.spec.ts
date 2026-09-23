import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { CompanyProfileImportResult } from '@/api/companyProfile'

/**
 * Profil firmy v Nastavení a v průvodcích převodu.
 *
 * Hlídá:
 *   1. nahrání souboru nejdřív jen ukáže náhled (dry_run = true), nic nezapíše,
 *   2. ostré nahrání jde až po potvrzení a pošle tentýž profil s dry_run = false,
 *   3. soubor, který není JSON, se na server vůbec nepošle,
 *   4. chyba serveru s označenou sekcí se ukáže u té sekce.
 */

const m = vi.hoisted(() => ({
  exportProfile: vi.fn(),
  importProfile: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/companyProfile', async (orig) => ({
  ...(await orig<typeof import('@/api/companyProfile')>()),
  companyProfileApi: { export: m.exportProfile, import: m.importProfile },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))

import CompanyProfileBox from '../CompanyProfileBox.vue'

const PROFILE = { format: 'myucto.company-profile', version: 1, company: { ic: '12345678' }, sections: { company: { stock_enabled: true } } }

function result(dryRun: boolean, changed = 1): CompanyProfileImportResult {
  return {
    dry_run: dryRun,
    changed,
    warnings: ['Profil je z jiné firmy.'],
    sections: {
      company: { created: 0, updated: changed, unchanged: 3, removed: 0, changes: ['stock_enabled: 0 → 1'], warnings: [] },
    },
  }
}

async function chooseFile(wrapper: ReturnType<typeof mount>, content: string) {
  const input = wrapper.get('[data-test="company-profile-file"]')
  const file = new File([content], 'profil.json', { type: 'application/json' })
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('CompanyProfileBox', () => {
  it('shows a dry-run preview first and applies only after confirmation', async () => {
    m.importProfile.mockResolvedValueOnce(result(true)).mockResolvedValueOnce(result(false))
    const wrapper = mount(CompanyProfileBox)

    await chooseFile(wrapper, JSON.stringify(PROFILE))
    expect(m.importProfile).toHaveBeenCalledTimes(1)
    expect(m.importProfile).toHaveBeenLastCalledWith(PROFILE, true)
    expect(wrapper.get('[data-test="company-profile-report"]').text()).toContain('stock_enabled: 0 → 1')
    expect(wrapper.text()).toContain('Profil je z jiné firmy.')

    await wrapper.get('[data-test="company-profile-apply"]').trigger('click')
    await flushPromises()
    expect(m.importProfile).toHaveBeenCalledTimes(2)
    expect(m.importProfile).toHaveBeenLastCalledWith(PROFILE, false)
    expect(m.toastSuccess).toHaveBeenCalled()
    expect(wrapper.find('[data-test="company-profile-apply"]').exists()).toBe(false)
  })

  it('does not send a file that is not JSON', async () => {
    const wrapper = mount(CompanyProfileBox)
    await chooseFile(wrapper, 'tohle není json')
    expect(m.importProfile).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="company-profile-error"]').text()).toBe('company_profile.invalid_json')
  })

  it('names the section of a server-side error', async () => {
    m.importProfile.mockRejectedValueOnce({ response: { data: { error: { code: 'validation_failed', message: 'Řádek neexistuje.', section: 'statement_overrides' } } } })
    const wrapper = mount(CompanyProfileBox)
    await chooseFile(wrapper, JSON.stringify(PROFILE))
    const text = wrapper.get('[data-test="company-profile-error"]').text()
    expect(text).toContain('company_profile.error_in_section')
    expect(text).toContain('company_profile.section.statement_overrides')
    expect(text).toContain('Řádek neexistuje.')
  })

  it('disables apply when the preview has nothing to change', async () => {
    m.importProfile.mockResolvedValueOnce(result(true, 0))
    const wrapper = mount(CompanyProfileBox)
    await chooseFile(wrapper, JSON.stringify(PROFILE))
    expect(wrapper.text()).toContain('company_profile.no_changes')
    expect(wrapper.get('[data-test="company-profile-apply"]').attributes('disabled')).toBeDefined()
  })
})
