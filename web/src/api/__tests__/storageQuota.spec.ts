import { describe, it, expect, beforeEach } from 'vitest'
import { formatQuotaBytes, readStorageQuotaHeaders, storageQuota } from '../storageQuota'

// H-10 — banner o docházejícím místě. Hlídá se hlavně to, že se NEZMĚŘENÁ
// spotřeba nesmí ukázat jako „0 %, vše v pořádku": backend v takovém případě
// hlavičky vůbec neposílá a frontend musí zůstat zticha, ne dopočítat nulu.

describe('storageQuota', () => {
  beforeEach(() => {
    readStorageQuotaHeaders({})
  })

  it('bez hlaviček nehlásí nic (a rozhodně ne nulu)', () => {
    readStorageQuotaHeaders({})

    expect(storageQuota.state.value).toBeNull()
    expect(storageQuota.percent.value).toBeNull()
    expect(storageQuota.isWarning.value).toBe(false)
    expect(storageQuota.isExhausted.value).toBe(false)
  })

  it('prázdná hodnota procent se čte jako null, ne jako 0', () => {
    readStorageQuotaHeaders({
      'x-storage-quota-state': 'warning',
      'x-storage-quota-percent': '',
    })

    expect(storageQuota.state.value).toBe('warning')
    expect(storageQuota.percent.value).toBeNull()
  })

  it('varování na 90 %', () => {
    readStorageQuotaHeaders({
      'x-storage-quota-state': 'warning',
      'x-storage-quota-percent': '90.0',
      'x-storage-quota-used-bytes': '943718400',
      'x-storage-quota-limit-bytes': '1048576000',
    })

    expect(storageQuota.isWarning.value).toBe(true)
    expect(storageQuota.isExhausted.value).toBe(false)
    expect(storageQuota.percent.value).toBe(90)
  })

  it('vyčerpaná kvóta', () => {
    readStorageQuotaHeaders({
      'x-storage-quota-state': 'exhausted',
      'x-storage-quota-percent': '100.0',
    })

    expect(storageQuota.isExhausted.value).toBe(true)
  })

  it('neznámý stav banner nezobrazuje', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'unknown' })

    expect(storageQuota.state.value).toBeNull()
  })

  it('čte i AxiosHeaders (get())', () => {
    const headers = {
      get: (key: string) =>
        ({ 'x-storage-quota-state': 'exhausted', 'x-storage-quota-percent': '104.2' })[key],
    }
    readStorageQuotaHeaders(headers)

    expect(storageQuota.state.value).toBe('exhausted')
    expect(storageQuota.percent.value).toBe(104.2)
  })

  it('formátování zachovává null', () => {
    expect(formatQuotaBytes(null)).toBeNull()
    expect(formatQuotaBytes(0)).toBe('0 MB')
    expect(formatQuotaBytes(1048576)).toBe('1 MB')
    expect(formatQuotaBytes(2 * 1024 * 1024 * 1024)).toBe('2.0 GB')
  })
})
