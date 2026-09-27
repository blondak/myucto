import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Překlady pro testy bez `import cs from '@/i18n/cs.json'`: import JSONu by vue-tsc nutil
 * odvodit literální typ ze 30 tisíc řádků každého jazyka, což stálo polovinu času kontroly
 * typů celého frontendu. Testy čtou jen konkrétní klíče, typ stromu jim nic nepřináší.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type LocaleMessages = Record<string, any>

function load(locale: 'cs' | 'en'): LocaleMessages {
  return JSON.parse(readFileSync(resolve(process.cwd(), `src/i18n/${locale}.json`), 'utf8')) as LocaleMessages
}

export const cs = load('cs')
export const en = load('en')

/**
 * Totéž pro `createI18n({ messages })`: s typem `any` odvozuje vue-i18n schéma zpráv do
 * nekonečna (TS2589), s mělkým typem ne. Za běhu je to týž strom.
 */
export const i18nMessages = { cs, en } as unknown as Record<'cs' | 'en', Record<string, string>>
