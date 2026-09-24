import { ref } from 'vue'
import type LockedPeriodAckModal from '@/components/accounting/LockedPeriodAckModal.vue'

/**
 * Mazání, které server může odmítnout kvůli uzamčenému období (409 `date_locked`
 * s `can_acknowledge`). Pak se zeptá přes {@link LockedPeriodAckModal} a po potvrzení
 * zopakuje požadavek s `ack_locked`. Vrací `null`, když účetní zásah odmítl.
 */
export function useLockedPeriodAck() {
  const modal = ref<InstanceType<typeof LockedPeriodAckModal> | null>(null)

  async function run<T>(request: (ackLocked: boolean) => Promise<T>): Promise<T | null> {
    try {
      return await request(false)
    } catch (e: any) {
      const error = e?.response?.data?.error
      if (e?.response?.status !== 409 || !error?.can_acknowledge || !modal.value) throw e
      if (!(await modal.value.ask(String(error.message ?? '')))) return null
      return await request(true)
    }
  }

  return { modal, run }
}
