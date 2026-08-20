import { describe, expect, it } from 'vitest'
import { codeFromName, slugifyCode, uniqueCode } from '@/utils/slugifyCode'

describe('slugifyCode', () => {
  it('vrací prázdný řetězec pro prázdný vstup', () => {
    expect(slugifyCode('')).toBe('')
    expect(slugifyCode('   ')).toBe('')
    expect(slugifyCode('---')).toBe('')
    expect(slugifyCode('…?!')).toBe('')
  })

  it('odstraní českou diakritiku', () => {
    expect(slugifyCode('Ústředí')).toBe('USTREDI')
    expect(slugifyCode('Mzdová účtárna')).toBe('MZDOVA_UCTARNA')
    expect(slugifyCode('Příliš žluťoučký kůň')).toBe('PRILIS_ZLUTOUCKY_KUN')
    expect(slugifyCode('ěščřžýáíéúůďťňó')).toBe('ESCRZYAIEUUDTNO')
  })

  it('přepíše i písmena bez NFD rozkladu (parita s PHP Slugifier)', () => {
    expect(slugifyCode('Łódź')).toBe('LODZ')
    expect(slugifyCode('Đakovo')).toBe('DAKOVO')
    expect(slugifyCode('Ørsted')).toBe('ORSTED')
    expect(slugifyCode('Straße')).toBe('STRASSE')
  })

  it('mezery a pomlčky převede na jedno podtržítko', () => {
    expect(slugifyCode('Pobočka   Brno')).toBe('POBOCKA_BRNO')
    expect(slugifyCode('Praha - Sever')).toBe('PRAHA_SEVER')
    expect(slugifyCode('a/b\\c.d')).toBe('A_B_C_D')
  })

  it('ořízne podtržítka z krajů', () => {
    expect(slugifyCode('  Sklad  ')).toBe('SKLAD')
    expect(slugifyCode('--Sklad--')).toBe('SKLAD')
  })

  it('zkrátí na maximální délku', () => {
    expect(slugifyCode('A'.repeat(50))).toHaveLength(32)
    expect(slugifyCode('Ústředí', 4)).toBe('USTR')
    expect(slugifyCode('Ústředí', 0)).toBe('USTREDI')
  })

  it('výsledek projde serverovou validací kódu účtárny', () => {
    const re = /^[A-Z0-9][A-Z0-9_-]{0,31}$/
    for (const name of ['Ústředí', 'Pobočka Brno — sever', '2. účtárna', 'Mzdová účtárna Praha 4']) {
      expect(slugifyCode(name)).toMatch(re)
    }
  })
})

describe('uniqueCode', () => {
  it('volný kód vrací beze změny', () => {
    expect(uniqueCode('USTREDI', ['BRNO'])).toBe('USTREDI')
  })

  it('při kolizi přidá numerický suffix', () => {
    expect(uniqueCode('USTREDI', ['USTREDI'])).toBe('USTREDI_2')
    expect(uniqueCode('USTREDI', ['USTREDI', 'USTREDI_2'])).toBe('USTREDI_3')
  })

  it('obsazené kódy porovnává case-insensitive a bez okrajových mezer', () => {
    expect(uniqueCode('USTREDI', [' ustredi '])).toBe('USTREDI_2')
  })

  it('prázdné obsazené hodnoty ignoruje', () => {
    expect(uniqueCode('USTREDI', ['', '   '])).toBe('USTREDI')
  })

  it('suffix se vejde do maximální délky na úkor konce základu', () => {
    const base = 'A'.repeat(32)
    const result = uniqueCode(base, [base])
    expect(result).toHaveLength(32)
    expect(result.endsWith('_2')).toBe(true)
  })

  it('prázdný základ nechává prázdný', () => {
    expect(uniqueCode('', ['X'])).toBe('')
  })
})

describe('codeFromName', () => {
  it('spojí slugify a řešení kolize', () => {
    expect(codeFromName('Mzdová účtárna')).toBe('MZDOVA_UCTARNA')
    expect(codeFromName('Mzdová účtárna', ['MZDOVA_UCTARNA'])).toBe('MZDOVA_UCTARNA_2')
  })

  it('prázdný název dá prázdný kód', () => {
    expect(codeFromName('', ['X'])).toBe('')
  })
})
