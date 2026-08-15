import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { DiagnosticCheck } from '@/api/diagnostics'

// Popisky se skládají z i18n klíčů; tady stačí, že se klíč vrátí zpátky —
// testujeme řazení, filtr a zvýraznění nálezu, ne překlady.
const TRANSLATED = new Set([
  'diagnostics.checks.php_extensions.actual_label',
  'diagnostics.checks.php_extensions.expected_label',
])

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string) => key,
    te: (key: string) => TRANSLATED.has(key),
  }),
}))

import EnvironmentCheckList from '@/components/system/EnvironmentCheckList.vue'

function check(partial: Partial<DiagnosticCheck> & { id: string; status: DiagnosticCheck['status'] }): DiagnosticCheck {
  return { actual: '', expected: '', manual: '', ...partial }
}

describe('EnvironmentCheckList', () => {
  const CHECKS: DiagnosticCheck[] = [
    check({ id: 'opcache', status: 'ok', actual: 'zapnuto' }),
    check({ id: 'php_extensions_optional', status: 'warn', actual: 'intl' }),
    check({ id: 'db_version', status: 'fail', actual: 'nedostupná' }),
    check({ id: 'redis', status: 'skip', actual: 'vypnutý' }),
  ]

  it('řadí problémy nahoru', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS } })
    const ids = wrapper.findAll('li').map((li) => li.text())

    expect(ids).toHaveLength(4)
    expect(ids[0]).toContain('db_version')
    expect(ids[1]).toContain('php_extensions_optional')
  })

  it('v režimu problems-only skryje ok i skip', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS, problemsOnly: true } })
    const text = wrapper.text()

    expect(wrapper.findAll('li')).toHaveLength(2)
    expect(text).not.toContain('diagnostics.checks.redis.label')
    expect(text).not.toContain('diagnostics.checks.opcache.label')
  })

  it('naměřenou hodnotu u nálezu zvýrazní červeně, u pořádku ne', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS } })
    const values = wrapper.findAll('dd')

    const failing = values.filter((dd) => dd.text() === 'nedostupná')
    const passing = values.filter((dd) => dd.text() === 'zapnuto')
    expect(failing[0].classes()).toContain('text-danger-600')
    expect(passing[0].classes()).not.toContain('text-danger-600')
  })

  it('informaci ukáže i u kontroly s nálezem, ale nezvýrazní ji jako problém', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({ id: 'cron_health', status: 'fail', actual: 'cron-backup', info: 'cron-bank-scan' })],
      },
    })

    const info = wrapper.findAll('dd').filter((dd) => dd.text() === 'cron-bank-scan')
    expect(info).toHaveLength(1)
    expect(info[0].classes()).not.toContain('text-danger-600')
    expect(wrapper.findAll('dt').map((dt) => dt.text())).toContain('diagnostics.info:')
  })

  it('u seznamových kontrol použije vlastní popisek místo „Naměřeno“', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: { checks: [check({ id: 'php_extensions', status: 'fail', actual: 'gd', expected: 'gd, zip' })] },
    })
    const labels = wrapper.findAll('dt').map((dt) => dt.text())

    expect(labels).toContain('diagnostics.checks.php_extensions.actual_label:')
    expect(labels).not.toContain('diagnostics.actual:')
  })
})
