import { createI18n } from 'vue-i18n'
import cs from './cs.json'

type Locale = 'cs' | 'en'

const initialLocale: Locale = (localStorage.getItem('locale') as Locale) || 'cs'

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'cs',
  messages: { cs } as Record<Locale, typeof cs>,
})

const loadedLocales = new Set<Locale>(['cs'])

// en.json (~300 kB) se nenahrává staticky do hlavního bundlu — dotáhne se dynamickým
// importem jen při přepnutí na angličtinu, nebo při startu, pokud si ji uživatel zvolil dřív.
export async function ensureLocaleLoaded(locale: Locale): Promise<void> {
  if (loadedLocales.has(locale)) return
  const messages = await import(`./${locale}.json`)
  i18n.global.setLocaleMessage(locale, messages.default)
  loadedLocales.add(locale)
}

export async function ensureInitialLocaleReady(): Promise<void> {
  await ensureLocaleLoaded(initialLocale)
}
