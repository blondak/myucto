import { ref } from 'vue'

/** Jeden řádek přehledu v potvrzení: co → komu → jakou cestou. */
export interface ProductionSendConfirmItem {
  what: string
  to: string
  channel: string
}

export interface ProductionSendConfirmRequest {
  message: string
  items?: ProductionSendConfirmItem[]
}

export function useProductionSendConfirm() {
  const request = ref<ProductionSendConfirmRequest | null>(null)
  let resolver: ((confirmed: boolean) => void) | null = null

  function confirmProductionSend(
    environment: string,
    message: string,
    items?: ProductionSendConfirmItem[],
  ): Promise<boolean> {
    if (environment !== 'production') return Promise.resolve(true)
    resolver?.(false)
    request.value = items === undefined ? { message } : { message, items }

    return new Promise(resolve => {
      resolver = resolve
    })
  }

  function settle(confirmed: boolean): void {
    const resolve = resolver
    resolver = null
    request.value = null
    resolve?.(confirmed)
  }

  return { request, confirmProductionSend, settle }
}
