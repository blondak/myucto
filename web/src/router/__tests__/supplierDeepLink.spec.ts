import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
vi.mock('@/api/client', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))
const switchTo = vi.fn().mockResolvedValue(undefined)
vi.mock('@/composables/useSupplierSwitch', () => ({ useSupplierSwitch: () => ({ switchTo }) }))

import { switchSupplierForDeepLink } from '../supplierDeepLink'
import { useSupplierStore } from '@/stores/supplier'

function route(name: string, params: Record<string, string> = {}, query: Record<string, string> = {}) {
  const path = `/${name}`
  return { name, params, query, fullPath: path + (query.entry_id ? `?entry_id=${query.entry_id}` : '') } as never
}

describe('odkaz na doklad jiné firmy', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    get.mockReset()
    switchTo.mockClear()
    useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as never, 1)
  })

  it('přepne na firmu, které doklad patří, a otevře tentýž doklad', async () => {
    get.mockResolvedValue({ data: { supplier_id: 2 } })

    await expect(switchSupplierForDeepLink(route('invoice-detail', { id: '2694' }))).resolves.toBe(true)

    expect(get).toHaveBeenCalledWith('/locate/invoice/2694')
    expect(switchTo).toHaveBeenCalledWith(2, '/invoice-detail')
  })

  it('u deníku bere id zápisu z query', async () => {
    get.mockResolvedValue({ data: { supplier_id: 2 } })

    await switchSupplierForDeepLink(route('accounting-journal', {}, { entry_id: '35' }))

    expect(get).toHaveBeenCalledWith('/locate/journal_entry/35')
  })

  it('nepřepíná, když doklad patří aktuální firmě', async () => {
    get.mockResolvedValue({ data: { supplier_id: 1 } })

    await expect(switchSupplierForDeepLink(route('invoice-detail', { id: '5' }))).resolves.toBe(false)
    expect(switchTo).not.toHaveBeenCalled()
  })

  it('nepřepíná, když doklad nenajde (cizí firma bez práv, neexistuje)', async () => {
    get.mockRejectedValue({ response: { status: 404 } })

    await expect(switchSupplierForDeepLink(route('invoice-detail', { id: '5' }))).resolves.toBe(false)
    expect(switchTo).not.toHaveBeenCalled()
  })

  it('s jedinou firmou se na vlastníka vůbec neptá', async () => {
    useSupplierStore().setAvailable([{ id: 1 }] as never, 1)

    await expect(switchSupplierForDeepLink(route('invoice-detail', { id: '5' }))).resolves.toBe(false)
    expect(get).not.toHaveBeenCalled()
  })

  it('routy bez dokladu ignoruje', async () => {
    await expect(switchSupplierForDeepLink(route('invoices'))).resolves.toBe(false)
    expect(get).not.toHaveBeenCalled()
  })
})
