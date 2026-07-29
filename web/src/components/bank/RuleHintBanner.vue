<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankTransaction } from '@/api/bank'
import type { BankPostingRulePayload } from '@/api/bankPosting'
import RuleFormModal from './RuleFormModal.vue'

const props = defineProps<{
  count: number
  tx: BankTransaction
  debitAccountCode: string
  creditAccountCode: string
}>()
const emit = defineEmits<{ close: []; created: [] }>()

const { t } = useI18n()
const showModal = ref(false)

const baseAmount = computed(() => Math.abs(props.tx.amount))

const prefill = computed<BankPostingRulePayload>(() => {
  const base = baseAmount.value
  const frag = (props.tx.description ?? '').slice(0, 40)
  return {
    name: props.tx.counterparty_name ?? '',
    is_active: true,
    direction: props.tx.amount > 0 ? 'incoming' : 'outgoing',
    counterparty_account: props.tx.counterparty_account,
    counterparty_bank: props.tx.counterparty_bank,
    variable_symbol: props.tx.variable_symbol,
    message_contains: frag || null,
    amount_min: Math.floor(base * 0.9),
    amount_max: Math.ceil(base * 1.1),
    priority: 100,
    operation_type: null,
    auto_amount_cap: null,
    applies_currency: props.tx.currency || 'CZK',
    counterparty_prefix: null,
    debit_account_code: props.debitAccountCode,
    credit_account_code: props.creditAccountCode,
    description: null,
    mode: 'suggest',
  }
})

function onSaved() {
  showModal.value = false
  emit('created')
  emit('close')
}
</script>

<template>
  <div class="bg-primary-50 border border-primary-500/40 rounded-lg px-4 py-3 flex items-center justify-between gap-3">
    <span class="text-sm text-neutral-700">
      💡 {{ t('bank.posting.hint_title', { count }) }}
    </span>
    <div class="flex items-center gap-2 shrink-0">
      <button @click="showModal = true"
        class="cursor-pointer h-8 px-3 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
        {{ t('bank.posting.hint_cta') }}
      </button>
      <button @click="emit('close')"
        class="cursor-pointer h-8 px-3 text-sm border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded-md">
        {{ t('common.close') }}
      </button>
    </div>

    <RuleFormModal v-if="showModal" :prefill="prefill" :base-amount="baseAmount"
      @saved="onSaved" @close="showModal = false" />
  </div>
</template>
