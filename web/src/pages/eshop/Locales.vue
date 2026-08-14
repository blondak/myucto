<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type EshopLocale, type EshopLocalePayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const locales = ref<EshopLocale[]>([])
const loading = ref(false)

/** Nabídka nejčastějších jazyků — jen předvyplnění, kód i název jdou přepsat. */
const SUGGESTIONS: { code: string; name: string }[] = [
  { code: 'cs', name: 'Čeština' },
  { code: 'sk', name: 'Slovenčina' },
  { code: 'en', name: 'English' },
  { code: 'de', name: 'Deutsch' },
  { code: 'pl', name: 'Polski' },
  { code: 'hu', name: 'Magyar' },
  { code: 'fr', name: 'Français' },
  { code: 'es', name: 'Español' },
  { code: 'it', name: 'Italiano' },
  { code: 'uk', name: 'Українська' },
]

async function load() {
  loading.value = true
  try {
    locales.value = await eshopApi.listLocales()
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
  return e?.response?.data?.error?.message || t('common.error')
}

// ── Modal: založit / upravit ────────────────────────────────────────────
const modalOpen = ref(false)
const editing = ref<EshopLocale | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<EshopLocalePayload>({ code: '', name: '', display_order: 0, is_default: false, archived: false })
const formActive = ref(true)

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', display_order: locales.value.length * 10, is_default: locales.value.length === 0, archived: false }
  formActive.value = true
  error.value = ''
  modalOpen.value = true
}

function openEdit(row: EshopLocale) {
  editing.value = row
  form.value = {
    code: row.code,
    name: row.name,
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
}

async function save() {
  error.value = ''
  const code = form.value.code.trim()
  const name = form.value.name.trim()
  if (!/^[a-z]{2}(-[A-Z]{2})?$/.test(code)) {
    error.value = t('eshop.locales.code_invalid')
    return
  }
  if (!name) {
    error.value = t('eshop.locales.name_required')
    return
  }
  saving.value = true
  form.value.code = code
  form.value.name = name
  form.value.archived = !formActive.value
  if (form.value.archived) form.value.is_default = false
  try {
    if (editing.value) {
      await eshopApi.updateLocale(editing.value.id, form.value)
    } else {
      await eshopApi.createLocale(form.value)
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

async function remove(row: EshopLocale) {
  if (!confirm(t('eshop.locales.delete_confirm', { name: row.name }))) return
  try {
    await eshopApi.deleteLocale(row.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'locale_in_use' || code === 'eshop.error.locale_in_use') {
      toast.warning(t('eshop.locales.in_use_hint'))
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
        <h1 class="text-2xl font-semibold">{{ t('eshop.locales.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.locales.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.locales.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="locales.length === 0" boxed icon="doc"
      :title="t('eshop.locales.empty_title')"
      :message="t('eshop.locales.empty_hint')"
      :cta="auth.canWrite('eshop.write') ? t('eshop.locales.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.locales.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.locales.col_name') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.locales.col_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.locales.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in locales" :key="row.id" :class="{ 'opacity-50': row.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono uppercase">{{ row.code }}</td>
              <td class="px-3 py-2 font-medium">{{ row.name }}</td>
              <td class="px-3 py-2 text-center">
                <span v-if="row.is_default" class="text-xs px-2 py-0.5 rounded font-medium bg-primary-50 text-primary-700">{{ t('eshop.locales.default_badge') }}</span>
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

    <Modal v-if="modalOpen" :title="editing ? t('eshop.locales.edit') : t('eshop.locales.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <div v-if="!editing">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.locales.field_suggestion') }}</label>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in SUGGESTIONS" :key="s.code" type="button" @click="applySuggestion(s.code)"
              class="cursor-pointer px-2.5 h-8 rounded-full border text-sm transition whitespace-nowrap"
              :class="form.code === s.code ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
              {{ s.name }}
            </button>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.locales.field_code') }} *</label>
            <input v-model="form.code" type="text" maxlength="5" placeholder="cs" :disabled="!!editing"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100 disabled:text-neutral-500" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.locales.field_name') }} *</label>
            <input v-model="form.name" type="text" maxlength="100" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ t('eshop.locales.code_hint') }}</p>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.locales.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" step="1" min="0"
            class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
        </div>
        <div class="flex flex-wrap items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.locales.field_active') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-50': !formActive }">
            <input v-model="form.is_default" type="checkbox" :disabled="!formActive" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.locales.field_default') }}
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
