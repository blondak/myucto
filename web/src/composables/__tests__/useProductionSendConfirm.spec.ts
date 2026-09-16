import { describe, expect, it } from 'vitest'
import { useProductionSendConfirm } from '@/composables/useProductionSendConfirm'

describe('useProductionSendConfirm', () => {
  it('nezdržuje odeslání v testovacím prostředí', async () => {
    const { request, confirmProductionSend } = useProductionSendConfirm()

    await expect(confirmProductionSend('test', 'Odeslat?')).resolves.toBe(true)
    expect(request.value).toBeNull()
  })

  it('u ostrého prostředí čeká na potvrzení', async () => {
    const { request, confirmProductionSend, settle } = useProductionSendConfirm()

    const pending = confirmProductionSend('production', 'Odeslat na ČSSZ?')
    expect(request.value?.message).toBe('Odeslat na ČSSZ?')

    settle(true)
    await expect(pending).resolves.toBe(true)
    expect(request.value).toBeNull()
  })

  it('zamítnutí odeslání vrátí false', async () => {
    const { confirmProductionSend, settle } = useProductionSendConfirm()

    const pending = confirmProductionSend('production', 'Odeslat na ČSSZ?')
    settle(false)

    await expect(pending).resolves.toBe(false)
  })

  it('druhý dotaz zruší ten předchozí, aby nezůstal viset', async () => {
    const { confirmProductionSend, settle } = useProductionSendConfirm()

    const first = confirmProductionSend('production', 'První?')
    const second = confirmProductionSend('production', 'Druhý?')

    await expect(first).resolves.toBe(false)
    settle(true)
    await expect(second).resolves.toBe(true)
  })
})
