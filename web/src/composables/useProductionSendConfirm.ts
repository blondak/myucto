import { ref } from 'vue'

export interface ProductionSendConfirmRequest {
  message: string
}

export function useProductionSendConfirm() {
  const request = ref<ProductionSendConfirmRequest | null>(null)
  let resolver: ((confirmed: boolean) => void) | null = null

  function confirmProductionSend(environment: string, message: string): Promise<boolean> {
    if (environment !== 'production') return Promise.resolve(true)
    resolver?.(false)
    request.value = { message }

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
