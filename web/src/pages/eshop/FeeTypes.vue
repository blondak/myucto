<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type FeeType, type FeeTypePayload } from '@/api/eshop'
import { codebooksApi, type VatRate } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const feeTypes = ref<FeeType[]>([])
const vatRates = ref<VatRate[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const [fetchedFeeTypes, fetchedVatRates] = await Promise.all([
      eshopApi.listFeeTypes(),
      codebooksApi.vatRates('CZ').catch(() => [])
    ])
    feeTypes.value = fetchedFeeTypes
    vatRates.value = fetchedVatRates
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
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
  return e?.response?.data?.error?.message || t('common.error')
}

// ── Modal: založit / upravit ────────────────────────────────────────────
const modalOpen = ref(false)
const editing = ref<FeeType | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<FeeTypePayload>({ code: '', name: '', vat_rate_id: null, archived: false })
const formActive = ref(true)

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', vat_rate_id: vatRates.value.find(v => v.is_default)?.id || null, archived: false }
  formActive.value = true
  error.value = ''
  modalOpen.value = true
}

function openEdit(ft: FeeType) {
  editing.value = ft
  form.value = { code: ft.code, name: ft.name, vat_rate_id: ft.vat_rate_id, archived: ft.archived }
  formActive.value = !ft.archived
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.fee_types.field_code') + ' / ' + t('eshop.fee_types.field_name')
    return
  }
  saving.value = true
  form.value.archived = !formActive.value
  try {
    if (editing.value) {
      await eshopApi.updateFeeType(editing.value.id, form.value)
    } else {
      await eshopApi.createFeeType(form.value)
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

async function remove(ft: FeeType) {
  if (!confirm(t('eshop.fee_types.delete_confirm', { name: ft.name }))) return
  try {
    await eshopApi.deleteFeeType(ft.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'fee_type_in_use' || code === 'eshop.error.fee_type_in_use') {
      toast.warning(t('eshop.fee_types.in_use_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}

function getVatLabel(vatRateId: number | null): string {
  if (!vatRateId) return '-'
  const rate = vatRates.value.find(v => v.id === vatRateId)
  return rate ? `${rate.rate_percent} %` : '-'
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.fee_types.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.fee_types.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.fee_types.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="feeTypes.length === 0" boxed icon="coin"
      :title="t('eshop.fee_types.empty_title')"
      :message="t('eshop.fee_types.empty_hint')"
      :cta="auth.canWrite('eshop.write') ? t('eshop.fee_types.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.fee_types.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.fee_types.col_name') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.fee_types.col_vat_rate') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.fee_types.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="ft in feeTypes" :key="ft.id" :class="{ 'opacity-50': ft.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ ft.code }}</td>
              <td class="px-3 py-2 font-medium">{{ ft.name }}</td>
              <td class="px-3 py-2 text-center font-mono">{{ getVatLabel(ft.vat_rate_id) }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!ft.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !ft.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openEdit(ft)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(ft)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.fee_types.edit') : t('eshop.fee_types.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.fee_types.field_code')"
          :name-label="t('eshop.fee_types.field_name')"
          :editing="!!editing"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.fee_types.field_vat_rate') }}</label>
          <select v-model="form.vat_rate_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option :value="null">-- {{ t('eshop.fee_types.no_vat') }} --</option>
            <option v-for="rate in vatRates" :key="rate.id" :value="rate.id">{{ rate.rate_percent }} %</option>
          </select>
        </div>
        <div class="flex items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.fee_types.field_active') }}
          </label>
        </div>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex justify-end gap-2 pt-2 border-t border-neutral-100">
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
