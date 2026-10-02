import { describe, expect, it } from 'vitest'
import type { SetupProposal } from '@/api/accountingSetupAssistant'
import {
  bulkSelection,
  dependentProposalIds,
  requiredChartProposalIds,
} from '@/utils/accountingSetupDependencies'

function proposal(id: number, proposalType: SetupProposal['proposal_type'], proposalJson: Record<string, unknown>): SetupProposal {
  return {
    id,
    proposal_type: proposalType,
    title: `Návrh ${id}`,
    confidence: 0.9,
    occurrence_count: 2,
    affected_amount: 0,
    proposal_json: proposalJson,
    evidence_json: {},
    decision: 'pending',
  }
}

describe('accounting setup proposal dependencies', () => {
  const proposals = [
    proposal(1, 'chart_account', { account_code: '501.200' }),
    proposal(2, 'chart_account', { account_code: '518.100' }),
    proposal(3, 'expense_rule', { target_account_code: '501.200' }),
    proposal(4, 'posting_rule', { debit_account_code: '518.100', credit_account_code: '321' }),
    proposal(5, 'bank_rule', { debit_account_code: '221', credit_account_code: '518.100' }),
  ]

  it('finds rules referencing an analytic on target, debit or credit side', () => {
    expect(dependentProposalIds(proposals, '518.100')).toEqual([4, 5])
  })

  it('finds every proposed analytic required by a rule', () => {
    expect(requiredChartProposalIds(proposals, proposals[4])).toEqual([2])
    expect(requiredChartProposalIds(proposals, proposals[2])).toEqual([1])
  })

  it('bulk-selects only the scope and keeps required analytics', () => {
    const scope = proposals.filter(p => p.proposal_type === 'expense_rule' || p.proposal_type === 'posting_rule')
    const next = bulkSelection(proposals, new Set([5]), scope, p => p.id === 4)
    expect([...next].sort()).toEqual([2, 4, 5])
  })

  it('bulk-deselects the scope and drops rules of a deselected analytic', () => {
    const scope = proposals.filter(p => p.proposal_type === 'chart_account')
    const next = bulkSelection(proposals, new Set([1, 2, 3, 4, 5]), scope, p => p.id === 1)
    expect([...next].sort()).toEqual([1, 3])
  })

  it('selects by confidence threshold within the scope only', () => {
    const scored = [
      { ...proposal(10, 'expense_rule', {}), confidence: 0.97 },
      { ...proposal(11, 'expense_rule', {}), confidence: 0.91 },
      { ...proposal(12, 'expense_rule', {}), confidence: 0.95 },
    ]
    const next = bulkSelection(scored, new Set([11, 99]), scored, p => p.confidence * 100 >= 95)
    expect([...next].sort()).toEqual([10, 12, 99])
  })
})
