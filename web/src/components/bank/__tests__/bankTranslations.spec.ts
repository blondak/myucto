import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { cs, en, i18nMessages } from '../../../../tests/locales'

describe('bank onboarding translations', () => {
  for (const [locale, messages] of Object.entries({ cs, en })) {
    it(`${locale} compiles every bank onboarding message`, () => {
      const i18n = createI18n({ legacy: false, locale, messages: { [locale]: i18nMessages[locale as 'cs' | 'en'] } })
      for (const namespace of ['kb_plus', 'creditas_bank', 'bank_connection'] as const) {
        for (const [key, value] of Object.entries(messages[namespace])) {
          if (typeof value !== 'string') continue
          expect(() => i18n.global.t(`${namespace}.${key}`), `${namespace}.${key}`).not.toThrow()
        }
      }
    })
  }
})
