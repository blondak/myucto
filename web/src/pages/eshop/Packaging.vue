<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type PackagingUnit, type PackagingUnitPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const units = ref<PackagingUnit[]>([])
const loading = ref(false)
const canWrite = computed(() => auth.canWrite('eshop.write'))

async function load() {
  loading.value = true
  try {
    units.value = await eshopApi.listPackagingUnits()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function errorCode(e: any): string {
  const code = String(e?.response?.data?.error?.code ?? '')
  return code.startsWith('eshop.error.') ? code.slice('eshop.error.'.length) : code
}

function mapError(e: any): string {
  const code = errorCode(e)
  if (code) {
    const key = `eshop.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  return e?.response?.data?.error?.message || t('common.error')
}

// ── Modal: založit / upravit ────────────────────────────────────────────
const modalOpen = ref(false)
const editing = ref<PackagingUnit | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<Required<PackagingUnitPayload>>({ code: '', name: '', is_active: true, display_order: 0 })
const codeLocked = computed(() => !!editing.value && editing.value.usage_count > 0)
const takenCodes = computed(() => units.value.filter(u => u.id !== editing.value?.id).map(u => u.code))

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', is_active: true, display_order: (units.value.length + 1) * 10 }
  error.value = ''
  modalOpen.value = true
}

function openEdit(unit: PackagingUnit) {
  editing.value = unit
  form.value = { code: unit.code, name: unit.name, is_active: unit.is_active, display_order: unit.display_order }
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.packaging.field_code') + ' / ' + t('eshop.packaging.field_name')
    return
  }
  saving.value = true
  const payload: PackagingUnitPayload = {
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    is_active: form.value.is_active,
    display_order: Number(form.value.display_order) || 0,
  }
  try {
    if (editing.value) {
      await eshopApi.updatePackagingUnit(editing.value.id, payload)
    } else {
      await eshopApi.createPackagingUnit(payload)
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

/** Použité balení nejde smazat, místo toho nabídneme deaktivaci (na kartách zůstane). */
async function deactivate(unit: PackagingUnit) {
  try {
    await eshopApi.updatePackagingUnit(unit.id, {
      code: unit.code,
      name: unit.name,
      is_active: false,
      display_order: unit.display_order,
    })
    toast.success(t('eshop.packaging.deactivated'))
    await load()
  } catch (e: any) {
    toast.error(mapError(e))
  }
}

async function remove(unit: PackagingUnit) {
  if (unit.usage_count > 0) {
    if (unit.is_active && confirm(t('eshop.packaging.in_use_deactivate_confirm', { name: unit.name, count: unit.usage_count }))) {
      await deactivate(unit)
    } else if (!unit.is_active) {
      toast.warning(t('eshop.packaging.in_use_hint'))
    }
    return
  }
  if (!confirm(t('eshop.packaging.delete_confirm', { name: unit.name }))) return
  try {
    await eshopApi.deletePackagingUnit(unit.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    if (errorCode(e) === 'packaging_unit_in_use') {
      if (unit.is_active && confirm(t('eshop.packaging.in_use_deactivate_confirm', { name: unit.name, count: unit.usage_count }))) {
        await deactivate(unit)
      } else {
        toast.warning(t('eshop.packaging.in_use_hint'))
      }
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
        <h1 class="text-2xl font-semibold">{{ t('eshop.packaging.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.packaging.subtitle') }}</p>
      </div>
      <button v-if="canWrite" type="button" @click="openCreate" :class="btnFilled('primary')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.packaging.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="units.length === 0" boxed icon="tag"
      :title="t('eshop.packaging.empty_title')"
      :message="t('eshop.packaging.empty_hint')"
      :cta="canWrite ? t('eshop.packaging.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.packaging.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.packaging.col_name') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('eshop.packaging.col_order') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('eshop.packaging.col_usage') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.packaging.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="unit in units" :key="unit.id" :class="{ 'opacity-50': !unit.is_active }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ unit.code }}</td>
              <td class="px-3 py-2 font-medium">{{ unit.name }}</td>
              <td class="px-3 py-2 text-right font-mono text-neutral-500">{{ unit.display_order }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ unit.usage_count }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="unit.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ unit.is_active ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="canWrite">
                <button type="button" @click="openEdit(unit)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(unit)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.packaging.edit') : t('eshop.packaging.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.packaging.field_code')"
          :name-label="t('eshop.packaging.field_name')"
          :editing="!!editing"
          code-mode="code"
          :code-maxlength="20"
          :name-maxlength="100"
          :taken-codes="takenCodes"
          :code-disabled="codeLocked"
          :code-hint="codeLocked ? t('eshop.packaging.code_locked_hint') : t('eshop.packaging.code_hint')"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.packaging.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" step="1" class="w-32 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
        </div>
        <label class="inline-flex items-center gap-2 text-sm cursor-pointer pt-1">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('eshop.packaging.field_active') }}
        </label>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button type="button" @click="modalOpen = false" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="save" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
