/**
 * Výchozí text poznámky pod položkami na nově zakládaném dokladu (#79).
 *
 * Zrcadlo backendového `MyInvoice\Service\Invoice\DefaultInvoiceNote` — editor
 * předvyplňuje v prohlížeči (aby text šel rovnou přepsat), API doplňuje tentýž
 * text při POST bez klíče `note_below_items`. Obě strany proto musí vracet totéž.
 *
 * Text se drží podle JAZYKA dokladu (`invoices.language`), NE podle měny: česká
 * firma běžně fakturuje v eurech česky.
 */

/** Jazyk dokladu — `invoices.language` je ENUM('cs','en'). */
export type InvoiceLanguage = 'cs' | 'en'

/**
 * Strop délky výchozího textu v nastavení firmy. Zrcadlí
 * `SettingsAction::DEFAULT_NOTE_MAX_LENGTH` — server delší text odmítne 400,
 * takže formulář musí useknout dřív, ať uživatel nepřijde o uložení.
 */
export const DEFAULT_NOTE_MAX_LENGTH = 5000

/** Podmnožina nastavení firmy, kterou předvyplnění potřebuje (z `/me`). */
export interface DefaultNoteSettings {
  default_note_below_items_enabled?: boolean
  default_note_below_items_cs?: string | null
  default_note_below_items_en?: string | null
}

/**
 * Výchozí poznámka pro daný jazyk dokladu, nebo prázdný řetězec.
 *
 * Vypnutý přepínač znamená doslova dnešní chování: prázdno, i kdyby byly texty
 * v nastavení vyplněné.
 */
export function defaultNoteFor(
  settings: DefaultNoteSettings | null | undefined,
  language: InvoiceLanguage,
): string {
  if (!settings?.default_note_below_items_enabled) return ''
  const text = language === 'en'
    ? settings.default_note_below_items_en
    : settings.default_note_below_items_cs
  return (text ?? '').trim()
}

/**
 * Co má být v poli po přepnutí jazyka rozpracovaného dokladu.
 *
 * ROZHODNUTÍ: text se přepne JEN tehdy, když je pole prázdné nebo se doslova
 * rovná výchozímu textu PŮVODNÍHO jazyka — tedy když ho uživatel nesáhl. Ruční
 * úprava má vždycky přednost, protože rozepsaná dohoda o ceně je práce, kterou
 * nesmí smazat přepnutí jazykové mutace. Druhá varianta („nepřepínat vůbec") by
 * u nejčastějšího případu — vyberu klienta, ten přepne doklad na angličtinu —
 * nechala v dokladu český text, kterého by si nikdo nevšiml.
 *
 * Důsledek, který je taky záměr: když nový jazyk výchozí text nemá, pole se
 * vyprázdní. Jinak by v anglickém dokladu zůstala česká věta.
 */
export function noteAfterLanguageChange(
  current: string,
  settings: DefaultNoteSettings | null | undefined,
  from: InvoiceLanguage,
  to: InvoiceLanguage,
): string {
  if (!settings?.default_note_below_items_enabled) return current
  const trimmed = current.trim()
  if (trimmed !== '' && trimmed !== defaultNoteFor(settings, from)) return current
  return defaultNoteFor(settings, to)
}
