import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollErasureProposal,
  PayrollErasureProposalItem,
} from '@/api/payrollRetention'

/**
 * Obrazovka výmazu — hlídá se na ní jediná věc: že nevratný úkon nejde spustit
 * omylem a že návrh JMENUJE, koho se týká.
 */

const m = vi.hoisted(() => ({
  proposals: vi.fn(),
  proposal: vi.fn(),
  createProposal: vi.fn(),
  approveProposal: vi.fn(),
  rejectProposal: vi.fn(),
  executeProposal: vi.fn(),
  canWrite: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/payrollRetention', () => ({
  payrollRetentionApi: {
    proposals: m.proposals,
    proposal: m.proposal,
    createProposal: m.createProposal,
    approveProposal: m.approveProposal,
    rejectProposal: m.rejectProposal,
    executeProposal: m.executeProposal,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: (permission: string) => m.canWrite(permission),
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, success: m.toastSuccess }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

vi.mock('vue-i18n', async (importOriginal) => {
  const { ref } = await import('vue')
  return {
    ...(await importOriginal<typeof import('vue-i18n')>()),
    useI18n: () => ({
      t: (key: string, params?: Record<string, unknown>) =>
        params ? `${key}:${JSON.stringify(params)}` : key,
      locale: ref('cs-CZ'),
    }),
  }
})

import PayrollErasure from '@/pages/payroll/PayrollErasure.vue'

function proposal(overrides: Partial<PayrollErasureProposal> = {}): PayrollErasureProposal {
  return {
    id: 42,
    as_of: '2026-08-17',
    status: 'pending',
    note: null,
    created_at: '2026-08-17 10:00:00',
    created_by: 1,
    approved_at: null,
    approved_by: null,
    rejected_at: null,
    executed_at: null,
    item_count: 2,
    ...overrides,
  }
}

function item(overrides: Partial<PayrollErasureProposalItem> = {}): PayrollErasureProposalItem {
  return {
    id: 1,
    employee_id: 11,
    full_name: 'Marie Dlouhá',
    action: 'erase',
    governing_category: 'payroll_sheet',
    governing_source: '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
    governing_source_status: 'statute_verified',
    retained_until: '2020-12-31',
    last_record_year: 1975,
    cascade_counts: {
      identity: { payroll_person_identifiers: 3 },
      residue: { payroll_documents: 2 },
    },
    outcome: 'pending',
    skip_reason: null,
    executed_at: null,
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollErasure, {
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
        Modal: { props: ['title', 'widthClass'], template: '<div class="modal"><slot /></div>' },
      },
    },
  })
}

describe('PayrollErasure', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.proposals.mockResolvedValue([proposal()])
    m.proposal.mockResolvedValue({ proposal: proposal(), items: [item()] })
    m.createProposal.mockResolvedValue({ id: 42 })
    m.approveProposal.mockResolvedValue({ ok: true })
    m.rejectProposal.mockResolvedValue({ ok: true })
    m.executeProposal.mockResolvedValue({ done: 1, skipped_hold: 0, skipped_changed: 0 })
  })

  it('selhání načtení se NIKDY nezobrazí jako „žádné návrhy"', async () => {
    m.proposals.mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.erasure.load_failed')
    expect(wrapper.text()).not.toContain('payroll.erasure.empty_hint')
  })

  it('detail jmenuje osoby a rozepisuje, co zmizí a co zůstane', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="erasure-proposal-42"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="erasure-item-11"]').text()).toContain('Marie Dlouhá')
    expect(wrapper.get('[data-test="erasure-tile-identity"]').text()).toContain('3')
    expect(wrapper.get('[data-test="erasure-tile-residue"]').text()).toContain('2')
    expect(wrapper.get('[data-test="erasure-residue-11"]').text()).toContain('payroll_documents')
  })

  it('schválení a provedení jsou dva různé kroky, ne jedno tlačítko', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="erasure-proposal-42"]').trigger('click')
    await flushPromises()

    // Nad neschváleným návrhem se provedení vůbec nenabízí.
    expect(wrapper.find('[data-test="erasure-execute"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="erasure-approve"]').exists()).toBe(true)

    m.proposal.mockResolvedValue({
      proposal: proposal({ status: 'approved', approved_at: '2026-08-17 11:00:00' }),
      items: [item()],
    })
    await wrapper.get('[data-test="erasure-approve"]').trigger('click')
    await flushPromises()

    expect(m.approveProposal).toHaveBeenCalledWith(42)
    expect(m.executeProposal).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="erasure-execute"]').exists()).toBe(true)
  })

  it('provedení vyžaduje zaškrtnutí I opsání čísla návrhu', async () => {
    m.proposals.mockResolvedValue([proposal({ status: 'approved' })])
    m.proposal.mockResolvedValue({ proposal: proposal({ status: 'approved' }), items: [item()] })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="erasure-proposal-42"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="erasure-execute"]').trigger('click')
    const run = wrapper.get('[data-test="erasure-confirm-run"]')
    expect(run.attributes('disabled')).toBeDefined()

    // Samotné zaškrtnutí nestačí.
    await wrapper.get('[data-test="erasure-confirm-ack"]').setValue(true)
    expect(wrapper.get('[data-test="erasure-confirm-run"]').attributes('disabled')).toBeDefined()

    // Ani špatné číslo — potvrzuje se KONKRÉTNÍ návrh.
    await wrapper.get('[data-test="erasure-confirm-id"]').setValue('41')
    expect(wrapper.get('[data-test="erasure-confirm-run"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="erasure-confirm-id"]').setValue('42')
    expect(wrapper.get('[data-test="erasure-confirm-run"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-test="erasure-confirm-run"]').trigger('click')
    await flushPromises()
    expect(m.executeProposal).toHaveBeenCalledWith(42)
  })

  it('bez práva zápisu se návrh dá jen číst', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="erasure-proposal-42"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="erasure-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="erasure-reject"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="erasure-item-11"]').text()).toContain('Marie Dlouhá')
  })

  it('provedený návrh zůstává dokladem, i když už jméno není', async () => {
    m.proposals.mockResolvedValue([proposal({ status: 'executed', executed_at: '2026-08-18 09:00:00' })])
    m.proposal.mockResolvedValue({
      proposal: proposal({ status: 'executed' }),
      items: [item({ full_name: null, outcome: 'done', executed_at: '2026-08-18 09:00:00' })],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="erasure-proposal-42"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="erasure-item-11"]').text()).toContain('payroll.erasure.person_erased')
    expect(wrapper.get('[data-test="erasure-state-hint"]').text()).toContain('payroll.erasure.state_executed')
    expect(wrapper.find('[data-test="erasure-execute"]').exists()).toBe(false)
  })
})
