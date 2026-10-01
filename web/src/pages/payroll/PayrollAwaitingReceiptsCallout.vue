<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi } from '@/api/payroll'
import type { ReceiptBatchResult } from '@/api/dataBox'
import MobileKeyReceiptsButton from '@/components/submission/MobileKeyReceiptsButton.vue'

/*
 * Úkol na přehledu mezd: odeslané zprávy datové schránky bez doručenky
 * (déle než hodinu). Přehled pojišťovně je podaný doručením — dokud se
 * doručenka nenačte, měsíc se jako splněný neukáže, a že se doručenky
 * musí načíst, dřív nikdo netušil.
 */
const { t } = useI18n()
const pending = ref(0)
const result = ref<ReceiptBatchResult | null>(null)

async function load() {
  try {
    const health = await payrollApi.operationalHealth()
    pending.value = health.isds_outbox.awaiting_receipt ?? 0
  } catch {
    pending.value = 0
  }
}

async function onDone(done: ReceiptBatchResult) {
  result.value = done
  await load()
}

onMounted(load)
</script>

<template>
  <section
    v-if="pending > 0 || result"
    class="rounded-xl border border-warning-500/40 bg-warning-50 p-4 shadow-sm sm:p-6"
    data-test="payroll-awaiting-receipts"
  >
    <h2 class="text-base font-semibold text-warning-800">
      {{ pending > 0
        ? t('payroll.dashboard.awaiting_receipts.title', { count: pending })
        : t('payroll.dashboard.awaiting_receipts.done_title') }}
    </h2>
    <p class="mt-1 max-w-3xl text-sm text-neutral-700">
      {{ t('payroll.dashboard.awaiting_receipts.hint') }}
    </p>
    <p v-if="result" class="mt-2 text-sm text-neutral-800" data-test="payroll-awaiting-receipts-result">
      {{ t('payroll.submissions.monthly_checklist.send.receipts_loaded', {
        attached: result.attached,
        pending: result.pending,
      }) }}
    </p>
    <MobileKeyReceiptsButton
      v-if="pending > 0"
      class="mt-3"
      environment="production"
      :pending="pending"
      @done="onDone"
    />
  </section>
</template>
