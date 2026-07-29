<script setup lang="ts">
/**
 * Multi-file panel dokumentu (Epic F7) — jeden primary + N attachmentů.
 * primary badge vs attachment, přidat/odebrat/přeřadit (sort), set-primary. ActionBar koncept.
 * `readonly` role = jen list + download.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { documentsApi, type DocumentFile } from '@/api/documents'
import { docTypeBadge, formatBytes } from './docFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ documentId: number }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const files = ref<DocumentFile[]>([])
const loading = ref(false)
const uploading = ref(false)
const uploadPct = ref(0)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const sorted = computed(() =>
  [...files.value].sort((a, b) => {
    if (a.role !== b.role) return a.role === 'primary' ? -1 : 1
    return a.sort_order - b.sort_order
  }))

function apply(list?: DocumentFile[]) { if (Array.isArray(list)) files.value = list }

async function load() {
  loading.value = true
  try { files.value = await documentsApi.listFiles(props.documentId) }
  catch { files.value = [] }
  finally { loading.value = false }
}

async function upload(list: File[]) {
  if (!list.length || !auth.canWrite('documents.upload')) return
  uploading.value = true
  uploadPct.value = 0
  try {
    const r = await documentsApi.addFiles(props.documentId, list, p => { uploadPct.value = p })
    if (r?.files) apply(r.files); else await load()
    toast.success(t('documents.files.uploaded', { n: list.length }))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('documents.upload_failed'))
  } finally {
    uploading.value = false
  }
}

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  upload(input.files ? Array.from(input.files) : [])
  input.value = ''
}
function onDrop(e: DragEvent) {
  e.preventDefault(); dragOver.value = false
  upload(Array.from(e.dataTransfer?.files ?? []))
}

async function setPrimary(f: DocumentFile) {
  if (f.role === 'primary') return
  try {
    const r = await documentsApi.patchFile(props.documentId, f.id, { role: 'primary' })
    if (r?.files) apply(r.files); else await load()
    toast.success(t('documents.files.primary_set'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function reorder(f: DocumentFile, dir: -1 | 1) {
  const atts = sorted.value.filter(x => x.role === 'attachment')
  const idx = atts.findIndex(x => x.id === f.id)
  const target = atts[idx + dir]
  if (!target) return
  try {
    const r = await documentsApi.patchFile(props.documentId, f.id, { sort_order: target.sort_order })
    if (r?.files) apply(r.files); else await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function remove(f: DocumentFile) {
  if (f.role === 'primary') { toast.error(t('documents.files.cant_delete_primary')); return }
  if (!confirm(t('documents.delete_confirm'))) return
  try {
    const r = await documentsApi.deleteFile(props.documentId, f.id)
    if (r?.files) apply(r.files); else await load()
    toast.success(t('common.deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

onMounted(load)
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-medium text-neutral-700 inline-flex items-center gap-2">
        <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
        {{ t('documents.files.title') }}
        <span v-if="files.length" class="text-xs text-neutral-400">({{ files.length }})</span>
      </h3>
      <button v-if="auth.canWrite('documents.upload')" type="button" :class="btnOutline('primary')" @click="fileInput?.click()">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
        {{ t('documents.files.add') }}
      </button>
      <input ref="fileInput" type="file" multiple class="hidden" @change="onPick" />
    </div>

    <div v-if="auth.canWrite('documents.upload')"
      class="border-2 border-dashed rounded-lg px-3 py-3 text-center text-xs transition"
      :class="dragOver ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-200 text-neutral-400'"
      @dragenter.prevent="dragOver = true" @dragover.prevent="dragOver = true"
      @dragleave.prevent="dragOver = false" @drop="onDrop">
      {{ t('documents.files.drop') }}
    </div>

    <div v-if="uploading">
      <div class="h-1.5 bg-neutral-100 rounded-full overflow-hidden">
        <div class="h-full bg-primary-500 transition-all" :style="{ width: uploadPct + '%' }"></div>
      </div>
    </div>

    <div v-if="loading" class="text-xs text-neutral-400">{{ t('common.loading') }}</div>
    <p v-else-if="files.length === 0" class="text-xs text-neutral-400">{{ t('documents.files.empty') }}</p>
    <ul v-else class="space-y-1">
      <li v-for="(f, i) in sorted" :key="f.id" class="flex items-center gap-2.5 px-2 py-1.5 rounded hover:bg-neutral-50 group">
        <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(f.doc_type ?? 'other').class]">{{ docTypeBadge(f.doc_type ?? 'other').label }}</span>
        <span class="min-w-0 flex-1 text-sm text-neutral-700 truncate">{{ f.original_name || f.filename }}</span>
        <span v-if="f.role === 'primary'" class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-primary-50 text-primary-700 inline-flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('documents.files.primary') }}
        </span>
        <span v-else class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium bg-neutral-100 text-neutral-500">{{ t('documents.files.attachment') }}</span>
        <span class="shrink-0 text-xs text-neutral-400 hidden sm:inline">{{ formatBytes(f.size_bytes ?? 0) }}</span>

        <!-- reorder (jen attachments, jen write) -->
        <template v-if="auth.canWrite('documents.move') && f.role === 'attachment'">
          <button type="button" class="shrink-0 text-neutral-300 hover:text-neutral-600 disabled:opacity-30 disabled:hover:text-neutral-300"
            :disabled="i <= 1 || sorted[i - 1]?.role !== 'attachment'" :title="t('documents.files.move_up')" @click="reorder(f, -1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" /></svg>
          </button>
          <button type="button" class="shrink-0 text-neutral-300 hover:text-neutral-600 disabled:opacity-30 disabled:hover:text-neutral-300"
            :disabled="i >= sorted.length - 1" :title="t('documents.files.move_down')" @click="reorder(f, 1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </button>
        </template>

        <button v-if="auth.canWrite('documents.move') && f.role === 'attachment'" type="button"
          class="shrink-0 text-neutral-300 hover:text-primary-600" :title="t('documents.files.set_primary')" @click="setPrimary(f)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>
        </button>
        <a :href="documentsApi.fileDownloadUrl(documentId, f.id)" class="shrink-0 text-neutral-400 hover:text-primary-600" :title="t('common.download')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        </a>
        <button v-if="auth.canWrite('documents.delete') && f.role === 'attachment'" type="button"
          class="shrink-0 opacity-0 group-hover:opacity-100 text-neutral-400 hover:text-danger-500" :title="t('common.delete')" @click="remove(f)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
        </button>
      </li>
    </ul>
  </div>
</template>
