import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    locale: { value: 'cs' },
  }),
}))

import TakeoverCheckSection from '@/components/payroll/takeover/TakeoverCheckSection.vue'
import { monthRanges } from '@/components/payroll/takeover/takeoverMonths'
import { crownsToMinor, hoursToMinutes, minorToCrowns, minutesToHours } from '@/components/payroll/takeover/takeoverAmounts'

describe('TakeoverCheckSection', () => {
  it('bez nálezů řekne, že je vše v pořádku', () => {
    const wrapper = mount(TakeoverCheckSection, {
      props: {
        check: { takeover_months: [1, 2, 3], missing_openings: [], differences: [], opening_only: [], takeover_only: [] },
      },
    })

    expect(wrapper.find('[data-test="takeover-check-ok"]').exists()).toBe(true)
  })

  /** CO, U KOHO a KDE: jméno, měsíce a proklik na kartu osoby. */
  it('chybějící počáteční stavy vypíše po lidech s proklikem na kartu', () => {
    const wrapper = mount(TakeoverCheckSection, {
      props: {
        check: {
          takeover_months: [1, 2, 3],
          missing_openings: [{ employee_id: 9, employee_name: 'Syntetická osoba', missing_months: [1, 3] }],
          differences: [],
          opening_only: [{ employee_id: 9, employee_name: 'Syntetická osoba', periods: ['2026-02'] }],
          takeover_only: [],
        },
      },
    })

    const gaps = wrapper.get('[data-test="takeover-check-gaps"]')
    expect(gaps.text()).toContain('Syntetická osoba')
    expect(gaps.text()).toContain('1, 3')
    expect(gaps.get('[data-test="takeover-gap-link-9"]').attributes('data-to')).toContain('payroll-person')
    expect(wrapper.get('[data-test="takeover-opening-only-9"]').text()).toContain('2')
  })

  it('nic nevykreslí, když rok převzaté měsíce nemá', () => {
    const wrapper = mount(TakeoverCheckSection, {
      props: {
        check: { takeover_months: [], missing_openings: [], differences: [], opening_only: [], takeover_only: [] },
      },
    })

    expect(wrapper.find('[data-test="takeover-check"]').exists()).toBe(false)
  })
})

describe('převody převzatých částek', () => {
  it('koruny s čárkou i mezerami převede na haléře bez ztráty', () => {
    expect(crownsToMinor('12 345,50')).toBe(1_234_550)
    expect(crownsToMinor('0,07')).toBe(7)
    expect(crownsToMinor('')).toBe(0)
    expect(crownsToMinor('12,345')).toBeNull()
    expect(minorToCrowns(1_234_550)).toBe('12345,50')
    expect(minorToCrowns(0)).toBe('')
  })

  it('hodiny převede na minuty a zpět', () => {
    expect(hoursToMinutes('168,25')).toBe(10_095)
    expect(minutesToHours(10_095)).toBe('168,25')
  })

  it('měsíce shrne do rozsahů', () => {
    expect(monthRanges([1, 2, 3, 5, 9, 8])).toBe('1–3, 5, 8–9')
  })
})
