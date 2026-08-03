<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type Tag, type TagPayload } from '@/api/eshop'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const tags = ref<Tag[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    tags.value = await eshopApi.listTags()
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
const editing = ref<Tag | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<TagPayload>({ code: '', name: '', color: '#3b82f6', archived: false })
const formActive = ref(true)

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', color: '#3b82f6', archived: false }
  formActive.value = true
  error.value = ''
  modalOpen.value = true
}

function openEdit(tag: Tag) {
  editing.value = tag
  form.value = { code: tag.code, name: tag.name, color: tag.color || '#3b82f6', archived: tag.archived }
  formActive.value = !tag.archived
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.tags.field_code') + ' / ' + t('eshop.tags.field_name')
    return
  }
  saving.value = true
  form.value.archived = !formActive.value
  try {
    if (editing.value) {
      await eshopApi.updateTag(editing.value.id, form.value)
    } else {
      await eshopApi.createTag(form.value)
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

async function remove(tag: Tag) {
  if (!confirm(t('eshop.tags.delete_confirm', { name: tag.name }))) return
  try {
    await eshopApi.deleteTag(tag.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'tag_in_use' || code === 'eshop.error.tag_in_use') {
      toast.warning(t('eshop.tags.in_use_hint'))
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
        <h1 class="text-2xl font-semibold">{{ t('eshop.tags.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.tags.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('eshop.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.tags.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="tags.length === 0" boxed icon="tag"
      :title="t('eshop.tags.empty_title')"
      :message="t('eshop.tags.empty_hint')"
      :cta="auth.canWrite('eshop.write') ? t('eshop.tags.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.tags.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.tags.col_name') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.tags.col_color') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.tags.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tag in tags" :key="tag.id" :class="{ 'opacity-50': tag.archived }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ tag.code }}</td>
              <td class="px-3 py-2 font-medium">
                <div class="flex items-center gap-2">
                  <span v-if="tag.color" class="w-4 h-4 rounded border border-neutral-300/60 shadow-sm shrink-0" :style="{ backgroundColor: tag.color }"></span>
                  <span v-else class="w-4 h-4 rounded border border-neutral-200 bg-neutral-100 shrink-0"></span>
                  <span>{{ tag.name }}</span>
                </div>
              </td>
              <td class="px-3 py-2 text-center font-mono text-xs">
                <span v-if="tag.color" class="px-1.5 py-0.5 rounded bg-neutral-100 border border-neutral-200">{{ tag.color }}</span>
                <span v-else>-</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="!tag.archived ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ !tag.archived ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('eshop.write')">
                <button type="button" @click="openEdit(tag)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(tag)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('eshop.tags.edit') : t('eshop.tags.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.tags.field_code')"
          :name-label="t('eshop.tags.field_name')"
          :editing="!!editing"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.tags.field_color') }}</label>
          <div class="flex gap-2">
            <input v-model="form.color" type="text" maxlength="7" placeholder="#RRGGBB" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
            <input v-model="form.color" type="color" class="w-10 h-9 p-0.5 border border-neutral-300 rounded-md cursor-pointer shrink-0" />
          </div>
        </div>
        <div class="flex items-center gap-4 pt-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="formActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('eshop.tags.field_active') }}
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
