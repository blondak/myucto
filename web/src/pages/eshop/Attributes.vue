<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type Attribute, type AttributePayload, type AttributeOption, type AttributeOptionPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useAutoSlug } from '@/composables/useAutoSlug'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const attributes = ref<Attribute[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    attributes.value = await eshopApi.listAttributes()
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
const editing = ref<Attribute | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<AttributePayload>({
  code: '',
  name: '',
  data_type: 'text',
  unit: null,
  is_filterable: false,
  is_multivalue: false,
  display_order: 10,
  archived: false
})
const formActive = ref(true)

// ── Atribut Options ──────────────────────────────────────────────────────
const options = ref<AttributeOption[]>([])
const optionsLoading = ref(false)
const editingOption = ref<AttributeOption | null>(null)
const optSaving = ref(false)
const optForm = ref<AttributeOptionPayload>({ code: '', label: '', display_order: 10 })

// Při zakládání atributu (ještě nemá ID) se volby bufferují lokálně a vytvoří se
// až po uložení atributu; při editaci jedou rovnou proti API.
const pendingOptions = ref<AttributeOptionPayload[]>([])
const editingOptionIdx = ref<number | null>(null)
const displayOptions = computed<AttributeOption[]>(() => {
  const pend = pendingOptions.value.map((o, i) => ({ id: -(i + 1), attribute_id: 0, code: o.code, label: o.label, display_order: o.display_order }))
  // Edit mode: server volby + případné ještě nevytvořené (po částečném selhání ukládání).
  return editing.value ? [...options.value, ...pend] : pend
})

// Auto-slug: Popisek volby → Kód volby (dokud uživatel kód needitoval ručně).
const optAuto = useAutoSlug((slug) => { optForm.value.code = slug }, { maxLen: 50 })
function onOptLabel(e: Event) {
  const val = (e.target as HTMLInputElement).value
  optForm.value.label = val
  optAuto.fromName(val)
}
function onOptCode(e: Event) {
  const val = (e.target as HTMLInputElement).value
  optForm.value.code = val
  optAuto.markManual(val)
}

async function loadOptions(attributeId: number) {
  optionsLoading.value = true
  try {
    options.value = await eshopApi.listAttributeOptions(attributeId)
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    optionsLoading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = {
    code: '',
    name: '',
    data_type: 'text',
    unit: null,
    is_filterable: false,
    is_multivalue: false,
    display_order: 10,
    archived: false
  }
  formActive.value = true
  options.value = []
  pendingOptions.value = []
  error.value = ''
  cancelEditOption()
  modalOpen.value = true
}

function openEdit(attr: Attribute) {
  editing.value = attr
  form.value = {
    code: attr.code,
    name: attr.name,
    data_type: attr.data_type,
    unit: attr.unit,
    is_filterable: attr.is_filterable,
    is_multivalue: attr.is_multivalue,
    display_order: attr.display_order,
    archived: attr.archived
  }
  formActive.value = !attr.archived
  error.value = ''
  pendingOptions.value = []
  modalOpen.value = true
  loadOptions(attr.id)
  cancelEditOption()
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.attributes.field_code') + ' / ' + t('eshop.attributes.field_name')
    return
  }
  saving.value = true
  form.value.archived = !formActive.value
  try {
    if (editing.value) {
      await eshopApi.updateAttribute(editing.value.id, form.value)
    } else {
      // Po vytvoření přepni na editaci — případný retry po chybě už atribut nezaloží podruhé.
      editing.value = await eshopApi.createAttribute(form.value)
    }
    // Nabufferované volby (ze zakládání) vytvoř z fronty — přežije částečné selhání i retry.
    while (pendingOptions.value.length > 0) {
      await eshopApi.createAttributeOption(editing.value.id, pendingOptions.value[0])
      pendingOptions.value.shift()
    }
    toast.success(t('common.saved'))
    modalOpen.value = false
    await load()
  } catch (e: any) {
    error.value = mapError(e)
    // Pokud atribut mezitím vznikl (částečné selhání voleb), načti jeho volby, ať jsou vidět.
    if (editing.value) { try { await loadOptions(editing.value.id) } catch { /* ignore */ } }
  } finally {
    saving.value = false
  }
}

async function remove(attr: Attribute) {
  if (!confirm(t('eshop.attributes.delete_confirm', { name: attr.name }))) return
  try {
    await eshopApi.deleteAttribute(attr.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'attribute_in_use' || code === 'eshop.error.attribute_in_use') {
      toast.warning(t('eshop.attributes.in_use_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}

// ── Options management ───────────────────────────────────────────────────
function startEditOption(opt: AttributeOption) {
  optForm.value = { code: opt.code, label: opt.label, display_order: opt.display_order }
  optAuto.init(opt.code, true)
  if (opt.id < 0) {
    // Nezaložená (buffer) volba — edituj podle indexu.
    editingOption.value = null
    editingOptionIdx.value = -opt.id - 1
  } else {
    editingOption.value = opt
    editingOptionIdx.value = null
  }
}

function cancelEditOption() {
  editingOption.value = null
  editingOptionIdx.value = null
  optForm.value = { code: '', label: '', display_order: displayOptions.value.length * 10 + 10 }
  optAuto.init('', false)
}

async function saveOption() {
  if (!optForm.value.code.trim() || !optForm.value.label.trim()) {
    toast.error(t('eshop.attributes.option_field_code') + ' / ' + t('eshop.attributes.option_field_label'))
    return
  }
  // Create mode: bufferuj lokálně (uloží se s atributem).
  if (!editing.value) {
    const payload: AttributeOptionPayload = {
      code: optForm.value.code.trim(),
      label: optForm.value.label.trim(),
      display_order: optForm.value.display_order,
    }
    if (editingOptionIdx.value !== null) {
      pendingOptions.value[editingOptionIdx.value] = payload
    } else {
      pendingOptions.value.push(payload)
    }
    cancelEditOption()
    return
  }
  optSaving.value = true
  try {
    if (editingOption.value) {
      await eshopApi.updateAttributeOption(editingOption.value.id, optForm.value)
    } else {
      await eshopApi.createAttributeOption(editing.value.id, optForm.value)
    }
    toast.success(t('common.saved'))
    cancelEditOption()
    await loadOptions(editing.value.id)
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    optSaving.value = false
  }
}

async function deleteOption(opt: AttributeOption) {
  // Nezaložená (buffer) volba — jen odeber z bufferu, žádné API.
  if (opt.id < 0) {
    pendingOptions.value.splice(-opt.id - 1, 1)
    if (editingOptionIdx.value !== null) cancelEditOption()
    return
  }
  if (!confirm(t('eshop.attributes.option_delete_confirm', { label: opt.label }))) return
  try {
    await eshopApi.deleteAttributeOption(opt.id)
    toast.success(t('common.saved'))
    if (editing.value) {
      await loadOptions(editing.value.id)
    }
  } catch (e: any) {
    toast.error(mapError(e))
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.attributes.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.attributes.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.attributes.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="attributes.length === 0" class="text-center py-12">
      <p class="text-neutral-500 text-sm mb-3">{{ t('common.no_items') }}</p>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.attributes.new') }}
      </button>
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.attributes.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.attributes.col_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.attributes.col_type') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.attributes.col_unit') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.attributes.col_filterable') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.attributes.col_multivalue') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.attributes.col_order') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.attributes.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="attr in attributes" :key="attr.id" :class="{ 'opacity-50': attr.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ attr.code }}</td>
              <td class="px-3 py-2 font-medium">{{ attr.name }}</td>
              <td class="px-3 py-2">
                <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 font-medium">
                  {{ attr.data_type }}
                </span>
              </td>
              <td class="px-3 py-2 font-mono">{{ attr.unit || '-' }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="attr.is_filterable ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ attr.is_filterable ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="attr.is_multivalue ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ attr.is_multivalue ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-mono">{{ attr.display_order }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!attr.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !attr.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openEdit(attr)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(attr)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.attributes.edit') : t('eshop.attributes.new')" widthClass="max-w-xl" @close="modalOpen = false">
      <div class="space-y-4 max-h-[80vh] overflow-y-auto scrollbar-slim pr-1">
        <div class="grid grid-cols-2 gap-3">
          <CodeNameFields
            v-model:code="form.code"
            v-model:name="form.name"
            :code-label="t('eshop.attributes.field_code')"
            :name-label="t('eshop.attributes.field_name')"
            :editing="!!editing"
          />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.attributes.field_type') }}</label>
            <select v-model="form.data_type" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="text">Text</option>
              <option value="number">Number</option>
              <option value="bool">Boolean</option>
              <option value="enum">Enum (Volby)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.attributes.field_unit') }}</label>
            <input v-model="form.unit" type="text" maxlength="20" placeholder="např. kg, cm, ks..." class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.attributes.field_display_order') }}</label>
            <input v-model.number="form.display_order" type="number" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="flex items-center gap-2 pt-5">
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="form.is_filterable" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('eshop.attributes.field_filterable') }}
            </label>
          </div>
          <div class="flex items-center gap-2 pt-5">
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="form.is_multivalue" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('eshop.attributes.field_multivalue') }}
            </label>
          </div>
        </div>

        <div class="flex items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.attributes.field_active') }}
          </label>
        </div>

        <!-- Inline Attribute Options section -->
        <div class="mt-4 pt-4 border-t border-neutral-200">
          <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('eshop.attributes.options_title') }}</h3>

          <!-- Options table -->
          <div v-if="optionsLoading" class="text-center text-xs text-neutral-500 py-3">{{ t('common.loading') }}</div>
          <div v-else class="border border-neutral-200 rounded overflow-hidden mb-3">
            <table class="w-full text-xs">
              <thead>
                <tr class="bg-neutral-50 border-b border-neutral-200">
                  <th class="p-2 text-left font-semibold">{{ t('eshop.attributes.option_col_code') }}</th>
                  <th class="p-2 text-left font-semibold">{{ t('eshop.attributes.option_col_label') }}</th>
                  <th class="p-2 text-center font-semibold">{{ t('eshop.attributes.option_col_order') }}</th>
                  <th class="p-2 w-16"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="opt in displayOptions" :key="opt.id" class="hover:bg-neutral-50">
                  <td class="p-2 font-mono font-medium">{{ opt.code }}</td>
                  <td class="p-2">{{ opt.label }}</td>
                  <td class="p-2 text-center font-mono text-neutral-500">{{ opt.display_order }}</td>
                  <td class="p-2 text-right whitespace-nowrap">
                    <button type="button" @click="startEditOption(opt)" class="text-neutral-400 hover:text-primary-600 px-1">
                      <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    </button>
                    <button type="button" @click="deleteOption(opt)" class="text-neutral-400 hover:text-danger-500 px-1">
                      <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    </button>
                  </td>
                </tr>
                <tr v-if="displayOptions.length === 0">
                  <td colspan="4" class="p-4 text-center text-neutral-400 italic">{{ t('common.no_items') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Add / Edit Option Form -->
          <div class="bg-neutral-50 p-3 rounded-md border border-neutral-200 text-xs">
            <div class="font-semibold text-neutral-700 mb-2">
              {{ (editingOption || editingOptionIdx !== null) ? t('eshop.attributes.option_edit') : t('eshop.attributes.option_new') }}
            </div>
            <div class="grid grid-cols-3 gap-2 mb-2">
              <div>
                <label class="block text-[10px] text-neutral-500 mb-0.5">{{ t('eshop.attributes.option_field_code') }}</label>
                <input :value="optForm.code" @input="onOptCode" type="text" class="w-full h-8 px-2 border border-neutral-300 rounded text-xs font-mono" />
              </div>
              <div>
                <label class="block text-[10px] text-neutral-500 mb-0.5">{{ t('eshop.attributes.option_field_label') }}</label>
                <input :value="optForm.label" @input="onOptLabel" type="text" class="w-full h-8 px-2 border border-neutral-300 rounded text-xs" />
              </div>
              <div>
                <label class="block text-[10px] text-neutral-500 mb-0.5">{{ t('eshop.attributes.option_field_order') }}</label>
                <input v-model.number="optForm.display_order" type="number" class="w-full h-8 px-2 border border-neutral-300 rounded text-xs font-mono" />
              </div>
            </div>
            <div class="flex justify-end gap-1.5 pt-1">
              <button v-if="editingOption || editingOptionIdx !== null" type="button" @click="cancelEditOption" class="px-2 py-1 text-xs border border-neutral-300 rounded hover:bg-neutral-100">
                {{ t('common.cancel') || 'Zrušit' }}
              </button>
              <button type="button" @click="saveOption" :disabled="optSaving" class="px-2.5 py-1 text-xs bg-primary-600 text-white rounded hover:bg-primary-700 disabled:opacity-50 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ optSaving ? (t('common.saving') || 'Ukládám...') : (t('common.save') || 'Uložit') }}
              </button>
            </div>
          </div>
        </div>

        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex justify-end gap-2 pt-3 border-t border-neutral-100">
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
