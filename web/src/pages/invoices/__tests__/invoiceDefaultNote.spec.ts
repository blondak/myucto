import { describe, expect, it } from 'vitest'
import {
  defaultNoteFor,
  noteAfterLanguageChange,
  type DefaultNoteSettings,
} from '@/pages/invoices/invoiceDefaultNote'

const CS = 'Zboží zůstává až do úplného uhrazení majetkem dodavatele.'
const EN = 'The goods remain the property of the supplier until paid in full.'

function settings(enabled = true): DefaultNoteSettings {
  return {
    default_note_below_items_enabled: enabled,
    default_note_below_items_cs: CS,
    default_note_below_items_en: EN,
  }
}

describe('výchozí poznámka pod položkami', () => {
  it('vypnuté nastavení nepředvyplní nic ani s vyplněnými texty', () => {
    expect(defaultNoteFor(settings(false), 'cs')).toBe('')
    expect(defaultNoteFor(settings(false), 'en')).toBe('')
    expect(defaultNoteFor(undefined, 'cs')).toBe('')
  })

  it('vybírá text podle jazyka dokladu', () => {
    expect(defaultNoteFor(settings(), 'cs')).toBe(CS)
    expect(defaultNoteFor(settings(), 'en')).toBe(EN)
  })

  it('chybějící nebo bílý text jazyka se chová jako nevyplněný', () => {
    expect(defaultNoteFor({ ...settings(), default_note_below_items_en: '   ' }, 'en')).toBe('')
    expect(defaultNoteFor({ ...settings(), default_note_below_items_en: null }, 'en')).toBe('')
  })

  it('přepne text při změně jazyka, dokud ho uživatel nesáhl', () => {
    expect(noteAfterLanguageChange('', settings(), 'cs', 'en')).toBe(EN)
    expect(noteAfterLanguageChange(CS, settings(), 'cs', 'en')).toBe(EN)
    // Okrajové mezery z textarey nesmí z nezměněného textu udělat ruční úpravu.
    expect(noteAfterLanguageChange(`  ${CS}  `, settings(), 'cs', 'en')).toBe(EN)
  })

  it('NIKDY nepřepíše text, který uživatel ručně změnil', () => {
    const rucni = `${CS} Splatnost na místě.`
    expect(noteAfterLanguageChange(rucni, settings(), 'cs', 'en')).toBe(rucni)
    expect(noteAfterLanguageChange('Vlastní text', settings(), 'cs', 'en')).toBe('Vlastní text')
  })

  it('při vypnutém nastavení nechá pole beze změny', () => {
    expect(noteAfterLanguageChange('', settings(false), 'cs', 'en')).toBe('')
    expect(noteAfterLanguageChange('Vlastní text', settings(false), 'cs', 'en')).toBe('Vlastní text')
  })

  it('vyprázdní pole, když nový jazyk text nemá a starý ho měl', () => {
    const jenCesky: DefaultNoteSettings = {
      default_note_below_items_enabled: true,
      default_note_below_items_cs: CS,
      default_note_below_items_en: null,
    }
    expect(noteAfterLanguageChange(CS, jenCesky, 'cs', 'en')).toBe('')
    // Ručně psaný text zůstává i tady.
    expect(noteAfterLanguageChange('Vlastní text', jenCesky, 'cs', 'en')).toBe('Vlastní text')
  })
})
