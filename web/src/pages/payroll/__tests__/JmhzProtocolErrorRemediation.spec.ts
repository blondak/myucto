import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { cs, en, i18nMessages } from '../../../../tests/locales'
import type { PayrollJmhzProtocolRemediation } from '@/api/payroll'
import JmhzProtocolErrorRemediation from '../JmhzProtocolErrorRemediation.vue'
import { jmhzProtocolRemediationTarget } from '../jmhzBlockerRemediation'

const RouterLinkStub = {
  props: ['to'],
  template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
}

function remediation(overrides: Partial<PayrollJmhzProtocolRemediation> = {}): PayrollJmhzProtocolRemediation {
  return {
    code: 'jmhz_protocol_tax_nonresident_with_declaration',
    kind: 'registration',
    field: null,
    source: 'cssz_faq_2026_06_09',
    empty_attribute_ids: ['10300', '10310'],
    ...overrides,
  }
}

function mountIn(locale: 'cs' | 'en', value: PayrollJmhzProtocolRemediation) {
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'cs', messages: i18nMessages })
  return mount(JmhzProtocolErrorRemediation, {
    props: { remediation: value, testId: 'remediation' },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('náprava chyby z protokolu ČSSZ', () => {
  it('ukáže vysvětlení, kroky z překladů, prázdné atributy a zdroj', () => {
    const wrapper = mountIn('cs', remediation())
    const help = cs.payroll.jmhz_protocol_help

    expect(wrapper.text()).toContain(cs.payroll.jmhz_gate.codes.jmhz_protocol_tax_nonresident_with_declaration)
    const steps = wrapper.findAll('li').map(item => item.text())
    expect(steps).toEqual(help.steps.jmhz_protocol_tax_nonresident_with_declaration)
    expect(wrapper.find('[data-test="remediation-empty-attributes"]').text()).toContain('10310')
    expect(wrapper.text()).toContain(help.source.cssz_faq_2026_06_09)
    expect(wrapper.find('[data-test="remediation-link"]').text())
      .toContain(cs.payroll.jmhz_gate.remediation.registration)
  })

  it('v angličtině nepoužije české texty', () => {
    const wrapper = mountIn('en', remediation({
      code: 'jmhz_protocol_regular_submission_duplicate',
      kind: 'correction',
      empty_attribute_ids: [],
    }))

    expect(wrapper.text()).toContain(en.payroll.jmhz_gate.codes.jmhz_protocol_regular_submission_duplicate)
    expect(wrapper.findAll('li').map(item => item.text()))
      .toEqual(en.payroll.jmhz_protocol_help.steps.jmhz_protocol_regular_submission_duplicate)
    expect(wrapper.find('[data-test="remediation-empty-attributes"]').exists()).toBe(false)
  })

  it('proklik vede na agendu, kde se náprava dělá', () => {
    expect(jmhzProtocolRemediationTarget(remediation({ kind: 'correction', code: 'jmhz_protocol_correction_guid_invalid' })))
      .toEqual({ name: 'payroll-submissions-tab', params: { tab: 'transport' } })
    expect(jmhzProtocolRemediationTarget(remediation()))
      .toEqual({ name: 'payroll-people' })
    expect(jmhzProtocolRemediationTarget(remediation({
      code: 'jmhz_protocol_employment_not_found_at_cssz',
      kind: 'employment_identity',
      field: 'jmhz.employment_external_identifier',
      source: 'cssz_control_catalog_1_4_2_10',
    }))).toEqual({
      name: 'payroll-people',
      query: { panel: 'jmhz_identity', field: 'jmhz.employment_external_identifier' },
    })
  })
})
