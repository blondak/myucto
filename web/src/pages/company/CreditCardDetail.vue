<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { importCreditCardStatement } from './creditCardImport'
import {
  creditCardsApi,
  type CreditCardDetail,
  type CreditCardPayload,
  type CreditCardTransaction,
  type CreditCardTransactionKind,
} from '@/api/creditCards'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()

const accountId = computed(() => Number(route.params.id))
const account = ref<CreditCardDetail | null>(null)
const loading = ref(true)
const saving = ref(false)
const busy = ref(false)
const importing = ref(false)
const analyticBusy = ref(false)
const errors = ref<Record<string, string>>({})
const fileInput = ref<HTMLInputElement | null>(null)

const canWrite = computed(() => auth.canWrite('settings.bank_accounts'))
const canPost = computed(() => auth.canWrite('bank.post'))
const canImport = computed(() => auth.canWrite('bank.import'))
const readOnly = computed(() => !canWrite.value || !!account.value?.archived)

const form = reactive<CreditCardPayload>({
  label: '', credit_limit: null, repayment_account: null, repayment_bank_code: null, repayment_vs: null, note: null,
})
const analyticChoice = ref<string>('')

function fill(a: CreditCardDetail) {
  account.value = a
  Object.assign(form, {
    label: a.label, credit_limit: a.credit_limit, repayment_account: a.repayment_account,
    repayment_bank_code: a.repayment_bank_code, repayment_vs: a.repayment_vs, note: a.note,
  })
  analyticChoice.value = a.account_code ?? ''
}

async function load() {
  loading.value = true
  try {
    fill(await creditCardsApi.get(accountId.value))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    fill(await creditCardsApi.update(accountId.value, {
      ...form,
      credit_limit: form.credit_limit === null || (form.credit_limit as unknown) === '' ? null : Number(form.credit_limit),
      repayment_account: form.repayment_account?.trim() || null,
      repayment_bank_code: form.repayment_bank_code?.trim() || null,
      repayment_vs: form.repayment_vs?.trim() || null,
      note: form.note?.trim() || null,
    }))
    toast.success(t('credit_cards.saved'))
  } catch (e: any) {
    errors.value = e?.response?.data?.error?.errors ?? {}
    toast.error(apiErrorMessage(e, t('credit_cards.save_failed')))
  } finally {
    saving.value = false
  }
}

