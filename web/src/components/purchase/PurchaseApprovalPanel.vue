<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { purchaseApprovalsApi, type PurchaseApprovalRow, type PurchaseInvoiceApprovals } from '@/api/purchaseApprovals'
import ApprovalStatusBadge from '@/components/purchase/ApprovalStatusBadge.vue'
import { formatDate, formatMoney } from '@/composables/useFormat'

/**
 * Panel „Schválení" na detailu přijaté faktury: kola schvalování (kdo, středisko, částka,
 * stav, kdy, jak, komentář) a požadavky, které doklad ještě čekají. Akce (odeslat znovu,
 * zrušit) jsou v ActionBar detailu; panel se po nich přenačte přes `refreshKey`.
 */
const props = defineProps<{
  invoiceId: number
  /** Souhrnný stav z dokladu; změna přenačte panel. */
  approvalStatus?: string | null
  /** Zvýšením se panel přenačte (po akci v ActionBar). */
  refreshKey?: number
}>()

const { t } = useI18n()

const data = ref<PurchaseInvoiceApprovals | null>(null)
const loading = ref(false)
const failed = ref(false)
let seq = 0

async function reload(): Promise<void> {
  const current = ++seq
  loading.value = true
  failed.value = false
  try {
    const res = await purchaseApprovalsApi.forInvoice(props.invoiceId)
    if (current === seq) data.value = res
  } catch {
    if (current === seq) {
      failed.value = true
      data.value = null
    }
  } finally {
    if (current === seq) loading.value = false
  }
}

watch(() => [props.invoiceId, props.approvalStatus, props.refreshKey], () => { void reload() }, { immediate: true })

const rounds = computed(() => {
  const byRound = new Map<number, PurchaseApprovalRow[]>()
  for (const row of data.value?.data ?? []) {
    byRound.set(row.round, [...(byRound.get(row.round) ?? []), row])
  }
  return [...byRound.entries()].sort((a, b) => b[0] - a[0]).map(([round, rows]) => ({ round, rows }))
})
const currentRound = computed(() => rounds.value[0] ?? null)
const earlierRounds = computed(() => rounds.value.slice(1))

const missingApprovers = computed(() =>
  (data.value?.requirements ?? []).filter(r => r.approver === null))
const waitingRequirements = computed(() =>
  (data.value?.requirements ?? []).filter(r => r.approver !== null))

const hasRounds = computed(() => rounds.value.length > 0)
const visible = computed(() => {
  if (!data.value) return false
  return hasRounds.value || data.value.required || missingApprovers.value.length > 0
})

function viaLabel(row: PurchaseApprovalRow): string {
  return row.decided_via ? t(`purchase_approval.via.${row.decided_via}`) : ''
}

defineExpose({ reload })
</script>

<template>
  <section v-if="visible && data" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3" data-test="purchase-approval-panel">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-medium text-neutral-700 flex items-center gap-2">
        {{ t('purchase_approval.panel.title') }}
        <ApprovalStatusBadge :status="approvalStatus" />
      </h3>
    </div>

    <p v-if="approvalStatus === 'pending'" class="text-sm text-warning-700">{{ t('purchase_approval.panel.pending_hint') }}</p>
    <p v-else-if="approvalStatus === 'rejected'" class="text-sm text-danger-600">{{ t('purchase_approval.panel.rejected_hint') }}</p>
    <p v-else-if="!hasRounds && data.required" class="text-sm text-neutral-600">{{ t('purchase_approval.panel.required_hint') }}</p>

    <p v-for="req in missingApprovers" :key="`miss-${req.dimension_value.id}`"
       class="text-sm rounded-md bg-warning-50 border border-warning-500/40 text-warning-700 px-3 py-2" data-test="purchase-approval-missing-approver">
      {{ t('purchase_approval.panel.approver_missing', { name: req.dimension_value.name }) }}
    </p>

    <div v-if="!hasRounds && waitingRequirements.length" class="text-sm text-neutral-600">
      <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('purchase_approval.panel.requirements') }}</div>
      <ul class="space-y-0.5">
        <li v-for="req in waitingRequirements" :key="`req-${req.dimension_value.id}`">
          <span class="font-medium">{{ req.approver?.name }}</span>
          <span class="text-neutral-500"> · {{ req.dimension_value.name }} · {{ formatMoney(req.amount_czk, 'CZK') }}</span>
        </li>
      </ul>
    </div>

    <template v-for="(group, gi) in (currentRound ? [currentRound, ...earlierRounds] : [])" :key="group.round">
      <div class="text-xs uppercase tracking-wide text-neutral-500 pt-1">
        {{ t('purchase_approval.panel.round', { n: group.round }) }}
        <span v-if="gi === 0" class="normal-case text-neutral-400">· {{ t('purchase_approval.panel.current_round') }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[40rem]">
          <thead class="text-xs text-neutral-500">
            <tr class="text-left">
              <th class="py-1 pr-3 font-medium">{{ t('purchase_approval.panel.approver') }}</th>
              <th class="py-1 pr-3 font-medium">{{ t('purchase_approval.panel.center') }}</th>
              <th class="py-1 pr-3 font-medium text-right">{{ t('purchase_approval.panel.amount') }}</th>
              <th class="py-1 pr-3 font-medium">{{ t('purchase_approval.panel.state') }}</th>
              <th class="py-1 pr-3 font-medium">{{ t('purchase_approval.panel.when') }}</th>
              <th class="py-1 font-medium">{{ t('purchase_approval.panel.comment') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in group.rows" :key="row.id" class="align-top" :class="{ 'opacity-70': gi > 0 }">
              <td class="py-1.5 pr-3">{{ row.approver.name }}</td>
              <td class="py-1.5 pr-3">{{ row.dimension_value.name }}</td>
              <td class="py-1.5 pr-3 text-right font-mono whitespace-nowrap">{{ formatMoney(row.amount_czk, 'CZK') }}</td>
              <td class="py-1.5 pr-3"><ApprovalStatusBadge :status="row.status" /></td>
              <td class="py-1.5 pr-3 whitespace-nowrap text-neutral-600">
                <template v-if="row.decided_at">{{ formatDate(row.decided_at) }}<span v-if="viaLabel(row)" class="text-neutral-400"> · {{ viaLabel(row) }}</span></template>
                <template v-else-if="row.requested_at">{{ formatDate(row.requested_at) }}</template>
              </td>
              <td class="py-1.5 text-neutral-700 whitespace-pre-wrap">{{ row.comment }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
