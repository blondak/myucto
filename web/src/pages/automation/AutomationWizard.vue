<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { automationApi, type WizardAccountEndpoint, type WizardAnalysis, type WizardApplyResult, type WizardRuleProposal } from '@/api/automation'
import { autoPostingApi, type AutomationPreset } from '@/api/autoPosting'
import { bankPostingApi } from '@/api/bankPosting'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ supplierId: number }>()
const emit = defineEmits<{ close: []; done: [] }>()
const { t } = useI18n()
const toast = useToast()
const step = ref(1)
const loading = ref(true)
const analysis = ref<WizardAnalysis | null>(null)
const selected = ref(new Set<string>())
const proposals = ref<Record<string, WizardRuleProposal>>({})
const result = ref<WizardApplyResult | null>(null)
const preset = ref<AutomationPreset>('suggest')
const digest = ref(false)
const digestHour = ref(7)
const proposalPage = ref(1)
const PROPOSALS_PER_PAGE = 5
const aiAvailable = ref(false)
const aiBusy = ref(new Set<string>())
const usable = computed(() => (analysis.value?.clusters ?? []).filter(c => selected.value.has(c.key) && proposals.value[c.key]?.debit_account_code && proposals.value[c.key]?.credit_account_code))
const proposalPageCount = computed(() => Math.max(1, Math.ceil((analysis.value?.clusters.length ?? 0) / PROPOSALS_PER_PAGE)))
const paginatedClusters = computed(() => {
  const start = (proposalPage.value - 1) * PROPOSALS_PER_PAGE
  return analysis.value?.clusters.slice(start, start + PROPOSALS_PER_PAGE) ?? []
})
function accountNumber(endpoint: WizardAccountEndpoint): string {
  if (!endpoint.account_number) return '—'
  if (!endpoint.bank_code || endpoint.account_number.includes('/') || endpoint.account_number.toUpperCase().startsWith('CZ')) return endpoint.account_number
  return `${endpoint.account_number} / ${endpoint.bank_code}`
}