async function archive() {
  if (!account.value || !window.confirm(t('credit_cards.archive_confirm'))) return
  busy.value = true
  try {
    fill(await creditCardsApi.archive(accountId.value))
    toast.success(t('credit_cards.archived_toast'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.save_failed')))
  } finally {
    busy.value = false
  }
}

async function restore() {
  busy.value = true
  try {
    fill(await creditCardsApi.restore(accountId.value))
    toast.success(t('credit_cards.restored_toast'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.save_failed')))
  } finally {
    busy.value = false
  }
}

async function changeAnalytic() {
  if (!analyticChoice.value) return
  analyticBusy.value = true
  try {
    fill(await creditCardsApi.setAnalytic(accountId.value, analyticChoice.value))
    toast.success(t('credit_cards.analytic_changed'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.analytic_change_failed')))
  } finally {
    analyticBusy.value = false
  }
}

async function onFile(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  importing.value = true
  try {
    const { result } = await importCreditCardStatement(file, accountId.value, msg => window.confirm(msg))
    toast.success(result.duplicate ? t('credit_cards.imported_duplicate') : t('credit_cards.imported', { n: result.transactions }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.import_failed')))
  } finally {
    importing.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'save', label: account.value && !account.value.is_verified ? t('credit_cards.save_verify') : t('credit_cards.save'),
    icon: 'check', tier: 'primary', variant: 'primary',
    run: save, loading: saving.value, disabled: saving.value || loading.value,
    show: canWrite.value && !account.value?.archived,
  },
  {
    key: 'restore', label: t('credit_cards.restore'), icon: 'uturn', tier: 'primary', variant: 'success',
    run: restore, loading: busy.value, show: canWrite.value && !!account.value?.archived,
  },
  {
    key: 'import', label: importing.value ? t('credit_cards.importing') : t('credit_cards.import'), icon: 'upload',
    tier: 'secondary', variant: 'neutral', title: t('credit_cards.import_hint'),
    run: () => fileInput.value?.click(), loading: importing.value, disabled: importing.value,
    show: canImport.value && !account.value?.archived,
  },
  {
    key: 'archive', label: t('credit_cards.archive'), icon: 'archive', tier: 'overflow', variant: 'warning',
    run: archive, disabled: busy.value, show: canWrite.value && !!account.value && !account.value.archived,
  },
])

const KIND_CLASS: Record<CreditCardTransactionKind, string> = {
  purchase: 'bg-neutral-100 text-neutral-700 ring-neutral-500/20',
  refund: 'bg-success-50 text-success-700 ring-success-600/20',
  repayment: 'bg-primary-50 text-primary-700 ring-primary-600/20',
  interest: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  fee: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  cash: 'bg-neutral-100 text-neutral-700 ring-neutral-500/20',
  reward: 'bg-success-50 text-success-700 ring-success-600/20',
}

type Posting = 'posted' | 'suggested' | 'ignored' | 'unposted'
function postingOf(tx: CreditCardTransaction): Posting {
  if (tx.entry_id !== null) return 'posted'
  if (tx.match_status === 'ignored') return 'ignored'
  return tx.suggestion_id !== null ? 'suggested' : 'unposted'
}
const POSTING_CLASS: Record<Posting, string> = {
  posted: 'bg-success-50 text-success-700 ring-success-600/20',
  suggested: 'bg-primary-50 text-primary-700 ring-primary-600/20',
  ignored: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
  unposted: 'bg-warning-50 text-warning-700 ring-warning-600/20',
}

/** Typ transakce z výpisu = první část popisu; zbytek jsou podrobnosti (datum transakce, původní měna). */
function detailOf(tx: CreditCardTransaction): string {
  return (tx.description ?? '').split(' | ').slice(1).join(' · ')
}

const differenceOk = computed(() => account.value?.balance_difference !== null && Math.abs(account.value?.balance_difference ?? 0) < 0.005)

const INPUT = 'h-9 w-full px-3 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50'
</script>

<template>
  <div>
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div class="min-w-0">
        <RouterLink :to="{ name: 'credit-cards' }" class="mb-1 inline-block text-sm text-primary-700 hover:underline">← {{ t('credit_cards.back') }}</RouterLink>
        <h1 class="text-2xl font-semibold truncate">{{ account?.label || t('credit_cards.title') }}</h1>
        <p v-if="account" class="text-sm text-neutral-500 mt-0.5">
          {{ t(`credit_cards.issuer.${account.issuer}`) }} ·
          <span class="font-mono">{{ account.account_number }}{{ account.bank_code ? '/' + account.bank_code : '' }}</span>
        </p>
      </div>
      <ActionBar :actions="actions" />
      <input ref="fileInput" type="file" accept="application/pdf,.pdf" class="hidden" @change="onFile" />
    </div>

    <div v-if="account?.archived" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('credit_cards.archived_notice') }}
    </div>
    <div v-else-if="account && !account.is_verified" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('credit_cards.unverified_notice') }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="account">
      <div class="grid gap-4 lg:grid-cols-2">
        <form class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3" @submit.prevent="save">
          <fieldset :disabled="readOnly" class="space-y-3">
            <legend class="text-sm font-semibold text-neutral-700">{{ t('credit_cards.section_account') }}</legend>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_label') }}</span>
              <input v-model="form.label" type="text" maxlength="120" :class="INPUT" required />
              <span v-if="errors.label" class="block text-xs text-danger-600 mt-1">{{ errors.label }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_limit') }}</span>
              <input v-model="form.credit_limit" type="number" min="0" step="0.01" :class="INPUT" />
              <span v-if="errors.credit_limit" class="block text-xs text-danger-600 mt-1">{{ errors.credit_limit }}</span>
            </label>
            <div class="grid gap-3 sm:grid-cols-3">
              <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_repayment_account') }}</span>
                <input v-model="form.repayment_account" type="text" maxlength="40" :class="[INPUT, 'font-mono']" />
                <span v-if="errors.repayment_account" class="block text-xs text-danger-600 mt-1">{{ errors.repayment_account }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_repayment_bank') }}</span>
                <input v-model="form.repayment_bank_code" type="text" inputmode="numeric" maxlength="4" :class="[INPUT, 'font-mono']" />
                <span v-if="errors.repayment_bank_code" class="block text-xs text-danger-600 mt-1">{{ errors.repayment_bank_code }}</span>
              </label>
            </div>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_repayment_vs') }}</span>
              <input v-model="form.repayment_vs" type="text" inputmode="numeric" maxlength="10" :class="[INPUT, 'font-mono']" />
              <span class="block text-xs text-neutral-500 mt-1">{{ t('credit_cards.repayment_hint') }}</span>
              <span v-if="errors.repayment_vs" class="block text-xs text-danger-600 mt-1">{{ errors.repayment_vs }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.field_note') }}</span>
              <textarea v-model="form.note" rows="2" maxlength="500"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50"></textarea>
            </label>
          </fieldset>
        </form>

        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3" data-testid="credit-card-ledger">
          <h2 class="text-sm font-semibold text-neutral-700">{{ t('credit_cards.section_ledger') }}</h2>
          <div class="grid gap-3 sm:grid-cols-2 text-sm">
            <div>
              <div class="text-xs text-neutral-500">{{ t('credit_cards.analytic') }}</div>
              <div class="font-mono">{{ account.account_code ?? t('credit_cards.analytic_none') }}</div>
            </div>
            <div>
              <div class="text-xs text-neutral-500">{{ t('credit_cards.ledger_balance') }}</div>
              <div class="font-medium">{{ account.ledger_balance !== null ? formatMoney(account.ledger_balance) : '-' }}</div>
            </div>
            <div>
              <div class="text-xs text-neutral-500">{{ t('credit_cards.last_statement_balance') }}</div>
              <div>
                <template v-if="account.last_statement">
                  {{ formatMoney(account.last_statement.curr_balance) }}
                  <span class="text-xs text-neutral-500">({{ formatDate(account.last_statement.statement_date) }})</span>
                </template>
                <template v-else>-</template>
              </div>
            </div>
            <div>
              <div class="text-xs text-neutral-500">{{ t('credit_cards.balance_check') }}</div>
              <div v-if="account.balance_difference === null" class="text-neutral-500">-</div>
              <div v-else :class="differenceOk ? 'text-success-700' : 'text-warning-700'">
                {{ differenceOk ? t('credit_cards.difference_ok') : t('credit_cards.difference', { amount: formatMoney(account.balance_difference) }) }}
              </div>
            </div>
          </div>
          <p class="text-xs text-neutral-500">{{ t('credit_cards.ledger_help') }}</p>
          <div v-if="canPost && !account.archived" class="flex flex-wrap items-end gap-2">
            <label class="block text-sm">
              <span class="text-xs font-medium text-neutral-600">{{ t('credit_cards.analytic_change_label') }}</span>
              <select v-model="analyticChoice" :class="INPUT" class="min-w-[16rem]">
                <option value="" disabled>{{ t('credit_cards.analytic_pick') }}</option>
                <option v-for="o in account.analytic_options.filter(x => x.credit_card_account_id === null || x.credit_card_account_id === account?.id)"
                  :key="o.id" :value="o.account_code">{{ o.account_code }} - {{ o.name }}</option>
              </select>
            </label>
            <button type="button" :class="btnOutline('warning')" class="whitespace-nowrap"
              :disabled="analyticBusy || !analyticChoice || analyticChoice === account.account_code" @click="changeAnalytic()">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
              </svg>
              {{ t('credit_cards.analytic_change') }}
            </button>
          </div>
        </section>
      </div>

      <section class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <h2 class="px-4 pt-4 pb-2 text-sm font-semibold text-neutral-700">{{ t('credit_cards.section_statements') }}</h2>
        <div v-if="account.statements.length === 0" class="px-4 pb-4 text-sm text-neutral-500">{{ t('credit_cards.statements_empty') }}</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_statement_date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_statement_number') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_prev') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_curr') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_count') }}</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in account.statements" :key="s.id">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(s.statement_date) }}</td>
                <td class="px-3 py-2">{{ s.statement_number || '-' }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ formatMoney(s.prev_balance) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ formatMoney(s.curr_balance) }}</td>
                <td class="px-3 py-2 text-right">{{ s.transaction_count }}</td>
                <td class="px-3 py-2 text-right">
                  <RouterLink :to="{ name: 'bank-detail', params: { id: s.id } }" :class="btnOutline('neutral')" class="whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" />
                    </svg>
                    {{ t('credit_cards.open_statement') }}
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <h2 class="px-4 pt-4 pb-2 text-sm font-semibold text-neutral-700">{{ t('credit_cards.section_transactions') }}</h2>
        <div v-if="account.transactions.length === 0" class="px-4 pb-4 text-sm text-neutral-500">{{ t('credit_cards.transactions_empty') }}</div>
        <template v-else>
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_date') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_kind') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_counterparty') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_detail') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_amount') }}</th>
                  <th class="px-3 py-2 text-center font-medium">{{ t('credit_cards.col_posting') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="tx in account.transactions" :key="tx.id">
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(tx.posted_at) }}</td>
                  <td class="px-3 py-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap" :class="KIND_CLASS[tx.kind]">
                      {{ t(`credit_cards.kind.${tx.kind}`) }}
                    </span>
                  </td>
                  <td class="px-3 py-2">
                    {{ tx.counterparty_name || tx.counterparty_account || '-' }}
                    <span v-if="tx.card_last4" class="ml-1 font-mono text-xs text-neutral-500">{{ t('credit_cards.masked', { last4: tx.card_last4 }) }}</span>
                  </td>
                  <td class="px-3 py-2 text-xs text-neutral-600">{{ detailOf(tx) || '-' }}</td>
                  <td class="px-3 py-2 text-right whitespace-nowrap font-medium" :class="tx.amount < 0 ? 'text-neutral-900' : 'text-success-700'">
                    {{ formatMoney(tx.amount, tx.currency) }}
                  </td>
                  <td class="px-3 py-2 text-center">
                    <RouterLink :to="{ name: 'bank-detail', params: { id: tx.statement_id } }"
                      class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap hover:underline"
                      :class="POSTING_CLASS[postingOf(tx)]">
                      {{ t(`credit_cards.posting.${postingOf(tx)}`) }}
                    </RouterLink>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="md:hidden divide-y divide-neutral-100">
            <RouterLink v-for="tx in account.transactions" :key="`m-${tx.id}`" :to="{ name: 'bank-detail', params: { id: tx.statement_id } }"
              class="block px-4 py-3 hover:bg-neutral-50">
              <div class="flex items-center justify-between gap-2">
                <span class="truncate text-neutral-800">{{ tx.counterparty_name || t(`credit_cards.kind.${tx.kind}`) }}</span>
                <span class="font-medium whitespace-nowrap">{{ formatMoney(tx.amount, tx.currency) }}</span>
              </div>
              <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-600">
                <span>{{ formatDate(tx.posted_at) }}</span>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset" :class="KIND_CLASS[tx.kind]">{{ t(`credit_cards.kind.${tx.kind}`) }}</span>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset" :class="POSTING_CLASS[postingOf(tx)]">{{ t(`credit_cards.posting.${postingOf(tx)}`) }}</span>
              </div>
            </RouterLink>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>
