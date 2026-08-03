<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type Manufacturer, type ManufacturerPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { safeExternalUrl } from '@/utils/safeUrl'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const manufacturers = ref<Manufacturer[]>([])
const loading = ref(false)

// SEC-10 — `website` je uživatelský vstup. Href si spočítáme dopředu přes sdílený
// fail-closed helper; když URL neprojde, řádek vykreslí jen text (viz šablona).
const rows = computed(() =>
  manufacturers.value.map((m) => ({ ...m, websiteHref: safeExternalUrl(m.website) })),
)

async function load() {
  loading.value = true
  try {
    manufacturers.value = await eshopApi.listManufacturers()
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
const editing = ref<Manufacturer | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<ManufacturerPayload>({ code: '', name: '', website: null, display_order: 10, export_eshop: true, archived: false })
const formActive = ref(true)
// SEC-10 — u legacy záznamu s neplatnou adresou hlásíme, že jsme ji z formuláře vyhodili.
const websiteDropped = ref(false)

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', website: null, display_order: 10, export_eshop: true, archived: false }
  formActive.value = true
  websiteDropped.value = false
  error.value = ''
  modalOpen.value = true
}

function openEdit(m: Manufacturer) {
  editing.value = m
  // SEC-10 — uložená adresa mohla vzniknout před zavedením validace. Kdybychom ji
  // zkopírovali do formuláře, uložení by backend odmítl a u takového výrobce by
  // nešlo změnit ani jméno. Vyprázdníme ji a napíšeme to nad formulář.
  websiteDropped.value = !!m.website && safeExternalUrl(m.website) === null
  form.value = {
    code: m.code,
    name: m.name,
    website: websiteDropped.value ? null : m.website,
    display_order: m.display_order,
    export_eshop: m.export_eshop,
    archived: m.archived,
  }
  formActive.value = !m.archived
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.manufacturers.field_code') + ' / ' + t('eshop.manufacturers.field_name')
    return
  }
  saving.value = true
  form.value.archived = !formActive.value
  try {
    if (editing.value) {
      await eshopApi.updateManufacturer(editing.value.id, form.value)
    } else {
      await eshopApi.createManufacturer(form.value)
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

async function remove(m: Manufacturer) {
  if (!confirm(t('eshop.manufacturers.delete_confirm', { name: m.name }))) return
  try {
    await eshopApi.deleteManufacturer(m.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'manufacturer_in_use' || code === 'eshop.error.manufacturer_in_use') {
      toast.warning(t('eshop.manufacturers.in_use_hint'))
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
        <h1 class="text-2xl font-semibold">{{ t('eshop.manufacturers.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.manufacturers.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.manufacturers.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="manufacturers.length === 0" boxed icon="factory"
      :title="t('eshop.manufacturers.empty_title')"
      :message="t('eshop.manufacturers.empty_hint')"
      :cta="auth.canWrite('eshop.write') ? t('eshop.manufacturers.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.manufacturers.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.manufacturers.col_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.manufacturers.col_website') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.manufacturers.col_order') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.manufacturers.col_export') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.manufacturers.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in rows" :key="m.id" :class="{ 'opacity-50': m.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ m.code }}</td>
              <td class="px-3 py-2 font-medium">{{ m.name }}</td>
              <td class="px-3 py-2">
                <!-- SEC-10 — odkaz jen z ověřené http(s) URL; jinak holý text bez href -->
                <a v-if="m.websiteHref" :href="m.websiteHref" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline">{{ m.website }}</a>
                <span v-else-if="m.website" class="text-neutral-400">{{ m.website }}</span>
                <span v-else class="text-neutral-400">-</span>
              </td>
              <td class="px-3 py-2 text-center font-mono">{{ m.display_order }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="m.export_eshop ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ m.export_eshop ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!m.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !m.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openEdit(m)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(m)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.manufacturers.edit') : t('eshop.manufacturers.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.manufacturers.field_code')"
          :name-label="t('eshop.manufacturers.field_name')"
          :editing="!!editing"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.manufacturers.field_website') }}</label>
          <input v-model="form.website" type="url" maxlength="255" placeholder="https://" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          <!-- SEC-10 — legacy adresa neprošla validací, byla z formuláře odstraněna -->
          <p v-if="websiteDropped" class="mt-1 text-xs text-warning-600">{{ t('eshop.manufacturers.website_dropped') }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.manufacturers.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="flex items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.export_eshop" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.manufacturers.field_export') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.manufacturers.field_active') }}
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
