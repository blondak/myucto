<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DphCrossCheckDocument, DphCrossCheckFinding } from '@/api/reports'
import { formatMoney, formatDate } from '@/composables/useFormat'
import PaginationBar from '@/components/ui/PaginationBar.vue'

const props = defineProps<{ findings: DphCrossCheckFinding[] }>()
const { t } = useI18n()

const pageSize = 20
const pages = reactive<Record<string, number>>({})

const blocking = computed(() => props.findings.find(f => f.check === 'pending_approval_self_assessment') ?? null)
const info = computed(() => props.findings.find(f => f.check === 'pending_approval_deduction') ?? null)

function statusLabel(d: DphCrossCheckDocument): string {
  return d.approval_status === 'rejected'
    ? t('reports.pending_approval.status.rejected')
    : t('reports.pending_approval.status.pending')
}

function paged(f: DphCrossCheckFinding): DphCrossCheckDocument[] {
  const page = pages[f.check] ?? 1
  return f.documents.slice((page - 1) * pageSize, page * pageSize)
}
</script>

<template>
  <div v-if="blocking" class="bg-danger-50 border border-danger-500/40 rounded-md p-3 text-sm text-danger-700 space-y-2">
    <strong>{{ t('reports.pending_approval.self_assessment_title') }}</strong>
    <div>{{ t('reports.pending_approval.self_assessment_note') }}</div>
    <ul class="list-disc list-inside">
      <li v-for="d in paged(blocking)" :key="d.invoice_id">
        <RouterLink :to="`/purchase-invoices/${d.invoice_id}`" class="underline font-medium">{{ d.doc_number ?? ('#' + d.invoice_id) }}</RouterLink>
        <template v-if="d.partner_name"> · {{ d.partner_name }}</template>
        · {{ t('reports.pending_approval.duzp') }} {{ formatDate(d.tax_date ?? '') }}
        · {{ t('reports.pending_approval.self_assessed_vat') }} {{ formatMoney(d.counter, 'CZK') }}
        · {{ statusLabel(d) }}
      </li>
    </ul>
    <PaginationBar embedded :page="pages[blocking.check] ?? 1" :per-page="pageSize" :total="blocking.documents.length" @update:page="pages[blocking.check] = $event" />
    <p class="text-xs text-danger-600">{{ t('reports.pending_approval.download_gate_hint') }}</p>
  </div>
  <div v-if="info" class="bg-neutral-50 border border-neutral-300 rounded-md p-3 text-sm text-neutral-600 space-y-2">
    <strong>{{ t('reports.pending_approval.deduction_title') }}</strong>
    <div>{{ t('reports.pending_approval.deduction_note') }}</div>
    <ul class="list-disc list-inside">
      <li v-for="d in paged(info)" :key="d.invoice_id">
        <RouterLink :to="`/purchase-invoices/${d.invoice_id}`" class="underline">{{ d.doc_number ?? ('#' + d.invoice_id) }}</RouterLink>
        <template v-if="d.partner_name"> · {{ d.partner_name }}</template>
        · {{ t('reports.pending_approval.duzp') }} {{ formatDate(d.tax_date ?? '') }}
        · {{ t('reports.pending_approval.vat') }} {{ formatMoney(d.counter, 'CZK') }}
        · {{ statusLabel(d) }}
      </li>
    </ul>
    <PaginationBar embedded :page="pages[info.check] ?? 1" :per-page="pageSize" :total="info.documents.length" @update:page="pages[info.check] = $event" />
  </div>
</template>
