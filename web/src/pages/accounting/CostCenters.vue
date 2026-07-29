<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type CostCenter } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const items = ref<CostCenter[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const formOpen = ref(false)
const form = reactive({ code: '', name: '', is_active: true })
const centerSlug = useAutoSlug(value => { form.code = value }, { maxLen: 50 })

async function load() {
  loading.value = true
  error.value = ''
  try {
    items.value = await accountingApi.listCostCenters(true)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function startNew() {
  editingId.value = null
  Object.assign(form, { code: '', name: '', is_active: true })
  centerSlug.init('', false)
  formOpen.value = true
  error.value = ''
}

function startEdit(item: CostCenter) {
  editingId.value = item.id
  Object.assign(form, { code: item.code, name: item.name, is_active: item.is_active })
  centerSlug.init(item.code, true)
  formOpen.value = true
  error.value = ''
}

function cancel() { formOpen.value = false; editingId.value = null }

async function save() {
  error.value = ''
  if (!form.code.trim() || !form.name.trim()) {
    error.value = t('accounting.cost_centers.required')
    return
  }
  saving.value = true
  try {
    if (editingId.value === null) {
      await accountingApi.createCostCenter({ code: form.code.trim(), name: form.name.trim() })
    } else {
      await accountingApi.updateCostCenter(editingId.value, { name: form.name.trim(), is_active: form.is_active })
    }
    toast.success(t('common.saved'))
    cancel()
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    saving.value = false
  }
}

async function remove(item: CostCenter) {
  if (!confirm(t('accounting.cost_centers.delete_confirm', { code: item.code }))) return
  error.value = ''
  try {
    const result = await accountingApi.deleteCostCenter(item.id)
    toast.success(result.deleted ? t('common.deleted') : t('accounting.cost_centers.deactivated_due_to_usage'))
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-4"><h1 class="text-2xl font-semibold">{{ t('accounting.cost_centers.title') }}</h1></div>

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h2 v-if="embedded" class="text-xl font-semibold">{{ t('accounting.cost_centers.title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('accounting.cost_centers.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('accounting')" type="button" @click="startNew" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.cost_centers.new') }}
      </button>
    </div>

    <div v-if="error" class="mb-4 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="items.length === 0" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-500">{{ t('accounting.cost_centers.empty') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-600">
          <tr>
            <th class="px-4 py-3 text-left font-medium">{{ t('accounting.cost_centers.code') }}</th>
            <th class="px-4 py-3 text-left font-medium">{{ t('accounting.cost_centers.name') }}</th>
            <th class="px-4 py-3 text-left font-medium">{{ t('accounting.cost_centers.status') }}</th>
            <th class="px-4 py-3 text-right font-medium">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in items" :key="item.id" :class="{ 'opacity-60': !item.is_active }">
            <td class="px-4 py-3 font-mono font-medium">{{ item.code }}</td>
            <td class="px-4 py-3">{{ item.name }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                {{ item.is_active ? t('common.active') : t('common.inactive') }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div v-if="auth.canWrite('accounting')" class="flex flex-wrap justify-end gap-2">
                <button type="button" @click="startEdit(item)" :class="btnOutlineSm('neutral')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
                <button type="button" @click="remove(item)" :class="btnOutlineSm('danger')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  {{ t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="auth.canWrite('accounting') && formOpen" class="mt-5 bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <h3 class="text-lg font-semibold">{{ editingId === null ? t('accounting.cost_centers.new') : t('accounting.cost_centers.edit') }}</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.cost_centers.code') }}</label>
          <input v-model="form.code" type="text" maxlength="50" :disabled="editingId !== null" @input="centerSlug.markManual(form.code)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
          <p v-if="editingId !== null" class="text-xs text-neutral-400 mt-1">{{ t('accounting.cost_centers.code_immutable') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.cost_centers.name') }}</label>
          <input v-model="form.name" type="text" maxlength="255" @input="centerSlug.fromName(form.name)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <label v-if="editingId !== null" class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
        {{ t('accounting.cost_centers.active') }}
      </label>
      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" @click="cancel" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" @click="save" :disabled="saving" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </div>
  </div>
</template>
