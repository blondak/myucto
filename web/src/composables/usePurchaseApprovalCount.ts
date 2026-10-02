import { ref } from 'vue'
import { purchaseApprovalsApi } from '@/api/purchaseApprovals'

/**
 * Počet dokladů čekajících na rozhodnutí přihlášeného schvalovatele. Sdílený stav
 * (badge v menu, stránka Ke schválení): po rozhodnutí na stránce se menu přepočítá
 * hned, bez čekání na další načtení rámce.
 */
const pending = ref(0)
let inFlight: Promise<void> | null = null

async function refresh(): Promise<void> {
  if (inFlight) return inFlight
  inFlight = purchaseApprovalsApi.count()
    .then((n) => { pending.value = n })
    .catch(() => { /* badge je jen doplněk, selhání nic nehlásí */ })
    .finally(() => { inFlight = null })
  return inFlight
}

function reset(): void {
  pending.value = 0
}

export function usePurchaseApprovalCount() {
  return { pending, refresh, reset, set: (n: number) => { pending.value = n } }
}
