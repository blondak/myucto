<script setup lang="ts">
/**
 * Rozšíření detailu účetního zápisu (Epic F7):
 *  - inline editor `description` (§35) — tužka → text field → Uložit/Zrušit (ActionBar koncept).
 *    U zaúčtovaných zápisů hint „§35 audit". U zápisů řízených zdrojovým dokladem (source_type
 *    ∉ manual/closing/opening) je edit skrytý (backend by vrátil 409 description_managed_by_source).
 *  - panel Přílohy §33a — list + drag&drop multi-upload + download + delete + inline edit popisku.
 *    `readonly` role = jen list + download.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import {
  accountingApi, isDescriptionEditable,
  type JournalEntryDetail, type JournalAttachment,
} from '@/api/accounting'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import JournalEntryHistory from '@/components/accounting/JournalEntryHistory.vue'
import JournalEntryNotes from '@/components/accounting/JournalEntryNotes.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const props = defineProps<{ entry: JournalEntryDetail }>()
const emit = defineEmits<{ (e: 'description-updated', description: string, rowVersion: number): void }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

// ── description editor (§35) ────────────────────────────────────────────────
const canEditDescription = computed(() =>
  auth.canWrite('accounting') && isDescriptionEditable(props.entry.source_type))
const isPosted = computed(() => !!props.entry.posted_at)

const editing = ref(false)
const draft = ref('')
const savingDesc = ref(false)
const currentDescription = ref<string | null>(props.entry.description)
// Lokálně držená verze pro optimistickou konkurenci (If-Match) — po každém úspěšném
// uložení se posune, ať druhá editace ve stejném detailu neskončí falešným 409 (Issue #15).
const currentRowVersion = ref<number>(props.entry.row_version)

function startEdit() {
  draft.value = currentDescription.value ?? ''
  editing.value = true
}
function cancelEdit() {
  editing.value = false
  draft.value = ''
}
async function saveDescription() {
  savingDesc.value = true
  try {
    const updated = await accountingApi.updateJournalDescription(
      props.entry.id, draft.value.trim(), currentRowVersion.value,
    )
    currentDescription.value = updated.description
    currentRowVersion.value = updated.row_version
    editing.value = false
    emit('description-updated', updated.description ?? '', updated.row_version)
    toast.success(t('common.saved'))
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    // 409 version_conflict: mezitím editoval jiný uživatel → sesynchronizuj aktuální stav.
    if (code === 'version_conflict') {
      try {
        const fresh = await accountingApi.getEntry(props.entry.id)
        currentDescription.value = fresh.description
        currentRowVersion.value = fresh.row_version
        draft.value = fresh.description ?? ''
        emit('description-updated', fresh.description ?? '', fresh.row_version)
      } catch { /* ponech dosavadní stav */ }
    }
    const key = `accounting.journal.desc_error.${code}`
    const msg = code && t(key) !== key ? t(key) : (e?.response?.data?.error?.message || t('common.error'))
    toast.error(msg)
  } finally {
    savingDesc.value = false
  }
}

// ── přílohy §33a ────────────────────────────────────────────────────────────
const attachments = ref<JournalAttachment[]>([])
const loadingAtt = ref(false)
const uploading = ref(false)
const uploadPct = ref(0)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const editingAttId = ref<number | null>(null)
const attDraft = ref('')

async function loadAttachments() {
  loadingAtt.value = true
  try {
    attachments.value = await accountingApi.listJournalAttachments(props.entry.id)
  } catch {
    attachments.value = []
  } finally {
    loadingAtt.value = false
  }
}

async function uploadFiles(files: File[]) {
  if (!files.length || !auth.canWrite('accounting')) return
  uploading.value = true
  uploadPct.value = 0
  try {
    await accountingApi.uploadJournalAttachments(props.entry.id, files, p => { uploadPct.value = p })
    await loadAttachments()
    toast.success(t('accounting.journal.att_uploaded', { n: files.length }))
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'duplicate') toast.warning(t('accounting.journal.att_duplicate'))
    else toast.error(e?.response?.data?.error?.message || t('documents.upload_failed'))
  } finally {
    uploading.value = false
  }
}

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  uploadFiles(input.files ? Array.from(input.files) : [])
  input.value = ''
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  dragOver.value = false
  uploadFiles(Array.from(e.dataTransfer?.files ?? []))
}

