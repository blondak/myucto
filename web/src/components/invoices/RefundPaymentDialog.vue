<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import {
  paymentOrdersApi,
  type PaymentOrderFormat,
  type PaymentOrderListItem,
  type PaymentOrderView,
  type RefundOrderPrefill,
  type RefundSuggestedAccount,
} from '@/api/paymentOrders'
import { bankConnectionsApi } from '@/api/bankConnections'
import { clientsApi } from '@/api/clients'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { bankNameByCode } from '@/utils/czBankCodes'
import { appIsoDate } from '@/utils/date'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import BankPaymentSubmission from '@/components/bank/BankPaymentSubmission.vue'

const props = defineProps<{ invoiceId: number }>()
const emit = defineEmits<{ close: []; ordered: [orderId: number] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const MANUAL = 'manual' as const

const loading = ref(true)
const loadError = ref('')
const prefill = ref<RefundOrderPrefill | null>(null)
const accountChoice = ref<number | typeof MANUAL>(MANUAL)
const manualAccount = ref('')
const manualBankCode = ref('')
const saveToClient = ref(true)
const payerId = ref<number | ''>('')
const paymentDate = ref(appIsoDate())
const busy = ref(false)
const order = ref<PaymentOrderView | null>(null)
const hasBankApi = ref(false)
const bankOrderId = ref<number | null>(null)

const canWrite = computed(() => auth.canWrite('purchase_invoices.payment_orders'))
const canSubmitToBank = computed(() => canWrite.value && auth.canWrite('settings.bank_accounts') && hasBankApi.value)

const chosenAccount = computed<RefundSuggestedAccount | null>(() =>
  accountChoice.value === MANUAL ? null : prefill.value?.accounts.find(a => a.id === accountChoice.value) ?? null,
)

const payee = computed(() => chosenAccount.value
  ? { account_number: chosenAccount.value.account_number ?? '', bank_code: chosenAccount.value.bank_code ?? '', iban: chosenAccount.value.iban ?? undefined }
  : { account_number: manualAccount.value.trim(), bank_code: manualBankCode.value.trim(), iban: undefined })

const payeeComplete = computed(() => payee.value.account_number !== '' && payee.value.bank_code !== '')

const orderListItem = computed<PaymentOrderListItem[]>(() => order.value ? [{
  id: order.value.id,
  currency: order.value.currency,
  payment_date: order.value.payment_date,
  total_amount: order.value.total_amount,
  item_count: order.value.item_count,
  mark_paid: order.value.mark_paid,
  note: order.value.note,
  created_at: order.value.created_at,
  payer_account_label: order.value.payer.label,
  payer_account_number: order.value.payer.account_number,
  payer_bank_code: order.value.payer.bank_code,
  payer_iban: order.value.payer.iban,
}] : [])

function accountLabel(a: { account_number: string | null; bank_code: string | null; iban: string | null }): string {
  if (a.account_number) return a.bank_code ? `${a.account_number}/${a.bank_code}` : a.account_number
  return a.iban ?? ''
}

onMounted(async () => {
  try {
    prefill.value = await paymentOrdersApi.refundPrefill(props.invoiceId)
    const first = prefill.value.accounts.find(a => a.account_number && a.bank_code)
    accountChoice.value = first ? first.id : MANUAL
    const payers = prefill.value.payer_accounts
    payerId.value = (payers.find(a => a.is_default) ?? payers[0])?.id ?? ''
  } catch (e) {
    const code = apiErrorCode(e)
    loadError.value = code === 'refund_disabled' || code === 'not_refundable'
      ? t(`refundPayment.error_${code}`)
      : apiErrorMessage(e)
  } finally {
    loading.value = false
  }
  if (auth.canRead('settings.bank_accounts')) {
    try {
      const res = await bankConnectionsApi.list()
      hasBankApi.value = res.connections.some(c => c.enabled && c.has_token
        && res.providers.some(p => p.code === c.provider && p.implemented && p.capabilities.payment_order_submission))
    } catch {
      hasBankApi.value = false
    }
  }
})

async function ensureOrder(): Promise<PaymentOrderView | null> {
  if (order.value) return order.value
  if (!canWrite.value || busy.value || blockDemoMutation()) return null
  if (!payeeComplete.value) {
    toast.error(t('refundPayment.error_no_account'))
    return null
  }
  if (payerId.value === '') {
    toast.error(t('refundPayment.error_no_payer'))
    return null
  }
  busy.value = true
  try {
    const res = await paymentOrdersApi.createRefundOrder(props.invoiceId, {
      payer_currency_id: payerId.value,
      payment_date: paymentDate.value,
      account_number: payee.value.account_number,
      bank_code: payee.value.bank_code,
      ...(payee.value.iban ? { iban: payee.value.iban } : {}),
      save_to_client: accountChoice.value === MANUAL && saveToClient.value,
    })
    if (res.clamped_date) toast.warning(t('payment_order.date_clamped'))
    order.value = res.view
    emit('ordered', res.order_id)
    toast.success(t('refundPayment.order_created', { id: res.order_id }))
    return res.view
  } catch (e) {
    toast.error(apiErrorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

async function download(format: PaymentOrderFormat) {
  const created = await ensureOrder()
  if (created) paymentOrdersApi.downloadPaymentOrder(created.id, format)
}

async function sendToBank() {
  const created = await ensureOrder()
  if (created) bankOrderId.value = created.id
}

/** Doklad zůstane mezi kandidáty platebních příkazů; ručně zadaný účet se uloží ke klientovi. */
async function addToBatch() {
  if (accountChoice.value === MANUAL && payeeComplete.value && prefill.value && !blockDemoMutation()) {
    busy.value = true
    try {
      await clientsApi.addBankAccount(prefill.value.invoice.client_id, {
        account_number: payee.value.account_number,
        bank_code: payee.value.bank_code,
      })
    } catch (e) {
      toast.error(apiErrorMessage(e))
      return
    } finally {
      busy.value = false
    }
  }
  toast.info(t('refundPayment.added_to_batch'))
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 max-h-[90vh] overflow-y-auto">
      <h3 class="text-lg font-semibold mb-1">{{ t('refundPayment.title') }}</h3>
      <p v-if="prefill" class="text-sm text-neutral-500 mb-3">
        {{ prefill.invoice.client_company_name }} · {{ prefill.invoice.varsymbol }}
      </p>

      <div v-if="loading" class="text-sm text-neutral-500 py-6 text-center">{{ t('common.loading') }}</div>
      <div v-else-if="loadError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ loadError }}
      </div>

      <template v-else-if="prefill">
        <div class="rounded-md bg-primary-50 border border-primary-500/30 px-3 py-2 mb-4 flex items-baseline justify-between gap-3 flex-wrap">
          <span class="text-sm text-primary-700">{{ t('refundPayment.amount') }}</span>
          <span class="font-mono font-semibold text-primary-800">{{ formatMoney(prefill.amount, prefill.invoice.currency) }}</span>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('refundPayment.client_account') }}</label>
            <select v-model="accountChoice" :disabled="!!order"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
              <option v-for="a in prefill.accounts" :key="a.id" :value="a.id" :disabled="!a.account_number || !a.bank_code">
                {{ accountLabel(a) }}{{ a.bank_code && bankNameByCode(a.bank_code) ? ` (${bankNameByCode(a.bank_code)})` : '' }}
                · {{ t(`payment_order.refund_source.${a.source}`) }}
              </option>
              <option :value="MANUAL">{{ t('refundPayment.manual_account') }}</option>
            </select>
          </div>

          <div v-if="accountChoice === MANUAL" class="grid grid-cols-3 gap-2">
            <input v-model="manualAccount" type="text" :disabled="!!order" :placeholder="t('refundPayment.account_number')"
              class="col-span-2 h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono disabled:opacity-60" />
            <input v-model="manualBankCode" type="text" inputmode="numeric" maxlength="4" :disabled="!!order" :placeholder="t('refundPayment.bank_code')"
              class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono disabled:opacity-60" />
            <label class="col-span-3 flex items-center gap-1.5 text-sm text-neutral-700">
              <input v-model="saveToClient" type="checkbox" :disabled="!!order" class="rounded border-neutral-300 text-primary-600" />
              {{ t('refundPayment.save_to_client') }}
            </label>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.payer_account') }}</label>
            <select v-model="payerId" :disabled="!!order"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
              <option v-if="prefill.payer_accounts.length === 0" :value="''">{{ t('payment_order.no_payer_accounts') }}</option>
              <option v-for="a in prefill.payer_accounts" :key="a.id" :value="a.id">
                {{ a.label || a.code }} · {{ accountLabel(a) }}
              </option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.payment_date') }}</label>
              <DateInput v-model="paymentDate" :disabled="!!order"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.col_vs') }}</label>
              <input :value="prefill.variable_symbol" readonly :title="t('refundPayment.vs_hint')"
                class="w-full h-9 px-3 border border-neutral-200 rounded-md bg-neutral-50 text-sm font-mono text-neutral-600" />
            </div>
          </div>
          <p class="text-xs text-neutral-500">{{ t('refundPayment.vs_hint') }}</p>
        </div>

        <div v-if="order" class="mt-4 rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
          {{ t('refundPayment.order_created', { id: order.id }) }}
        </div>

        <div v-if="bankOrderId !== null" class="mt-4">
          <BankPaymentSubmission v-model="bankOrderId" :orders="orderListItem" />
        </div>

        <div v-if="!canWrite" class="mt-4 rounded-md bg-neutral-100 border border-neutral-200 px-3 py-2 text-sm text-neutral-500">
          {{ t('payment_order.readonly_hint') }}
        </div>

        <div class="flex flex-wrap justify-end gap-2 mt-5">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
          <template v-if="canWrite">
            <button v-if="!order" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="busy" @click="addToBatch">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('refundPayment.add_to_batch') }}
            </button>
            <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="busy" @click="download('csv')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              CSV
            </button>
            <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="busy" @click="download('pdf')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              PDF
            </button>
            <button v-if="canSubmitToBank" type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="busy || bankOrderId !== null" @click="sendToBank">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
              {{ t('refundPayment.send_to_bank') }}
            </button>
            <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="busy" @click="download('abo')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ busy ? '…' : t('refundPayment.download_abo') }}
            </button>
          </template>
        </div>
      </template>
    </div>
  </div>
</template>
