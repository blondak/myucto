import { describe, expect, it } from 'vitest'
import { PERMISSION_KEYS } from '@/security/permissions'
import {
  payrollAgendas,
  payrollQueryId,
  payrollQueryValue,
} from '@/pages/payroll/payrollAgendaLinks'

/**
 * Katalog agend je kontrakt mezi kartou zaměstnance, routerem a backendem.
 * Rozejde-li se, tlačítko svítí a routa ho zahodí na homepage — bez jediné
 * chybové hlášky. Proto se kontroluje testem, ne pohledem.
 */
describe('payrollAgendaLinks', () => {
  it('každá agenda míří na existující routu a předává zúžení na člověka', () => {
    for (const agenda of payrollAgendas) {
      const target = agenda.to(12, 5) as { name: string; query: Record<string, string> }
      expect(target.name, agenda.key).toMatch(/^payroll-/)
      if (agenda.scope === 'employment') {
        expect(target.query.employment, agenda.key).toBe('12')
      } else {
        expect(target.query.person, agenda.key).toBe('5')
      }
    }
  })

  it('používá jen oprávnění, která v katalogu práv opravdu existují', () => {
    for (const agenda of payrollAgendas) {
      expect(PERMISSION_KEYS, agenda.key).toContain(agenda.permission)
    }
  })

  it('nemá dvě agendy se stejným klíčem', () => {
    const keys = payrollAgendas.map(agenda => agenda.key)
    expect(new Set(keys).size).toBe(keys.length)
  })

  it('bere z opakovaného query parametru první hodnotu, ne pole', () => {
    expect(payrollQueryValue({ employment: ['7', '9'] }, 'employment')).toBe('7')
    expect(payrollQueryValue({ employment: '' }, 'employment')).toBeNull()
    expect(payrollQueryValue({}, 'employment')).toBeNull()
  })

  it('nepustí dál nečíselné ani nekladné id', () => {
    expect(payrollQueryId({ employment: '7' }, 'employment')).toBe(7)
    expect(payrollQueryId({ employment: 'abc' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '0' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '-3' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '7.5' }, 'employment')).toBeNull()
  })
})
