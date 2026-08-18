import { api } from './client'
import type { PayrollBenefitExemptionBasket } from './payroll'

// ── Roční koše osvobození benefitů ──────────────────────────────────────────
// Čtecí přehled čerpání košů podle § 6 odst. 9 ZDP za celou firmu. Čísla jsou
// ZMRAZENÁ ze schválení mzdových vstupů — klient je nedopočítává a nesmí je
// dopočítávat ani zobrazením: jediné, co se odsud počítá, je procento pro
// ukazatel, a to čistě vizuálně.

// Koš je tentýž typ, jaký nese katalog složek (`payroll.ts`) — vlastní kopie
// unionu by se s ním rozešla, jakmile zákon přidá další bod § 6 odst. 9.
export const BENEFIT_EXEMPTION_BASKETS: PayrollBenefitExemptionBasket[] = [
  'non_cash_health',
  'non_cash_leisure',
  'old_age_savings',
]

/**
 * Stav řádku. `exceeded` vychází ze zmrazené nadlimitní části, takže platí
 * i bez dnešního limitu; `limit_unavailable` a `incomplete` jsou přiznání, že
 * podklad chybí — ne nula.
 */
export type BenefitBasketStatus =
  | 'ok'
  | 'approaching'
  | 'exceeded'
  | 'incomplete'
  | 'limit_unavailable'

export interface BenefitBasketUsage {
  employee_id: number
  /**
   * Účinné jméno osoby. Citlivý identifikátor to podle § 58.17 manuálu není;
   * rodné číslo, adresa ani účet se odsud nevydávají.
   */
  employee_name: string
  basket: PayrollBenefitExemptionBasket
  statute: string
  /** Roční limit koše z rulesetu; `null` = ruleset ho pro ten rok netvrdí. */
  limit_minor: number | null
  /** Úhrn HRUBÝCH plnění, kterými se koš čerpá. */
  used_minor: number
  /** Zmrazený součet osvobozených částí. */
  exempt_minor: number
  /** Zmrazený součet nadlimitních částí — to, co se už zdanilo. */
  taxable_minor: number
  /** `null`, když limit není znám. Nula znamená vyčerpáno, ne „nevíme". */
  remaining_minor: number | null
  input_count: number
  /** Kolik vstupů nemá zmrazený rozpad — chybějící podklad se nedopočítává. */
  unfrozen_count: number
  status: BenefitBasketStatus
  /** Zmrazený rozpad se rozešel s dnešním limitem (limit se v rulesetu změnil). */
  split_drift: boolean
}

export interface BenefitBasketOverview {
  items: BenefitBasketUsage[]
  total: number
  limit: number
  offset: number
  year: number
  /** Roky, ve kterých firma vůbec něco do koše načerpala. */
  years: number[]
}

export interface BenefitBasketQuery {
  year: number
  basket?: PayrollBenefitExemptionBasket | ''
  q?: string
  limit?: number
  offset?: number
}

export const payrollBenefitBasketsApi = {
  overview: (query: BenefitBasketQuery) =>
    api
      .get<BenefitBasketOverview>('/payroll/benefit-baskets', {
        params: {
          year: String(query.year),
          ...(query.basket ? { basket: query.basket } : {}),
          ...(query.q ? { q: query.q } : {}),
          ...(query.limit === undefined ? {} : { limit: String(query.limit) }),
          ...(query.offset ? { offset: String(query.offset) } : {}),
        },
      })
      .then(r => r.data),
}
