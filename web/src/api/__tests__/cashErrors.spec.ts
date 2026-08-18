import { describe, expect, it } from 'vitest'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { cashErrorCode, cashErrorMessage, cashWarningMessage } from '@/api/cashErrors'

/** Minimální náhrada `t()`: vrátí překlad z cs.json, nebo klíč (jako vue-i18n). */
function translator(dict: Record<string, any>) {
  return (key: string): string => {
    const value = key.split('.').reduce<any>((node, part) => (node == null ? undefined : node[part]), dict)
    return typeof value === 'string' ? value : key
  }
}
const t = translator(cs as Record<string, any>)
const tEn = translator(en as Record<string, any>)

function apiError(code: string | undefined, message?: string) {
  return { response: { data: { error: { code, message } } } }
}

describe('cashErrorMessage', () => {
  it('u catch-all kódu `validation` upřednostní konkrétní hlášku serveru', () => {
    const e = apiError('cash.error.validation', 'Vazba na fakturu je povolena jen u úhrady FV/PF.')
    expect(cashErrorMessage(e, t)).toBe('Vazba na fakturu je povolena jen u úhrady FV/PF.')
    expect(cashErrorMessage(e, t)).not.toBe(t('cash.error.validation'))
  })

  it('bez zprávy serveru u `validation` spadne na obecný překlad', () => {
    expect(cashErrorMessage(apiError('cash.error.validation'), t)).toBe(t('cash.error.validation'))
  })

  it('přeloží kód s prefixem i bez prefixu na tentýž text', () => {
    const expected = t('cash.error.doc_not_draft')
    expect(cashErrorMessage(apiError('cash.error.doc_not_draft'), t)).toBe(expected)
    expect(cashErrorMessage(apiError('doc_not_draft'), t)).toBe(expected)
  })

  it('fallback na accounting.error.* staví klíč ze ZKRÁCENÉHO kódu (dřív mrtvá větev)', () => {
    const dict = { accounting: { error: { entry_reversed: 'Zápis už je stornovaný.' } }, common: { error: 'Chyba' } }
    const local = translator(dict)
    expect(cashErrorMessage(apiError('cash.error.entry_reversed'), local)).toBe('Zápis už je stornovaný.')
  })

  it('neznámý kód bez překladu vrátí zprávu serveru', () => {
    expect(cashErrorMessage(apiError('cash.error.zcela_neznamy', 'Konkrétní důvod.'), t)).toBe('Konkrétní důvod.')
  })

  it('chyba bez kódu i bez zprávy skončí na common.error', () => {
    expect(cashErrorMessage(new Error('network'), t)).toBe(t('common.error'))
  })

  it.each(['period_not_open', 'date_locked', 'ddkp_not_payable', 'has_dependencies'])(
    'kód %s má překlad v cs i en (dřív se vypisoval syrový český text serveru)',
    (code) => {
      expect(t(`cash.error.${code}`)).not.toBe(`cash.error.${code}`)
      expect(tEn(`cash.error.${code}`)).not.toBe(`cash.error.${code}`)
      expect(cashErrorMessage(apiError(`cash.error.${code}`, 'syrový vývojářský text'), tEn))
        .toBe(tEn(`cash.error.${code}`))
    },
  )
})

describe('cashErrorCode', () => {
  it('vrací kód bez prefixu', () => {
    expect(cashErrorCode(apiError('cash.error.register_has_documents'))).toBe('register_has_documents')
    expect(cashErrorCode(apiError('register_has_documents'))).toBe('register_has_documents')
    expect(cashErrorCode({})).toBe('')
  })
})

describe('cashWarningMessage', () => {
  it('zvládne plný klíč i holý kód', () => {
    expect(cashWarningMessage('cash.warning.negative_balance', t)).toBe(t('cash.warning.negative_balance'))
    expect(cashWarningMessage('negative_balance', t)).toBe(t('cash.warning.negative_balance'))
  })

  it('nepřeložitelné varování vrátí tak, jak přišlo', () => {
    expect(cashWarningMessage('neco_noveho', t)).toBe('neco_noveho')
  })
})

describe('validační hlášky formuláře', () => {
  it.each(['register', 'amount', 'description', 'name', 'account', 'reason'])(
    'cash.validation.%s je věta, ne popisek pole',
    (key) => {
      const value = t(`cash.validation.${key}`)
      expect(value).not.toBe(`cash.validation.${key}`)
      expect(tEn(`cash.validation.${key}`)).not.toBe(`cash.validation.${key}`)
      // Popisek pole je jedno slovo bez tečky („Částka"); hláška je věta.
      expect(value.length).toBeGreaterThan(12)
    },
  )

  it('hlášky se neshodují s popisky sloupců, které se do boxu plnily dřív', () => {
    expect(t('cash.validation.amount')).not.toBe(t('cash.col.amount'))
    expect(t('cash.validation.description')).not.toBe(t('cash.col.description'))
    expect(t('cash.validation.name')).not.toBe(t('cash.register_name'))
    expect(t('cash.validation.reason')).not.toBe(t('cash.reverse.reason'))
  })
})
