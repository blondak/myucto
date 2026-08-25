import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import cs from '@/i18n/cs.json'
import { startPreview, stopPreview, type PreviewScenario } from '@/api/instancePreview'
import { readStorageQuotaHeaders } from '@/api/storageQuota'
import StorageQuotaBanner from '../StorageQuotaBanner.vue'

async function mountBanner() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/hosting', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
  const wrapper = mount(StorageQuotaBanner, { global: { plugins: [router, i18n] } })
  await nextTick()

  return wrapper
}

beforeEach(() => {
  stopPreview()
  readStorageQuotaHeaders({})
})

afterEach(stopPreview)

describe('StorageQuotaBanner v náhledu', () => {
  it.each<[PreviewScenario, string]>([
    ['storage_80', 'notice'],
    ['storage_95', 'warning'],
    ['storage_100', 'exhausted'],
  ])('vykreslí scénář %s jako %s bez reálných hlaviček', async (scenario, level) => {
    startPreview(scenario)
    const wrapper = await mountBanner()

    expect(wrapper.get('[data-storage-quota-banner]').attributes('data-storage-quota-level')).toBe(level)
  })

  it('syntetický scénář má přednost před konfliktní reálnou hlavičkou', async () => {
    readStorageQuotaHeaders({
      'x-storage-quota-state': 'exhausted',
      'x-storage-quota-percent': '100',
    })
    startPreview('storage_95')

    const wrapper = await mountBanner()

    expect(wrapper.get('[data-storage-quota-banner]').attributes('data-storage-quota-level')).toBe('warning')
    expect(wrapper.text()).toContain('95')
  })

  it('zaplacené rozšíření nevyzývá k dalšímu nákupu', async () => {
    startPreview('provisioning')
    const wrapper = await mountBanner()

    expect(wrapper.text()).toContain('Rozšíření na 22 GB je zaplacené')
    expect(wrapper.text()).toContain(cs.common.storage_quota.detail_cta)
    expect(wrapper.text()).not.toContain(cs.common.storage_quota.expand_cta)
  })
})
