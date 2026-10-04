import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))

const listMock = vi.fn()
vi.mock('@/api/integrations', () => ({
  integrationsApi: { listOllamaModels: (...a: unknown[]) => listMock(...a) },
}))

import OllamaProviderFields from '@/components/admin/OllamaProviderFields.vue'

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>(r => { resolve = r })
  return { promise, resolve }
}
const m = (name: string) => ({ name, size: 1, vision: true, thinking: null })

describe('OllamaProviderFields', () => {
  it('zahodí odpověď pro starou adresu a ponechá model při úvodním načtení', async () => {
    const first = deferred<{ models: ReturnType<typeof m>[] }>()
    listMock.mockReturnValueOnce(first.promise)
    const w = mount(OllamaProviderFields, {
      props: { baseUrl: 'http://a:11434', model: 'saved', apiKey: '', hasApiKey: false,
        'onUpdate:baseUrl': (v: string) => w.setProps({ baseUrl: v }),
        'onUpdate:model': (v: string) => w.setProps({ model: v }) },
    })
    expect(w.props('model')).toBe('saved')

    await w.setProps({ baseUrl: 'http://b:11434' })
    expect(w.props('model')).toBe('')
    first.resolve({ models: [m('stale-x')] })
    await flushPromises()
    expect(w.find('[data-test="ollama-model"]').exists()).toBe(false)

    listMock.mockResolvedValueOnce({ models: [m('new')] })
    await w.find('[data-test="ollama-load-models"]').trigger('click')
    await flushPromises()
    const opts = w.findAll('option').map(o => o.text())
    expect(opts.some(o => o.includes('new'))).toBe(true)
    expect(opts.some(o => o.includes('stale-x'))).toBe(false)
  })
})
