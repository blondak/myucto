<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type Category, type CategoryPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

// Hierarchicky odsazený název pro výběr nadřazené kategorie (ne interní path).
function catLabel(c: Category): string {
  return (c.depth > 0 ? '  '.repeat(c.depth) + '↳ ' : '') + c.name
}
function catOptions(list: Category[]) {
  return list.map(c => ({ value: c.id, label: catLabel(c), secondary: c.code }))
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const categories = ref<Category[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    categories.value = await eshopApi.listCategories()
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
const editing = ref<Category | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<CategoryPayload>({
  parent_id: null,
  code: '',
  name: '',
  display_order: 10,
  export_eshop: true,
  archived: false
})
const formActive = ref(true)

const parentCandidates = computed(() => {
  if (!editing.value) return categories.value
  // Zamezit cyklické vazbě (nelze zvolit sám sebe jako parenta)
  return categories.value.filter(c => c.id !== editing.value?.id)
})

function openCreate() {
  editing.value = null
  form.value = {
    parent_id: null,
    code: '',
    name: '',
    display_order: 10,
    export_eshop: true,
    archived: false
  }
  formActive.value = true
  error.value = ''
  modalOpen.value = true
}

function openEdit(cat: Category) {
  editing.value = cat
  form.value = {
    parent_id: cat.parent_id,
    code: cat.code,
    name: cat.name,
    display_order: cat.display_order,
    export_eshop: cat.export_eshop,
    archived: cat.archived
  }
  formActive.value = !cat.archived
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.categories.field_code') + ' / ' + t('eshop.categories.field_name')
    return
  }
  saving.value = true
  form.value.archived = !formActive.value
  try {
    if (editing.value) {
      await eshopApi.updateCategory(editing.value.id, form.value)
    } else {
      await eshopApi.createCategory(form.value)
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

async function remove(cat: Category) {
  if (!confirm(t('eshop.categories.delete_confirm', { name: cat.name }))) return
  try {
    await eshopApi.deleteCategory(cat.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'category_in_use' || code === 'eshop.error.category_in_use') {
      toast.warning(t('eshop.categories.in_use_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}

// ── Přesun (reparent) přes /move endpoint — tlačítko, ne drag-drop ────────
const moveModalOpen = ref(false)
const moving = ref<Category | null>(null)
const moveParentId = ref<number | null>(null)
const moveSaving = ref(false)
const moveError = ref('')

const moveCandidates = computed(() => {
  // Nelze zvolit sám sebe ani vlastní podstrom (materialized path prefix).
  if (!moving.value) return categories.value
  const selfPath = moving.value.path
  return categories.value.filter(c => c.id !== moving.value?.id && !c.path.startsWith(selfPath + '/') && c.path !== selfPath)
})

function openMove(cat: Category) {
  moving.value = cat
  moveParentId.value = cat.parent_id
  moveError.value = ''
  moveModalOpen.value = true
}

async function doMove() {
  if (!moving.value) return
  moveSaving.value = true
  moveError.value = ''
  try {
    await eshopApi.moveCategory(moving.value.id, moveParentId.value)
    toast.success(t('common.saved'))
    moveModalOpen.value = false
    await load()
  } catch (e: any) {
    moveError.value = mapError(e)
  } finally {
    moveSaving.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.categories.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.categories.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.categories.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="categories.length === 0" class="text-center py-12">
      <p class="text-neutral-500 text-sm mb-3">{{ t('common.no_items') }}</p>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.categories.new') }}
      </button>
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.categories.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.categories.col_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.categories.col_path') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.categories.col_order') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.categories.col_export') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.categories.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="cat in categories" :key="cat.id" :class="{ 'opacity-50': cat.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono text-xs">{{ cat.code }}</td>
              <td class="px-3 py-2 font-medium">
                <span :style="{ marginLeft: `${cat.depth * 1.5}rem` }" class="inline-flex items-center gap-1.5">
                  <svg v-if="cat.depth > 0" class="w-3.5 h-3.5 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                  </svg>
                  {{ cat.name }}
                </span>
              </td>
              <td class="px-3 py-2 font-mono text-xs text-neutral-500">{{ cat.path }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ cat.display_order }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="cat.export_eshop ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ cat.export_eshop ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!cat.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !cat.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openMove(cat)" :title="t('eshop.categories.move')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
                </button>
                <button type="button" @click="openEdit(cat)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(cat)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.categories.edit') : t('eshop.categories.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.categories.field_code')"
          :name-label="t('eshop.categories.field_name')"
          :editing="!!editing"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.categories.field_parent') }}</label>
          <SearchableSelect
            :model-value="form.parent_id ?? null"
            @update:model-value="form.parent_id = $event"
            :options="catOptions(parentCandidates)"
            :placeholder="t('eshop.categories.root_category')"
            :no-results-label="t('common.no_items')"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.categories.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
        </div>
        <div class="flex items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.export_eshop" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.categories.field_export') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.categories.field_active') }}
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

    <Modal v-if="moveModalOpen" :title="t('eshop.categories.move_title')" widthClass="max-w-md" @close="moveModalOpen = false">
      <div class="space-y-3">
        <p class="text-sm text-neutral-600">{{ moving?.name }}</p>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.categories.move_to') }}</label>
          <SearchableSelect
            v-model="moveParentId"
            :options="catOptions(moveCandidates)"
            :placeholder="t('eshop.categories.root_category')"
            :no-results-label="t('common.no_items')"
          />
        </div>
        <div v-if="moveError" class="text-sm text-danger-500">{{ moveError }}</div>
        <div class="flex justify-end gap-2 pt-2 border-t border-neutral-100">
          <button @click="moveModalOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button @click="doMove" :disabled="moveSaving" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ moveSaving ? t('common.saving') : t('eshop.categories.move') }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
