import type { SetupProposal } from '@/api/accountingSetupAssistant'

function referencedAccountCodes(proposal: SetupProposal): string[] {
  const payload = proposal.proposal_json
  return [...new Set([
    payload.target_account_code,
    payload.debit_account_code,
    payload.credit_account_code,
  ].map(value => String(value || '').trim()).filter(Boolean))]
}

export function dependentProposalIds(proposals: SetupProposal[], accountCode: string): number[] {
  return proposals
    .filter(proposal => proposal.proposal_type !== 'chart_account'
      && referencedAccountCodes(proposal).includes(accountCode))
    .map(proposal => proposal.id)
}

/**
 * Hromadný výběr v rozsahu (aktuální záložka a zdroj): `shouldSelect` rozhodne o každém návrhu.
 * Nejdřív se odebírá (odebraná analytika vezme s sebou závislá pravidla), pak přidává (vybrané
 * pravidlo si přibere analytiku, bez které by nemělo platný účet), takže pravidlo nad prahem
 * nezůstane viset bez své analytiky ani když ta sama práh nesplní.
 */
export function bulkSelection(
  proposals: SetupProposal[],
  current: Set<number>,
  scope: SetupProposal[],
  shouldSelect: (proposal: SetupProposal) => boolean,
): Set<number> {
  const next = new Set(current)
  const toSelect = scope.filter(shouldSelect)
  for (const proposal of scope) {
    if (toSelect.includes(proposal)) continue
    next.delete(proposal.id)
    const accountCode = String(proposal.proposal_json.account_code || '').trim()
    if (proposal.proposal_type === 'chart_account' && accountCode) {
      for (const dependentId of dependentProposalIds(proposals, accountCode)) next.delete(dependentId)
    }
  }
  for (const proposal of toSelect) {
    next.add(proposal.id)
    for (const dependencyId of requiredChartProposalIds(proposals, proposal)) next.add(dependencyId)
  }
  return next
}

export function requiredChartProposalIds(proposals: SetupProposal[], proposal: SetupProposal): number[] {
  const referenced = new Set(referencedAccountCodes(proposal))
  return proposals
    .filter(candidate => candidate.proposal_type === 'chart_account' && candidate.proposal_json.create !== false
      && referenced.has(String(candidate.proposal_json.account_code || '').trim()))
    .map(candidate => candidate.id)
}
