import { onBeforeUnmount, ref } from 'vue'
import { dataBoxApi, type MobileKeyReceiptSession, type ReceiptBatchResult } from '@/api/dataBox'

/**
 * Prodlevy mezi pokusy o dotažení doručenek po odeslání (ms). Pojišťovna
 * zprávu obvykle převezme během desítek sekund; součet (~3,5 min) se vejde
 * do pěti minut, po které server relaci drží.
 */
export const RECEIPT_FOLLOW_UP_DELAYS = [8_000, 15_000, 30_000, 45_000, 60_000, 60_000]

/**
 * Po odeslání Mobilním klíčem dotáhne doručenky odeslaných zpráv v TÉŽE
 * relaci, kterou účetní potvrdila — bez dalšího potvrzení v mobilu.
 *
 * Nikdy nezakládá nové přihlášení: když relace vyprší nebo ji server
 * ukončí, polling skončí a zbývá ruční „Načíst doručenky". Čte se jen
 * dodání ODESLANÝCH zpráv; schránka doručených zpráv se tu nečte.
 */
export function useReceiptFollowUp(
  environment: () => string,
  onResult: (result: ReceiptBatchResult) => void | Promise<void>,
  delays: number[] = RECEIPT_FOLLOW_UP_DELAYS,
) {
  const active = ref(false)
  const lastResult = ref<ReceiptBatchResult | null>(null)
  let token = ''
  let attempt = 0
  let timer: ReturnType<typeof setTimeout> | null = null

  function clear() {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  function stop() {
    clear()
    active.value = false
    token = ''
  }

  function schedule() {
    clear()
    const delay = delays[attempt]
    if (delay === undefined) {
      // Poslední pokus relaci ukončí, ať po sobě nenechá otevřenou session.
      void poll(true)
      return
    }
    timer = setTimeout(() => { void poll(false) }, delay)
  }

  async function poll(finish: boolean) {
    if (token === '') return
    attempt += 1
    try {
      const result = await dataBoxApi.downloadReceiptsInMobileKeySession(token, environment(), finish)
      lastResult.value = result
      await onResult(result)
      if (!result.session_open || finish) {
        stop()
        return
      }
      schedule()
    } catch {
      // Vypršelá nebo ukončená relace: nic se neobnovuje samo.
      stop()
    }
  }

  function start(session: MobileKeyReceiptSession | null) {
    stop()
    if (session === null || session.session_token === '') return
    token = session.session_token
    attempt = 0
    active.value = true
    schedule()
  }

  /** Okamžitý pokus v otevřené relaci (tlačítko „Načíst doručenky"). */
  async function now(): Promise<boolean> {
    if (token === '') return false
    clear()
    await poll(false)
    return true
  }

  onBeforeUnmount(clear)

  return { active, lastResult, start, stop, now }
}