async function removeAttachment(a: JournalAttachment) {
  if (!confirm(t('accounting.journal.att_delete_confirm'))) return
  try {
    await accountingApi.deleteJournalAttachment(props.entry.id, a.id)
    attachments.value = attachments.value.filter(x => x.id !== a.id)
    toast.success(t('common.deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function startEditAtt(a: JournalAttachment) {
  editingAttId.value = a.id
  attDraft.value = a.description ?? ''
}
function cancelEditAtt() {
  editingAttId.value = null
  attDraft.value = ''
}
async function saveAttDescription(a: JournalAttachment) {
  try {
    const updated = await accountingApi.updateJournalAttachmentDescription(props.entry.id, a.id, attDraft.value.trim())
    const idx = attachments.value.findIndex(x => x.id === a.id)
    if (idx >= 0) attachments.value[idx] = updated
    editingAttId.value = null
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function fmtBytes(n: number | null): string {
  if (!n) return ''
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} kB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

onMounted(loadAttachments)
</script>

<template>
  <div class="mt-3 space-y-4">
    <!-- ── description (§35) ── -->
    <div class="border-t border-neutral-200 pt-3">
      <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1">
          <div class="text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.description') }}</div>
          <template v-if="editing">
            <input v-model="draft" type="text" maxlength="255" autofocus
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
              @keydown.enter.prevent="saveDescription" @keydown.esc="cancelEdit" />
            <div class="flex items-center gap-2 mt-2">
              <button type="button" :class="btnFilled('primary')" :disabled="savingDesc" @click="saveDescription">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ savingDesc ? t('common.saving') : t('common.save') }}
              </button>
              <button type="button" :class="btnOutline('neutral')" :disabled="savingDesc" @click="cancelEdit">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('common.cancel') }}
              </button>
            </div>
            <p v-if="isPosted" class="text-xs text-warning-600 mt-1.5 inline-flex items-center gap-1">
              <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
              {{ t('accounting.journal.desc_posted_hint') }}
            </p>
          </template>
          <template v-else>
            <div class="flex items-center gap-2">
              <span class="text-sm text-neutral-800">{{ currentDescription || '—' }}</span>
              <button v-if="canEditDescription" type="button"
                class="cursor-pointer text-neutral-400 hover:text-primary-600" :title="t('common.edit')" @click="startEdit">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              </button>
            </div>
            <p v-if="canEditDescription && isPosted" class="text-xs text-neutral-400 mt-1">{{ t('accounting.journal.desc_posted_hint') }}</p>
            <p v-else-if="auth.canWrite('accounting') && !canEditDescription" class="text-xs text-neutral-400 mt-1">{{ t('accounting.journal.desc_source_managed') }}</p>
          </template>
        </div>
      </div>
    </div>

    <!-- ── přílohy §33a ── -->
    <div class="border-t border-neutral-200 pt-3">
      <div class="flex items-center justify-between mb-2">
        <h4 class="text-xs font-medium text-neutral-500 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('accounting.journal.attachments') }}
          <span v-if="attachments.length" class="text-neutral-400">({{ attachments.length }})</span>
        </h4>
        <button v-if="auth.canWrite('accounting')" type="button" :class="btnOutline('primary')" @click="fileInput?.click()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('accounting.journal.att_add') }}
        </button>
        <input ref="fileInput" type="file" multiple class="hidden" @change="onPick" />
      </div>

      <!-- drag&drop zóna (jen write) -->
      <div v-if="auth.canWrite('accounting')"
        class="border-2 border-dashed rounded-lg px-3 py-3 text-center text-xs transition mb-2"
        :class="dragOver ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-200 text-neutral-400'"
        @dragenter.prevent="dragOver = true" @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false" @drop="onDrop">
        {{ t('accounting.journal.att_drop') }}
      </div>

      <div v-if="uploading" class="mb-2">
        <div class="h-1.5 bg-neutral-100 rounded-full overflow-hidden">
          <div class="h-full bg-primary-500 transition-all" :style="{ width: uploadPct + '%' }"></div>
        </div>
      </div>

      <div v-if="loadingAtt" class="text-xs text-neutral-400 py-2">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="attachments.length === 0" dense accent="neutral" icon="doc" :title="t('accounting.journal.att_empty')" />
      <ul v-else class="space-y-1">
        <li v-for="a in attachments" :key="a.id" class="flex items-center gap-3 px-2 py-1.5 rounded hover:bg-neutral-100 group">
          <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          <div class="min-w-0 flex-1">
            <div class="text-sm text-neutral-700 truncate">{{ a.original_name || a.filename }}</div>
            <template v-if="editingAttId === a.id">
              <div class="flex items-center gap-1.5 mt-1">
                <input v-model="attDraft" type="text" maxlength="255"
                  class="flex-1 h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
                  :placeholder="t('accounting.journal.att_desc_placeholder')"
                  @keydown.enter.prevent="saveAttDescription(a)" @keydown.esc="cancelEditAtt" />
                <button type="button" class="cursor-pointer text-success-600 hover:text-success-700" :title="t('common.save')" @click="saveAttDescription(a)">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                </button>
                <button type="button" class="cursor-pointer text-neutral-400 hover:text-neutral-600" :title="t('common.cancel')" @click="cancelEditAtt">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                </button>
              </div>
            </template>
            <div v-else class="text-xs text-neutral-400 flex items-center gap-1.5">
              <span v-if="a.description" class="truncate">{{ a.description }}</span>
              <span v-else class="italic">{{ t('accounting.journal.att_no_desc') }}</span>
              <button v-if="auth.canWrite('accounting')" type="button" class="cursor-pointer text-neutral-300 hover:text-primary-600 shrink-0" :title="t('common.edit')" @click="startEditAtt(a)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              </button>
            </div>
          </div>
          <span class="text-xs text-neutral-400 shrink-0">{{ fmtBytes(a.size_bytes) }}</span>
          <span v-if="a.uploaded_at" class="text-xs text-neutral-300 shrink-0 hidden sm:inline">{{ formatDate(a.uploaded_at) }}</span>
          <a :href="accountingApi.journalAttachmentDownloadUrl(entry.id, a.id)"
            class="text-neutral-400 hover:text-primary-600 shrink-0" :title="t('common.download')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          </a>
          <button v-if="auth.canWrite('accounting')" type="button" class="opacity-0 group-hover:opacity-100 text-neutral-400 hover:text-danger-500 shrink-0" :title="t('common.delete')" @click="removeAttachment(a)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </li>
      </ul>
    </div>

    <!-- ── poznámky (1:N) — lazy, jdou psát i tam, kde je description read-only ── -->
    <JournalEntryNotes :entry-id="entry.id" />

    <!-- ── historie (SYSTEM VERSIONING timeline) ── -->
    <JournalEntryHistory :entry-id="entry.id" />
  </div>
</template>
