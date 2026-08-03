<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { automationApi, type AutomationHistoryItem } from '@/api/automation'
import { formatDate, formatMoney } from '@/composables/useFormat'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
const props = defineProps<{ suppliers: number[] }>()
const { t } = useI18n()
const items = ref<AutomationHistoryItem[]>([])
const page = ref(1)
const perPage = 50
const total = ref(0)
const loading = ref(false)
const failed = ref(false)
let loadSequence = 0

async function load() {
  const sequence = ++loadSequence
  loading.value = true
  failed.value = false
  try {
    const result = await automationApi.history({ suppliers: props.suppliers.join(','), page: page.value, per_page: perPage })
    if (sequence !== loadSequence) return
    if (result.items.length === 0 && result.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(result.total / perPage))
      return
    }
    items.value = result.items
    total.value = result.total
  } catch {
    if (sequence === loadSequence) failed.value = true
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

watch(() => props.suppliers.join(','), () => {
  if (page.value !== 1) page.value = 1
  else void load()
}, { immediate: true })
watch(page, load)
</script>
<template>
  <div class="overflow-hidden rounded-lg border border-neutral-200 bg-surface">
    <p v-if="loading" class="p-8 text-center text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="failed" class="p-8 text-center text-danger-500">{{ t('automation.load_error') }}</p>
    <div v-else class="divide-y divide-neutral-200">
      <article v-for="item in items" :key="item.id" class="grid gap-3 p-4 lg:grid-cols-[10rem_minmax(0,1fr)_auto]">
        <time class="text-sm text-neutral-500">{{ item.occurred_at }}</time>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <strong>{{ t(`automation.history.${item.event}`) }}</strong>
            <span class="text-xs text-neutral-500">{{ item.supplier_name }} · {{ item.decided_by || t('automation.decided_automatic') }}</span>
          </div>
          <p class="mt-1 font-medium text-neutral-800">{{ item.description || t('automation.history_no_description') }}</p>
          <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-neutral-500">
            <span v-if="item.counterparty">{{ item.counterparty }}</span>
            <span>{{ t('automation.history_bank_date') }} {{ formatDate(item.transaction_date) }}</span>
            <span v-if="item.variable_symbol">{{ t('automation.history_vs', { value: item.variable_symbol }) }}</span>
            <span v-if="item.document_no">{{ item.document_no }}</span>
          </div>
          <div class="mt-2 flex flex-wrap gap-3 text-xs">
            <RouterLink v-if="item.journal_entry_id" :to="{ path: '/accounting/journal', query: { entry_id: String(item.journal_entry_id) } }" class="font-medium text-primary-600 hover:underline">
              {{ t('automation.show_entry') }} #{{ item.journal_entry_id }}
            </RouterLink>
            <RouterLink :to="{ path: `/bank/${item.statement_id}`, query: { transaction: String(item.bank_transaction_id) } }" class="font-medium text-primary-600 hover:underline">
              {{ t('automation.history_open_transaction') }} #{{ item.bank_transaction_id }}
            </RouterLink>
          </div>
        </div>
        <div class="text-left lg:text-right">
          <div class="font-semibold tabular-nums">{{ formatMoney(item.amount, item.currency) }}</div>
          <div class="mt-1 font-mono text-sm text-neutral-600">{{ item.debit_account_code }}/{{ item.credit_account_code }}</div>
        </div>
      </article>
      <EmptyState v-if="!items.length" icon="archive" accent="neutral" :title="t('automation.history_empty')"/>
    </div>
    <PaginationBar embedded :page="page" :per-page="perPage" :total="total" @update:page="page = $event" />
  </div>
</template>
