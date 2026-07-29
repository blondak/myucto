<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney, formatDate } from '@/composables/useFormat'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import type { Client } from '@/api/clients'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

const props = defineProps<{ actions: BankTransactionActions }>()
const { t } = useI18n()

const {
  createTx, createVendorId, vendorModalOpen, creatingPi,
  onVendorCreated, submitCreatePurchase, closeCreate,
} = props.actions

// Template ref na VendorPicker zůstává lokální (reload po vytvoření vendora) —
// composable drží jen business logiku, ne DOM/component instance.
const vendorPickerRef = ref<InstanceType<typeof VendorPicker> | null>(null)
function handleVendorCreated(client: Client) {
  onVendorCreated(client)
  vendorPickerRef.value?.reload()
}
</script>

<template>
  <div v-if="createTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
      <h3 class="text-lg font-semibold mb-1">{{ t('bank.create_purchase_title') }}</h3>
      <p class="text-xs text-neutral-500 mb-3">
        {{ formatMoney(Math.abs(createTx.amount), createTx.currency ?? 'CZK') }} ·
        {{ formatDate(createTx.posted_at) }}
        <span v-if="createTx.counterparty_name"> · {{ createTx.counterparty_name }}</span>
      </p>
      <VendorPicker ref="vendorPickerRef" v-model="createVendorId" :on-create-new="() => { vendorModalOpen = true }" />
      <p class="text-xs text-neutral-500 mt-2 mb-4">{{ t('bank.create_purchase_hint') }}</p>
      <div class="flex justify-end gap-2">
        <button @click="closeCreate" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
        <button @click="submitCreatePurchase" :disabled="!createVendorId || creatingPi"
          class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ creatingPi ? '…' : t('bank.create_purchase_submit') }}
        </button>
      </div>
    </div>
  </div>

  <ClientFormModal v-if="vendorModalOpen"
    :defaults="{ is_vendor: true, is_customer: false, company_name: createTx?.counterparty_name || '' }"
    @created="handleVendorCreated"
    @close="vendorModalOpen = false" />
</template>
