<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { cashApi, type CashRegister } from '@/api/cash'
import { cashErrorCode, cashErrorMessage } from '@/api/cashErrors'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'

/**
 * Modal správy pokladen (§6.4). CRUD nad cash_registers; analytika 211 z osnovy,
 * měna skrytá (v1 CZK-only, O4). Smazání jen bez dokladů (409 → nabídka deaktivace).
 */
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>()

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()

// Daňová evidence (Epic DE §6): pokladna nemá journal → nepotřebuje účet 211 v osnově
// (COA se pro tax_evidence neseeduje). Účet je pak volitelný a select se skryje.
const isTaxEvidence = computed(() => supplierStore.currentSupplier?.accounting_mode === 'tax_evidence')

const registers = ref<CashRegister[]>([])
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')

// Analytiky 211 z osnovy (aktivní) — nabídka pro select.
const cashAccounts = computed(() =>
  accounts.value
    .filter(a => a.is_active && a.account_code.startsWith('211'))
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

const form = ref({ name: '', account_code: '', currency_code: 'CZK', is_default: false })
// Valutová pokladna (§11): u cizí měny je analytika volitelná (BE ji přidělí/dohraje automaticky).
const isForeignForm = computed(() => form.value.currency_code !== 'CZK')

async function load() {
  loading.value = true
  try {
    // G6 (audit 2026-07): osnova je double_entry-only (tax_evidence firma ji nemá
    // naseedovanou a GET /api/accounting/accounts je teď pro ni gatovaný 403) —
    // v DE se účet stejně nenabízí (viz isTaxEvidence výše), takže se ani nefetchuje.
    const [regs, accs] = await Promise.all([
      cashApi.listRegisters(true),
      isTaxEvidence.value ? Promise.resolve<ChartAccount[]>([]) : accountingApi.listAccounts(),
    ])
    registers.value = regs
    accounts.value = accs
    if (!form.value.account_code && cashAccounts.value.length > 0) {
      form.value.account_code = cashAccounts.value[0].account_code
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function mapError(e: any): string {
  return cashErrorMessage(e, t)
}

async function create() {
  error.value = ''
  if (!form.value.name.trim()) { error.value = t('cash.validation.name'); return }
  if (!isTaxEvidence.value && !isForeignForm.value && !form.value.account_code) { error.value = t('cash.validation.account'); return }
  saving.value = true
  try {
    await cashApi.createRegister({
      name: form.value.name.trim(),
      account_code: form.value.account_code,
      currency_code: form.value.currency_code,
      is_default: form.value.is_default,
    })
    toast.success(t('common.saved'))
    form.value = { name: '', account_code: cashAccounts.value[0]?.account_code ?? '', currency_code: 'CZK', is_default: false }
    await load()
    emit('changed')
  } catch (e: any) {
    error.value = mapError(e)
  } finally {
    saving.value = false
  }
}

async function setDefault(r: CashRegister) {
  try {
    await cashApi.updateRegister(r.id, { is_default: true })
    await load()
    emit('changed')
  } catch (e: any) {
    toast.error(mapError(e))
  }
}

async function toggleActive(r: CashRegister) {
  try {
    await cashApi.updateRegister(r.id, { is_active: !r.is_active })
    await load()
    emit('changed')
  } catch (e: any) {
    toast.error(mapError(e))
  }
}

async function remove(r: CashRegister) {
  if (!confirm(t('cash.register_delete_confirm', { name: r.name }))) return
  try {
    await cashApi.deleteRegister(r.id)
    toast.success(t('common.saved'))
    await load()
    emit('changed')
  } catch (e: any) {
    if (cashErrorCode(e) === 'register_has_documents') {
      toast.warning(t('cash.register_deactivate_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-2xl w-full my-8">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ t('cash.registers_manage') }}</h3>
        <button @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div class="p-5 space-y-5">
        <!-- Seznam pokladen -->
        <div v-if="loading" class="text-center text-neutral-500 py-6 text-sm">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="registers.length === 0" dense accent="neutral" icon="coin" :title="t('cash.register_empty')" />
        <div v-else class="overflow-x-auto border border-neutral-200 rounded-lg">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('cash.register_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('cash.register_account') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('cash.balance') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('cash.register_default') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('cash.status.posted') }}</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in registers" :key="r.id" :class="{ 'opacity-50': !r.is_active }">
                <td class="px-3 py-2">{{ r.name }}</td>
                <td class="px-3 py-2">
                  <span class="font-mono">{{ r.account_code }}</span>
                  <span class="text-neutral-500 ml-1">{{ r.account_name }}</span>
                  <span v-if="r.currency_code && r.currency_code !== 'CZK'"
                    class="ml-1 text-xs font-mono px-1 rounded bg-primary-50 text-primary-700">{{ r.currency_code }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ formatMoney(r.balance) }}
                  <span v-if="r.balance_foreign != null" class="block text-xs text-neutral-500">
                    {{ formatMoney(r.balance_foreign) }} {{ r.currency_code }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center">
                  <input type="radio" :checked="r.is_default" :disabled="r.is_default || !r.is_active"
                    @change="setDefault(r)" class="cursor-pointer" />
                </td>
                <td class="px-3 py-2 text-center">
                  <button type="button" @click="toggleActive(r)"
                    class="cursor-pointer text-xs px-2 py-0.5 rounded font-medium"
                    :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                    {{ r.is_active ? t('common.yes') : t('common.no') }}
                  </button>
                </td>
                <td class="px-3 py-2 text-right">
                  <button type="button" @click="remove(r)" :title="t('common.delete')"
                    class="cursor-pointer text-danger-500 hover:text-danger-600">✕</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Formulář nové pokladny -->
        <div class="border-t border-neutral-200 pt-4">
          <h4 class="text-sm font-medium text-neutral-700 mb-3">{{ t('cash.register_create') }}</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div :class="isTaxEvidence ? 'sm:col-span-2' : ''">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.register_name') }}</label>
              <input v-model="form.name" type="text"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="!isTaxEvidence">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.register_currency') }}</label>
              <select v-model="form.currency_code"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="CZK">CZK</option>
                <option value="EUR">EUR</option>
                <option value="USD">USD</option>
                <option value="GBP">GBP</option>
              </select>
            </div>
            <div v-if="!isTaxEvidence" class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1">
                {{ t('cash.register_account') }}
                <span v-if="isForeignForm" class="text-neutral-400">({{ t('common.optional') }})</span>
              </label>
              <select v-if="!isForeignForm" v-model="form.account_code"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="" disabled>—</option>
                <option v-for="a in cashAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
              </select>
              <input v-else v-model="form.account_code" type="text" placeholder="211.500"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              <p v-if="isForeignForm" class="text-xs text-neutral-500 mt-1">{{ t('cash.register_foreign_hint') }}</p>
              <RouterLink v-else to="/accounting/accounts" class="text-xs text-primary-600 hover:text-primary-700 mt-1 inline-block">
                {{ t('cash.register_account') }} +
              </RouterLink>
            </div>
          </div>
          <div class="flex items-center justify-between mt-3">
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700 cursor-pointer">
              <input v-model="form.is_default" type="checkbox" class="cursor-pointer" />
              {{ t('cash.register_default') }}
            </label>
            <button type="button" @click="create" :disabled="saving"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md disabled:opacity-40">
              {{ saving ? t('common.saving') : t('cash.register_create') }}
            </button>
          </div>
          <div v-if="error" class="text-sm text-danger-500 mt-2">{{ error }}</div>
        </div>
      </div>

      <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end">
        <button @click="emit('close')"
          class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface">{{ t('common.close') }}</button>
      </footer>
    </div>
  </div>
</template>
