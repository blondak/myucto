import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Zákaznický nález: „Pro import přijatých pdf dokladů jsme použili AI import, ovšem do
 * pole Datum přijetí se nám vkládá aktuální datum importu."
 *
 * Datum se počítá na serveru ({@link ImportedReceivedDatePolicy}, pokryto PHPUnitem);
 * tenhle test hlídá FORMULÁŘ — že volba v nastavení firmy existuje, nabízí obě chování,
 * opravdu se ukládá (jinak by ji uživatel klikal pořád dokola) a že nápovědy nelžou.
 */
const page = readFileSync(resolve(process.cwd(), 'src/pages/admin/Settings.vue'), 'utf8')
const settingsApi = readFileSync(resolve(process.cwd(), 'src/api/settings.ts'), 'utf8')
const cs = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/cs.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/en.json'), 'utf8'))

describe('datum přijetí u importovaných přijatých dokladů', () => {
  it('nabídne v nastavení firmy obě chování', () => {
    expect(page).toContain("t('settings.purchase_import_received_at')")
    expect(page).toContain('v-model="supplier.purchase_import_received_at"')
    expect(page).toContain('<option value="issue_date">')
    expect(page).toContain('<option value="import_date">')
  })

  it('volbu skutečně posílá do uložení nastavení', () => {
    // Payload v saveSupplier() je vypsaný ručně, takže zapomenuté pole se tiše NEULOŽÍ.
    expect(page).toMatch(/purchase_import_received_at:\s*supplier\.value\.purchase_import_received_at/)
  })

  it('výchozí je datum z dokladu, ne dnešek', () => {
    expect(page).toMatch(/purchase_import_received_at\s*\?\?\s*'issue_date'/)
    expect(settingsApi).toContain("purchase_import_received_at: 'issue_date' | 'import_date'")
  })

  it('přepnutí zpět na dnešek je pojmenované jako den importu v obou jazycích', () => {
    expect(cs.settings.purchase_import_received_at_import).toContain('import')
    expect(en.settings.purchase_import_received_at_import).toMatch(/import/i)
  })

  it('má kompletní české i anglické texty', () => {
    for (const dict of [cs, en]) {
      for (const key of [
        'purchase_import_received_at',
        'purchase_import_received_at_document',
        'purchase_import_received_at_import',
        'purchase_import_received_at_hint',
      ]) {
        expect(typeof dict.settings[key], key).toBe('string')
        expect(dict.settings[key].length, key).toBeGreaterThan(0)
      }
    }
  })

  it('nápověda říká, co se stane u dokladu bez data vystavení', () => {
    // Náhrada na den importu nesmí být tichá — uživatel se o ní musí dočíst.
    expect(cs.settings.purchase_import_received_at_hint).toContain('žádné datum')
    expect(cs.settings.purchase_import_received_at_hint).toContain('den importu')
    expect(en.settings.purchase_import_received_at_hint).toContain('no readable date')
  })

  it('nápověda u pole data přijetí už netvrdí, že se předvyplní dnem zpracování', () => {
    // Tenhle text byl po změně chování nepravdivý — hlídáme, ať se nevrátí.
    expect(cs.purchase_invoice.fields.vat_claim_received_note).not.toContain('dnem zpracování')
    expect(cs.purchase_invoice.fields.vat_claim_received_note).toContain('datem z dokladu')
    expect(en.purchase_invoice.fields.vat_claim_received_note).not.toContain('processing date')
  })

  it('volba nesmí slibovat změnu období odpočtu DPH', () => {
    // Importovaný doklad si drží received_at_source='import', takže na § 73 zařazení
    // tahle volba nesahá. Kdyby to nápověda tvrdila, účetní by čekala jiné chování.
    expect(cs.settings.purchase_import_received_at_hint).toContain('vliv nemá')
  })
})
