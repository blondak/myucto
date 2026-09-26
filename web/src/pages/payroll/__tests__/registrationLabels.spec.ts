import { describe, expect, it } from 'vitest'
import { registrationA1FieldLabel } from '../registrationA1FieldLabels'
import { registrationItemLabel, registrationMissingItems } from '../registrationMissingItems'

const t = (key: string) => `T(${key})`

describe('registrationA1FieldLabel', () => {
  it('maps technical A1 paths to the form labels', () => {
    expect(registrationA1FieldLabel('permanent_address.house_number', t))
      .toBe('T(payroll.people.registration.a1.section.permanent_address) · T(payroll.people.registration.a1.address.house_number)')
    expect(registrationA1FieldLabel('employment.work_mode_code', t))
      .toBe('T(payroll.people.registration.a1.employment.work_mode_code)')
    expect(registrationA1FieldLabel('pension', t)).toBe('T(payroll.people.registration.a1.section.pension)')
    expect(registrationA1FieldLabel('facts.highest_education_code', t))
      .toBe('T(payroll.people.registration.a1.facts.highest_education_code)')
    expect(registrationA1FieldLabel('identity.citizenship_country_code', t))
      .toBe('T(payroll.people.registration.missing.fields.citizenship_country_code)')
  })

  it('leaves unknown paths as they are instead of inventing a label', () => {
    expect(registrationA1FieldLabel('something.unknown', t)).toBe('something.unknown')
  })
})

describe('registrationMissingItems', () => {
  it('reads every missing item from the error response', () => {
    const items = registrationMissingItems({
      response: {
        data: {
          error: {
            code: 'registration_data_incomplete',
            problems: [
              { field: 'identity.birth_surname', label: 'Rodné příjmení', message: 'x', panel: 'registration_identity', target: 'person' },
              { field: 'employer_variable_symbol', label: 'VS', message: 'y', panel: null, target: 'employer_settings' },
              { label: 'bez pole' },
            ],
          },
        },
      },
    })

    expect(items.map(item => item.field)).toEqual(['identity.birth_surname', 'employer_variable_symbol'])
    expect(items[1]).toMatchObject({ panel: null, target: 'employer_settings' })
    expect(registrationItemLabel(items[0]!, t)).toBe('T(payroll.people.registration.missing.fields.birth_surname)')
  })

  it('returns nothing for a plain error without a list', () => {
    expect(registrationMissingItems(new Error('network'))).toEqual([])
  })
})
