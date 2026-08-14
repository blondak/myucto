<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type EshopCurrency, type EshopCurrencyPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const currencies = ref<EshopCurrency[]>([])
const loading = ref(false)

/** Nabídka nejčastějších měn — jen předvyplnění, kód i název jdou přepsat. */
const SUGGESTIONS: { code: string; name: string; symbol: string }[] = [
  { code: 'CZK', name: 'Česká koruna', symbol: 'Kč' },
  { code: 'EUR', name: 'Euro', symbol: '€' },
  { code: 'USD', name: 'Americký dolar', symbol: '$' },
  { code: 'GBP', name: 'Britská libra', symbol: '£' },
  { code: 'PLN', name: 'Polský zlotý', symbol: 'zł' },
  { code: 'HUF', name: 'Maďarský forint', symbol: 'Ft' },
  { code: 'CHF', name: 'Švýcarský frank', symbol: 'CHF' },
  { code: 'SEK', name: 'Švédská koruna', symbol: 'kr' },
  { code: 'DKK', name: 'Dánská koruna', symbol: 'kr' },
  { code: 'NOK', name: 'Norská koruna', symbol: 'kr' },
]

async function load() {
  loading.value = true
  try {
    currencies.value = await eshopApi.listCurrencies()
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    loading.value = false
  }
}

onMounted(load)

function mapError(e: any): string {
  const code = e?.response?.data?.error?.code
  if (code) {
    const key = code.startsWith('eshop.error.') ? code : `eshop.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  const message = e?.response?.data?.error?.message
  if (message) return message
  // Fallback pro odpovědi bez chybové obálky (typicky 500 ze serveru): holé
  // „Chyba" nechává uživatele hádat, tak aspoň ukážeme, co server vrátil.
  const status = e?.response?.status
  return status ? `${t('common.error')} (HTTP ${status})` : t('common.error')
}

// ── Modal: založit / upravit ────────────────────────────────────────────
const modalOpen = ref(false)
const editing = ref<EshopCurrency | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<EshopCurrencyPayload>({ code: '', name: '', symbol: '', display_order: 0, is_default: false, archived: false })
const formActive = ref(true)

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', symbol: '', display_order: currencies.value.length * 10, is_default: currencies.value.length === 0, archived: false }
  formActive.value = true
  error.value = ''
  modalOpen.value = true
}

function openEdit(row: EshopCurrency) {
  editing.value = row
  form.value = {
    code: row.code,
    name: row.name,
    symbol: row.symbol ?? '',
    display_order: row.display_order,
    is_default: row.is_default,
    archived: row.archived,
  }
  formActive.value = !row.archived
  error.value = ''
  modalOpen.value = true
}

function applySuggestion(code: string) {
  const s = SUGGESTIONS.find(x => x.code === code)
  if (!s) return
  form.value.code = s.code
  if (!form.value.name.trim()) form.value.name = s.name
  if (!String(form.value.symbol ?? '').trim()) form.value.symbol = s.symbol
}

async function save() {
  error.value = ''
  const code = form.value.code.trim().toUpperCase()
  const name = form.value.name.trim()
  if (!/^[A-Z]{3}$/.test(code)) {
    error.value = t('eshop.currencies.code_invalid')
    return
  }
  if (!name) {
    error.value = t('eshop.currencies.name_required')
    return
  }
  saving.value = true
  form.value.code = code
  form.value.name = name
  form.value.symbol = String(form.value.symbol ?? '').trim() || null
  form.value.archived = !formActive.value
  if (form.value.archived) form.value.is_default = false
  try {
    if (editing.value) {
      await eshopApi.updateCurrency(editing.value.id, form.value)
    } else {
      await eshopApi.createCurrency(form.value)
    }
    toast.success(t('common.saved'))
    modalOpen.value = false
    await load()
  } catch (e: any) {
    error.value = mapError(e)
  } finally {
    saving.value = false
  }
}

async function remove(row: EshopCurrency) {
  if (!confirm(t('eshop.currencies.delete_confirm', { name: row.name }))) return
  try {
    await eshopApi.deleteCurrency(row.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'currency_in_use' || code === 'eshop.error.currency_in_use') {
      toast.warning(t('eshop.currencies.in_use_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.currencies.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.currencies.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.currencies.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="currencies.length === 0" boxed icon="doc"
      :title="t('eshop.currencies.empty_title')"
      :message="t('eshop.currencies.empty_hint')"
      :cta="auth.canWrite('eshop.write') ? t('eshop.currencies.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.currencies.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.currencies.col_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.currencies.col_symbol') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.currencies.col_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.currencies.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in currencies" :key="row.id" :class="{ 'opacity-50': row.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono uppercase">{{ row.code }}</td>
              <td class="px-3 py-2 font-medium">{{ row.name }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ row.symbol || '—' }}</td>
              <td class="px-3 py-2 text-center">
                <span v-if="row.is_default" class="text-xs px-2 py-0.5 rounded font-medium bg-primary-50 text-primary-700">{{ t('eshop.currencies.default_badge') }}</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!row.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !row.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openEdit(row)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(row)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.currencies.edit') : t('eshop.currencies.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <div v-if="!editing">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.currencies.field_suggestion') }}</label>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in SUGGESTIONS" :key="s.code" type="button" @click="applySuggestion(s.code)"
              class="cursor-pointer px-2.5 h-8 rounded-full border text-sm transition whitespace-nowrap"
              :class="form.code === s.code ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
              {{ s.code }} — {{ s.name }}
            </button>
          </div>
        </div>
        <div class="grid grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.currencies.field_code') }} *</label>
            <input v-model="form.code" type="text" maxlength="3" placeholder="CZK" :disabled="!!editing"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-100 disabled:text-neutral-500" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.currencies.field_name') }} *</label>
            <input v-model="form.name" type="text" maxlength="100" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.currencies.field_symbol') }}</label>
            <input v-model="form.symbol" type="text" maxlength="8" placeholder="Kč" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ t('eshop.currencies.code_hint') }}</p>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.currencies.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" step="1" min="0"
            class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
        </div>
        <div class="flex flex-wrap items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.currencies.field_active') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-50': !formActive }">
            <input v-model="form.is_default" type="checkbox" :disabled="!formActive" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.currencies.field_default') }}
          </label>
        </div>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button @click="modalOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button @click="save" :disabled="saving" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
