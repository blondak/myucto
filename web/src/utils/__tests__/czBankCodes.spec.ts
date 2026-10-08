import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { CZ_BANK_CODES, bankNameByCode } from '../czBankCodes'

/*
 * Platné kódy bank drží backend jako beze změny převzatý registr ČNB
 * (CzechBankCodeRegistry, kontrola C_KODBANKY v NEMPRI). Frontend k nim
 * doplňuje názvy; chybějící platný kód by u účtu v nové bance nechal pole
 * názvu prázdné.
 */
describe('číselník kódů bank', () => {
  it('obsahuje všechny platné kódy z registru ČNB', () => {
    const csv = readFileSync(resolve(process.cwd(), '../api/resources/ciselniky/kody_bank_CR.csv'), 'utf8')
    const codes = csv.replace(/^﻿/, '').split(/\r?\n/).slice(1)
      .map(line => line.split(';')[0]?.trim() ?? '')
      .filter(code => /^\d{4}$/.test(code))
    expect(codes.length).toBeGreaterThanOrEqual(40)
    expect(codes.filter(code => !Object.hasOwn(CZ_BANK_CODES, code))).toEqual([])
  })

  it('najde název banky i u kódu bez úvodních nul', () => {
    expect(bankNameByCode('100')).toBe('Komerční banka')
    expect(bankNameByCode('6363')).toBe('Partners Banka')
  })
})
