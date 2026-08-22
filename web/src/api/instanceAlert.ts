/**
 * Blokující stav instalace — podklad pro červenou linku nad aplikací (H-31).
 *
 * Linka je jiná věc než bannery vedle ní: ty upozorňují, tahle oznamuje, že
 * něco NEJDE. Proto nemá křížek ani „rozumím" a zmizí až tím, že se závada
 * odstraní. Rozhodování o ní je schválně čistá funkce bez Vue a bez axiosu —
 * jde o pravidlo, které se má dát otestovat bez namountované komponenty.
 *
 * ⚠️ Tři pravidla, na kterých to stojí:
 *
 *  1. **Self-hosted linku nikdy nedostane.** Kvótu tam nikdo nenastavil a
 *     licence v degradovaném stavu je legitimní provoz MIT jádra, ne dluh vůči
 *     nám. `managed !== true` = konec, žádný jiný vstup se ani nečte.
 *  2. **„Nevím" se nikdy nečte jako „v pořádku".** Vstupy sem chodí jako
 *     poslední ZNÁMÝ stav (viz `storageQuota.isCriticallyExhausted`), takže
 *     spadlý požadavek linku neschová.
 *  3. **Varování na 90 % sem nepatří.** To zůstává žlutým, odklikatelným
 *     pruhem — kdyby se z každého docházejícího místa stala červená linka,
 *     přestane se rozlišovat „doplňte" od „zastaveno".
 */

/** Proč linka svítí. Víc důvodů najednou je legitimní stav, ne chyba. */
export type InstanceAlertReason = 'storage_exhausted' | 'unpaid'

export interface InstanceAlertInput {
  /** `app.managed` — spravovaný (hostovaný) provoz. */
  managed: boolean
  /** Poslední známý blokující stav místa (zápisy odmítané s 507). */
  storageExhausted: boolean
  /**
   * Stav licence z /auth/me. `degraded` = token propadl / chybí,
   * `trial_expired` = doběhl trial. Ve spravovaném provozu obojí znamená, že
   * za instalaci není zaplaceno — self-hosted výklad („běžím na MIT jádru")
   * tady neplatí, protože hostovaný provoz nikdo zadarmo nedělá.
   */
  licenseState?: string | null
  /**
   * Stav předplatného z licenčního serveru, když ho obrazovka zná
   * (`/api/license/status` → `instance.billing.subscription_state`).
   * `past_due` hlásí server DŘÍV, než licence propadne.
   */
  subscriptionState?: string | null
}

/** Stavy licence, které ve spravovaném provozu znamenají neuhrazeno. */
const UNPAID_LICENSE_STATES = ['degraded', 'trial_expired']

/** Stavy předplatného, které licenční server hlásí jako nezaplacené. */
const UNPAID_SUBSCRIPTION_STATES = ['past_due', 'expired']

export function resolveInstanceAlerts(input: InstanceAlertInput): InstanceAlertReason[] {
  // Self-hosted: konec dřív, než se cokoli dalšího vyhodnotí.
  if (!input.managed) return []

  const reasons: InstanceAlertReason[] = []
  if (input.storageExhausted) reasons.push('storage_exhausted')

  const unpaid = UNPAID_LICENSE_STATES.includes(input.licenseState ?? '')
    || UNPAID_SUBSCRIPTION_STATES.includes(input.subscriptionState ?? '')
  if (unpaid) reasons.push('unpaid')

  return reasons
}
