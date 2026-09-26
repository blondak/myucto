import { describe, expect, it } from 'vitest'
import { czechBirthNumberFacts } from './czechBirthNumber'

describe('czechBirthNumberFacts', () => {
  it('reads date and sex from a ten-digit birth number', () => {
    expect(czechBirthNumberFacts('900412/1236')).toEqual({ birthDate: '1990-04-12', sex: 'male' })
    // +50 u měsíce = žena
    expect(czechBirthNumberFacts('9152031234')).toEqual({ birthDate: '1991-02-03', sex: 'female' })
  })

  it('handles the extended +20 and +70 series and the 2000s', () => {
    expect(czechBirthNumberFacts('0422151234')).toEqual({ birthDate: '2004-02-15', sex: 'male' })
    expect(czechBirthNumberFacts('0472151234')).toEqual({ birthDate: '2004-02-15', sex: 'female' })
  })

  it('treats nine-digit numbers as 19xx', () => {
    expect(czechBirthNumberFacts('520101123')).toEqual({ birthDate: '1952-01-01', sex: 'male' })
  })

  it('returns null for incomplete or impossible numbers', () => {
    expect(czechBirthNumberFacts('9004')).toBeNull()
    expect(czechBirthNumberFacts('901332/1234')).toBeNull()
    expect(czechBirthNumberFacts('900231/1234')).toBeNull()
  })
})
