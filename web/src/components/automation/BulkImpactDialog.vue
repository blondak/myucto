<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  count: number
  supplierCount: number
  accounts: Array<{ currency: string; account_code: string; debit: number; credit: number }>
  failed: number
  busy?: boolean
}>()
const emit = defineEmits<{ confirm: []; close: [] }>()
const { t } = useI18n()
const money = (amount: number, currency: string) => new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
    <section class="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-xl bg-surface p-5 shadow-xl">
      <header class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">{{ t('automation.bulk_preview_title') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('automation.bulk_preview_summary', { count, companies: supplierCount }) }}</p></div><button type="button" class="text-2xl" @click="emit('close')">×</button></header>
      <div class="mt-4 overflow-x-auto rounded-lg border border-neutral-200"><table class="w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('automation.account_label') }}</th><th class="px-3 py-2 text-right">{{ t('bank.posting.debit') }}</th><th class="px-3 py-2 text-right">{{ t('bank.posting.credit') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="row in accounts" :key="`${row.currency}:${row.account_code}`"><td class="px-3 py-2 font-mono">{{ row.account_code }} · {{ row.currency }}</td><td class="px-3 py-2 text-right font-mono">{{ money(row.debit, row.currency) }}</td><td class="px-3 py-2 text-right font-mono">{{ money(row.credit, row.currency) }}</td></tr></tbody></table></div>
      <p v-if="failed" class="mt-3 rounded bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('automation.bulk_preview_failed', { count: failed }) }}</p>
      <footer class="mt-5 flex flex-wrap justify-end gap-2"><button type="button" class="rounded border border-neutral-300 px-4 py-2 text-sm" :disabled="busy" @click="emit('close')">{{ t('common.cancel') }}</button><button type="button" class="rounded bg-primary-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-40" :disabled="busy || count === 0" @click="emit('confirm')">✓ {{ t('automation.bulk_preview_confirm', { count }) }}</button></footer>
    </section>
  </div>
</template>
