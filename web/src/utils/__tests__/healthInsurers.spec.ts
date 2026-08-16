import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  HEALTH_INSURERS,
  healthInsurerName,
  healthInsurerOptions,
  isHealthInsurerCode,
} from '@/utils/healthInsurers'

describe('číselník zdravotních pojišťoven', () => {
  it('má jen trojmístné číselné kódy a žádný se neopakuje', () => {
    for (const insurer of HEALTH_INSURERS) {
      expect(insurer.code, insurer.name).toMatch(/^\d{3}$/)
      expect(insurer.name.trim()).not.toBe('')
    }
    const codes = HEALTH_INSURERS.map(insurer => insurer.code)
    expect(new Set(codes).size).toBe(codes.length)
  })

  it('pozná kód z číselníku a odmítne cokoliv jiného', () => {
    expect(isHealthInsurerCode('111')).toBe(true)
    expect(isHealthInsurerCode(' 111 ')).toBe(true)
    expect(isHealthInsurerCode('999')).toBe(false)
    expect(isHealthInsurerCode('')).toBe(false)
    expect(isHealthInsurerCode(null)).toBe(false)
    expect(isHealthInsurerCode(undefined)).toBe(false)
  })

  it('vrátí název ke kódu a null k neznámému', () => {
    expect(healthInsurerName('111')).toBe('Všeobecná zdravotní pojišťovna ČR (VZP)')
    expect(healthInsurerName('999')).toBeNull()
    expect(healthInsurerName(null)).toBeNull()
  })

  it('nabízí do výběru kód i název, aby šlo hledat obojím', () => {
    const options = healthInsurerOptions()
    expect(options).toHaveLength(HEALTH_INSURERS.length)
    expect(options[0]).toEqual({
      value: '111',
      label: '111 — Všeobecná zdravotní pojišťovna ČR (VZP)',
    })
  })

  /**
   * Číselník je záměrně kopie backendového `Service\Codebook\HealthInsurers`.
   * Dvě kopie v různých jazycích se rozejdou, jakmile je nikdo nedrží u sebe —
   * přesně to se stalo, když seznam žil zadrátovaný v `EmployerSettings.vue`.
   */
  it('sedí na backendový číselník kód po kódu', () => {
    // vitest běží s cwd = web/, proto cesta od kořene repa.
    const php = readFileSync(
      resolve(process.cwd(), '../api/src/Service/Codebook/HealthInsurers.php'),
      'utf8',
    )
    const constStart = php.indexOf('public const CODES')
    const constEnd = php.indexOf('];', constStart)
    const block = php.slice(constStart, constEnd)
    const backend = [...block.matchAll(/'(\d{3})'\s*=>\s*'([^']+)'/g)]
      .map(match => ({ code: match[1], name: match[2] }))

    expect(backend.length).toBeGreaterThan(0)
    expect(HEALTH_INSURERS.map(insurer => ({ ...insurer }))).toEqual(backend)
  })
})