onMounted(async () => {
  try {
    analysis.value = await automationApi.wizardAnalysis(props.supplierId)
    for (const cluster of analysis.value.clusters) {
      proposals.value[cluster.key] = { ...cluster.proposal }
      if (cluster.proposal.debit_account_code && cluster.proposal.credit_account_code) selected.value.add(cluster.key)
    }
    try { const policy = await autoPostingApi.getPolicy(); preset.value = policy.automation_level; digest.value = policy.automation_digest_enabled; digestHour.value = policy.automation_digest_hour ?? 7 } catch { /* feature-degrade */ }
    try { aiAvailable.value = (await bankPostingApi.aiAvailability()).available } catch { /* feature-degrade */ }
  } catch { toast.error(t('common.error')) } finally { loading.value = false }
})
function toggle(key: string) { const n = new Set(selected.value); n.has(key) ? n.delete(key) : n.add(key); selected.value = n }
async function suggestPosting(cluster: WizardAnalysis['clusters'][number]) {
  const txId = Number(cluster.sample?.[0]?.id ?? 0)
  const query = `${proposals.value[cluster.key]?.name ?? ''} ${String(cluster.sample?.[0]?.description ?? '')}`.trim().slice(0, 500)
  if (!txId || query.length < 3) { toast.error(t('automation.ai.manual_query_error')); return }
  const busy = new Set(aiBusy.value); busy.add(cluster.key); aiBusy.value = busy
  try {
    const s = await bankPostingApi.aiSuggest(txId, query)
    proposals.value[cluster.key].debit_account_code = s.debit_account_code
    proposals.value[cluster.key].credit_account_code = s.credit_account_code
    const sel = new Set(selected.value); sel.add(cluster.key); selected.value = sel
    toast.success(t('automation.wizard.ai_suggest_done'))
  } catch { toast.error(t('automation.ai.manual_query_error')) }
  finally { const b = new Set(aiBusy.value); b.delete(cluster.key); aiBusy.value = b }
}
async function apply() {
  loading.value = true
  try { result.value = await automationApi.wizardApply(props.supplierId, usable.value.map(c => proposals.value[c.key]), true); step.value = 4; emit('done') }
  catch { toast.error(t('common.error')) } finally { loading.value = false }
}
async function savePolicy() {
  try { await autoPostingApi.putPolicy({ automation_level: preset.value, automation_digest_enabled: digest.value, automation_digest_hour: digestHour.value }); toast.success(t('common.saved')); emit('close') }
  catch { toast.error(t('common.error')) }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-black/40 p-4" @click.self="emit('close')">
    <section class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-surface shadow-xl">
      <header class="flex shrink-0 items-center justify-between border-b border-neutral-200 p-5"><div><h2 class="text-xl font-semibold">{{ t('automation.wizard.title') }}</h2><p class="text-xs text-neutral-500">{{ step }}/4</p></div><button class="text-2xl" @click="emit('close')">×</button></header>
      <div class="flex-1 overflow-y-auto p-5">
        <div v-if="loading" class="py-16 text-center text-neutral-500">{{ t('common.loading') }}</div>
        <template v-else-if="analysis">
          <div v-if="step===1" class="space-y-4"><p class="text-lg font-medium">{{ t('automation.wizard.coverage', { clusters: analysis.clusters.length, pct: analysis.coverage_pct }) }}</p><p class="text-sm text-neutral-600">{{ t('automation.wizard.analyzed', { count: analysis.analyzed_tx }) }}</p><button class="cursor-pointer rounded bg-primary-600 px-4 py-2 text-white" @click="step=2">{{ t('common.continue') }}</button></div>
          <div v-else-if="step===2" class="space-y-4"><p class="rounded bg-primary-50 p-3 text-sm text-primary-700">{{ t('automation.wizard.all_suggest_hint') }}</p>
            <label v-for="cluster in paginatedClusters" :key="cluster.key" class="block rounded-lg border border-neutral-200 p-4">
              <div class="flex items-start gap-3"><input type="checkbox" :checked="selected.has(cluster.key)" @change="toggle(cluster.key)"><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><strong>{{ proposals[cluster.key]?.name }}</strong><span v-if="cluster.flow.own_transfer" class="rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-600">{{ t('automation.wizard.own_transfer') }}</span></div><div class="text-xs text-neutral-500">{{ cluster.tx_count }}× · {{ cluster.first_seen }}–{{ cluster.last_seen }}</div><div class="mt-3 grid items-center gap-2 rounded-lg bg-neutral-50 p-3 sm:grid-cols-[1fr_auto_1fr]"><div><div class="font-medium">{{ cluster.flow.from.label || t(cluster.flow.from.own ? 'automation.wizard.own_account' : 'automation.wizard.counterparty_account') }}</div><div class="font-mono text-xs text-neutral-500">{{ accountNumber(cluster.flow.from) }}</div></div><span class="text-center text-lg text-primary-600">→</span><div><div class="font-medium">{{ cluster.flow.to.label || t(cluster.flow.to.own ? 'automation.wizard.own_account' : 'automation.wizard.counterparty_account') }}</div><div class="font-mono text-xs text-neutral-500">{{ accountNumber(cluster.flow.to) }}</div></div></div><div class="mt-3 grid grid-cols-2 gap-2"><label class="text-xs text-neutral-500">{{ t('bank.posting.debit') }}<input v-model="proposals[cluster.key].debit_account_code" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono"></label><label class="text-xs text-neutral-500">{{ t('bank.posting.credit') }}<input v-model="proposals[cluster.key].credit_account_code" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono"></label></div><button v-if="aiAvailable" type="button" :disabled="aiBusy.has(cluster.key)" class="mt-2 inline-flex h-8 cursor-pointer items-center gap-1 rounded-md border border-primary-300 px-3 text-xs font-medium text-primary-700 hover:bg-primary-50 disabled:cursor-not-allowed disabled:opacity-50" @click.stop.prevent="suggestPosting(cluster)">{{ aiBusy.has(cluster.key) ? t('common.loading') : t('automation.wizard.ai_suggest') }}</button></div></div>
            </label>
            <nav v-if="proposalPageCount > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-4 text-sm">
              <button type="button" :disabled="proposalPage <= 1" class="h-8 cursor-pointer rounded-md border border-neutral-300 px-3 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-40" @click="proposalPage--">{{ t('common.previous') }}</button>
              <span class="text-neutral-500">{{ t('common.page') }} {{ proposalPage }} / {{ proposalPageCount }}</span>
              <button type="button" :disabled="proposalPage >= proposalPageCount" class="h-8 cursor-pointer rounded-md border border-neutral-300 px-3 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-40" @click="proposalPage++">{{ t('common.next') }}</button>
            </nav>
            <button :disabled="usable.length===0" class="cursor-pointer rounded bg-primary-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-40" @click="step=3">{{ t('common.continue') }}</button></div>
          <div v-else-if="step===3" class="space-y-4"><h3 class="font-semibold">{{ t('automation.wizard.step_backfill') }}</h3><p>{{ t('automation.wizard.backfill_preview', { count: usable.reduce((n,c)=>n+c.tx_count,0) }) }}</p><p v-if="analysis.locked.tx_count" class="rounded bg-warning-50 p-3 text-warning-700">{{ t('automation.wizard.locked_warning', { count: analysis.locked.tx_count, periods: analysis.locked.periods.join(', ') }) }}</p><button class="rounded bg-primary-600 px-4 py-2 text-white" @click="apply">{{ t('automation.wizard.create_rules') }}</button></div>
          <div v-else class="space-y-4"><p class="rounded bg-success-50 p-3 text-success-600">{{ t('automation.wizard.backfill_done', { n: result?.backfilled ?? 0 }) }}</p><label class="block"><span class="text-sm font-medium">{{ t('automation.wizard.step_level') }}</span><select v-model="preset" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2"><option value="off">{{ t('settings.automation.preset_off') }}</option><option value="suggest">{{ t('settings.automation.preset_suggest') }}</option><option value="assisted">{{ t('settings.automation.preset_assisted') }}</option><option value="full">{{ t('settings.automation.preset_full') }}</option></select></label><label class="flex items-center gap-2"><input v-model="digest" type="checkbox">{{ t('automation.digest.enable') }}</label><input v-if="digest" v-model.number="digestHour" type="number" min="0" max="23" class="w-24 rounded border border-neutral-300 px-3 py-2"><button class="rounded bg-primary-600 px-4 py-2 text-white" @click="savePolicy">{{ t('common.save') }}</button></div>
        </template>
      </div>
    </section>
  </div>
</template>
