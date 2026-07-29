<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AutomationFeedItem } from '@/api/automation'
import Drawer from '@/components/ui/Drawer.vue'

/**
 * Náhled zdrojového dokladu v automation feedu. Vizuál i chování (ESC, teleport,
 * scroll-lock, slide-in) drží sdílený Drawer.vue — dřív si tenhle komponent
 * kreslil vlastní panel a nic z toho neuměl.
 */
const props = defineProps<{ item: AutomationFeedItem }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()

const sourceLink = computed(() => {
  if (props.item.refs.invoice_id) return `/invoices/${props.item.refs.invoice_id}`
  if (props.item.refs.purchase_invoice_id) return `/purchase-invoices/${props.item.refs.purchase_invoice_id}`
  if (props.item.refs.statement_id) return { path: `/bank/${props.item.refs.statement_id}`, query: props.item.refs.bank_transaction_id ? { transaction: String(props.item.refs.bank_transaction_id) } : {} }
  return null
})
const money = computed(() => new Intl.NumberFormat(undefined, { style: 'currency', currency: props.item.currency }).format(props.item.amount))
</script>

<template>
  <Drawer
    :title="item.description || item.document_no || '—'"
    :subtitle="`${item.supplier_name} · ${item.date}`"
    width-class="max-w-lg"
    @close="emit('close')"
  >
    <p class="text-xs font-medium uppercase text-neutral-500">{{ t('automation.source_detail_title') }}</p>
    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
      <div>
        <dt class="text-neutral-500">{{ t('common.amount') }}</dt>
        <dd class="font-mono font-semibold">{{ money }}</dd>
      </div>
      <div>
        <dt class="text-neutral-500">{{ t('automation.col_posting') }}</dt>
        <dd class="font-mono">{{ item.debit_account_code || '—' }}/{{ item.credit_account_code || '—' }}</dd>
      </div>
      <div>
        <dt class="text-neutral-500">{{ t('automation.col_supplier') }}</dt>
        <dd>{{ item.supplier_name }}</dd>
      </div>
      <div>
        <dt class="text-neutral-500">{{ t('common.date') }}</dt>
        <dd>{{ item.date }}</dd>
      </div>
      <div class="col-span-2">
        <dt class="text-neutral-500">{{ t('common.description') }}</dt>
        <dd>{{ item.counterparty || item.description || '—' }}</dd>
      </div>
      <div v-if="item.source_details?.variable_symbol">
        <dt class="text-neutral-500">{{ t('automation.variable_symbol') }}</dt>
        <dd class="font-mono">{{ item.source_details.variable_symbol }}</dd>
      </div>
      <div v-if="item.source_details?.counterparty_account">
        <dt class="text-neutral-500">{{ t('automation.counterparty_account') }}</dt>
        <dd class="font-mono">
          {{ item.source_details.counterparty_account }}<span v-if="item.source_details.counterparty_bank"> / {{ item.source_details.counterparty_bank }}</span>
        </dd>
      </div>
    </dl>
    <RouterLink v-if="sourceLink" :to="sourceLink"
      class="mt-6 inline-flex w-fit rounded bg-primary-600 px-4 py-2 text-sm font-medium text-white">
      {{ t('automation.open_full_source') }} →
    </RouterLink>
  </Drawer>
</template>
