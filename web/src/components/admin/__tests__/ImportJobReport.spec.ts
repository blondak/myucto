import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))

import ImportJobReport from '@/components/admin/ImportJobReport.vue'
import type { ImportJobReport as Report } from '@/api/integrations'

const report: Report = {
  dry_run: false,
  agendas: {
    issued: { processed: 2, created: 2, skipped: 0, failed: 0, review: 1 },
    received: { processed: 401, created: 396, skipped: 0, failed: 5, review: 0 },
  },
  problems: [
    { agenda: 'received', fakturoid_id: 9001, number: 'DOD-1', local_id: null, severity: 'error', reason: 'Položka č. 1: sazba 20 %', hint: 'vat_rate' },
    { agenda: 'issued', fakturoid_id: 9002, number: 'FA-2', local_id: 42, severity: 'review', reason: 'Částky se liší', hint: 'amounts' },
  ],
  problems_omitted: 0,
}

describe('ImportJobReport', () => {
  it('ukáže počty po agendách a nepřenesené doklady s návodem', () => {
    const w = mount(ImportJobReport, { props: { report }, global: { stubs: { RouterLink: RouterLinkStub } } })

    expect(w.find('[data-test="agenda-received"]').text()).toContain('396')
    expect(w.find('[data-test="agenda-received"]').text()).toContain('5')
    expect(w.find('[data-test="agenda-subjects"]').exists()).toBe(false)

    const error = w.find('[data-test="problem-9001"]')
    expect(error.text()).toContain('sazba 20 %')
    expect(error.text()).toContain('integrations.import_report.hints.vat_rate')
    expect(error.findComponent(RouterLinkStub).exists()).toBe(false)

    const review = w.find('[data-test="problem-9002"]')
    expect(review.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'invoice-detail', params: { id: 42 } })
  })

  it('u zkoušky nanečisto řekne, co se ověřilo', () => {
    const w = mount(ImportJobReport, { props: { report: { ...report, dry_run: true } }, global: { stubs: { RouterLink: RouterLinkStub } } })
    expect(w.text()).toContain('integrations.import_report.dry_run_note')
  })
})
