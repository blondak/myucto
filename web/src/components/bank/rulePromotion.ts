import type { BankPostingRule } from '@/api/bankPosting'

type Translate = (key: string, params?: Record<string, unknown>) => string

type PromotionSubject = Pick<BankPostingRule, 'name' | 'hit_count' | 'approved_streak' | 'amount_min' | 'amount_max'>
  & { promotion_candidate?: boolean }

/**
 * Text potvrzení povýšení na automatiku. Kandidát (5 potvrzení beze změny) dostane krátké
 * potvrzení; ostatní pravidla výslovné upozornění, že jde o vynucené povýšení bez historie.
 * Chybějící rozsah částky se doplní poznámkou, protože ručně povýšené pravidlo ho
 * nepotřebuje a zaúčtuje jakoukoli částku do stropu automatiky.
 */
export function promoteConfirmMessage(rule: PromotionSubject, t: Translate): string {
  const base = rule.promotion_candidate
    ? t('automation.rules.promote_confirm', { name: rule.name })
    : t('automation.rules.promote_forced_confirm', {
      name: rule.name,
      hits: rule.hit_count,
      streak: Math.min(rule.approved_streak, 5),
    })
  const noBand = rule.amount_min == null || rule.amount_max == null
  return noBand ? `${base}\n\n${t('automation.rules.promote_no_band_note')}` : base
}
