<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoiceSubmissionsApi,
  type PurchaseInvoiceSubmission,
  type PurchaseInvoiceSubmissionKindHint,
  type PurchaseInvoiceSubmissionStatus,
} from '@/api/purchaseInvoiceSubmissions'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PdfDropzone from '@/components/purchase/PdfDropzone.vue'

const { t } = useI18n()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()

const items = ref<PurchaseInvoiceSubmission[]>([])
const selected = ref<PurchaseInvoiceSubmission | null>(null)
const status = ref<PurchaseInvoiceSubmissionStatus | ''>('submitted')
const reason = ref('')
const loading = ref(true)
const acting = ref(false)
const error = ref('')
const files = ref<File[]>([])
const note = ref('')
const kindHint = ref<PurchaseInvoiceSubmissionKindHint | null>(null)
const uploading = ref(false)

const ALLOWED_FILES = '.pdf,.jpg,.jpeg,.png,.isdoc,.xml,.isdocx,application/pdf,image/jpeg,image/png'
/** Server bere podle `documents.max_file_bytes`, výchozí 50 MiB; tady jen UX pojistka. */
const MAX_FILE_BYTES = 50 * 1024 * 1024

const canWrite = computed(() => auth.canWrite('documents.inbox'))
const canCreateInvoice = computed(() => auth.canWrite('purchase_invoices.create'))
const canDelete = computed(() => auth.canWrite('documents.inbox.delete'))
const canPreviewInline = computed(() => selected.value?.doc_type === 'pdf' || selected.value?.doc_type === 'image')
/** U dokladu, který si účetní nahrála sama, není komu psát „co doplnit". */
const hasClientToInform = computed(() => selected.value !== null && selected.value.submitted_via !== 'staff')
const canDeleteSelected = computed(() =>
  canDelete.value
  && selected.value !== null
  && selected.value.status !== 'processed'
  && selected.value.status !== 'processing',
)

async function load(keepSelection = true) {
  loading.value = true
  error.value = ''
  try {
    const page = await purchaseInvoiceSubmissionsApi.list(status.value || undefined)
    items.value = page.items
    if (keepSelection && selected.value) {
      selected.value = items.value.find(i => i.id === selected.value?.id) ?? null
    }
    if (!selected.value && items.value.length) selected.value = items.value[0]
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function selectFiles(event: Event) {
  files.value = Array.from((event.target as HTMLInputElement).files ?? [])
}

/** Drag&drop i klik v dropzone plní tentýž výběr, odeslání zůstává na tlačítku. */
function addDroppedFiles(dropped: File[]) {
  const known = new Set(files.value.map(f => `${f.name}:${f.size}:${f.lastModified}`))
  files.value = [
    ...files.value,
    ...dropped.filter(f => !known.has(`${f.name}:${f.size}:${f.lastModified}`)),
  ]
}

function clearFiles() {
  files.value = []
  const input = document.getElementById('inbox-files') as HTMLInputElement | null
  if (input) input.value = ''
}

async function upload() {
  if (uploading.value || files.value.length === 0) return
  uploading.value = true
  try {
    const result = await purchaseInvoiceSubmissionsApi.upload(files.value, note.value, kindHint.value)
    if (result.errors.length > 0) {
      toast.warning(t('purchase_submissions.uploaded_partial', {
        accepted: result.items.length,
        failed: result.errors.length,
      }))
    } else {
      toast.success(result.duplicates > 0
        ? t('purchase_submissions.inbox_uploaded_with_duplicates', {
            created: result.created,
            duplicates: result.duplicates,
          })
        : t('purchase_submissions.inbox_uploaded', { n: result.created }))
    }
    clearFiles()
    note.value = ''
    kindHint.value = null
    // Nahraný doklad čeká ve stavu `submitted`. Kdyby účetní zrovna filtrovala jinak,
    // přepnutím filtru se seznam načte přes watch — jinak ho obnovíme sami.
    const uploadedId = result.items[0]?.id ?? null
    if (status.value !== '' && status.value !== 'submitted') {
      status.value = 'submitted'
    } else {
      await load(false)
      if (uploadedId !== null) {
        selected.value = items.value.find(i => i.id === uploadedId) ?? selected.value
      }
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function extract() {
  if (!selected.value || acting.value) return
  acting.value = true
  try {
    const fresh = await purchaseInvoiceSubmissionsApi.extract(selected.value.id)
    selected.value = fresh
    toast.success(t('purchase_submissions.processed_success'))
    if (fresh.purchase_invoice_id) {
      await router.push(`/purchase-invoices/${fresh.purchase_invoice_id}/edit`)
    } else {
      await load()
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await refreshSelected()
  } finally {
    acting.value = false
  }
}

async function removeSelected() {
  const item = selected.value
  if (!item || acting.value || !canDeleteSelected.value) return
  if (!confirm(t('purchase_submissions.delete_confirm', { name: item.original_name }))) return
  acting.value = true
  try {
    await purchaseInvoiceSubmissionsApi.remove(item.id)
    toast.success(t('purchase_submissions.delete_success'))
    selected.value = null
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function setReviewStatus(target: 'needs_information' | 'rejected') {
  if (!selected.value || acting.value) return
  if (!reason.value.trim() && (hasClientToInform.value || target === 'needs_information')) {
    toast.error(t('purchase_submissions.reason_required'))
    return
  }
  acting.value = true
  try {
    selected.value = target === 'needs_information'
      ? await purchaseInvoiceSubmissionsApi.needsInformation(selected.value.id, reason.value)
      : await purchaseInvoiceSubmissionsApi.reject(selected.value.id, reason.value)
    reason.value = ''
    toast.success(t(`purchase_submissions.${target}_success`))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function refreshSelected() {
  if (!selected.value) return
  try {
    selected.value = await purchaseInvoiceSubmissionsApi.get(selected.value.id)
  } catch {
  }
}

function badge(s: PurchaseInvoiceSubmissionStatus): string {
  return {
    submitted: 'bg-warning-50 text-warning-600',
    processing: 'bg-primary-50 text-primary-700',
    needs_information: 'bg-danger-50 text-danger-600',
    processed: 'bg-success-50 text-success-600',
    rejected: 'bg-neutral-100 text-neutral-600',
  }[s]
}

function size(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} kB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function kindLabel(item: PurchaseInvoiceSubmission): string {
  return item.document_kind_hint && item.document_kind_hint !== 'other'
    ? t(`purchase_invoice.document_kind.${item.document_kind_hint}`)
    : t('purchase_submissions.kind_other')
}

onMounted(() => load(false))
watch(status, () => { selected.value = null; void load(false) })
watch(() => supplierStore.currentSupplierId, () => { selected.value = null; void load(false) })
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_submissions.inbox_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1">{{ t('purchase_submissions.inbox_subtitle') }}</p>
      </div>
      <label class="text-sm text-neutral-700 whitespace-nowrap">
        <span class="mr-2">{{ t('purchase_submissions.filter_status') }}</span>
        <select v-model="status" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface">
          <option value="">{{ t('common.all') }}</option>
          <option value="submitted">{{ t('purchase_submissions.status.submitted') }}</option>
          <option value="processing">{{ t('purchase_submissions.status.processing') }}</option>
          <option value="needs_information">{{ t('purchase_submissions.status.needs_information') }}</option>
          <option value="processed">{{ t('purchase_submissions.status.processed') }}</option>
          <option value="rejected">{{ t('purchase_submissions.status.rejected') }}</option>
        </select>
      </label>
    </header>

    <section v-if="canWrite" class="bg-surface border border-primary-500/30 rounded-lg shadow-sm p-5 space-y-4">
      <div class="flex items-start gap-3">
        <span class="w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
        </span>
        <div>
          <h2 class="font-semibold text-neutral-800">{{ t('purchase_submissions.inbox_submit_title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('purchase_submissions.inbox_submit_hint') }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label class="block text-sm text-neutral-700">
          <span class="block mb-1">{{ t('purchase_submissions.kind_hint') }}</span>
          <select v-model="kindHint" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option :value="null">{{ t('purchase_submissions.kind_unknown') }}</option>
            <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
            <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
            <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
            <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
            <option value="tax_document">{{ t('purchase_invoice.document_kind.tax_document') }}</option>
            <option value="other">{{ t('purchase_submissions.kind_other') }}</option>
          </select>
        </label>
        <label class="block text-sm text-neutral-700">
          <span class="block mb-1">{{ t('purchase_submissions.inbox_note') }}</span>
          <input v-model="note" maxlength="8000" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"
            :placeholder="t('purchase_submissions.inbox_note_placeholder')" />
        </label>
      </div>

      <PdfDropzone
        multiple
        accept-structured
        :extra-extensions="['xml']"
        :accept="ALLOWED_FILES"
        :uploading="uploading"
        :max-size-bytes="MAX_FILE_BYTES"
        :hint="t('purchase_submissions.inbox_dropzone_hint')"
        :size-hint="t('purchase_submissions.inbox_dropzone_formats')"
        @files-dropped="addDroppedFiles"
        @error="(_code, message) => toast.error(message)"
      />

      <ul v-if="files.length" class="text-sm text-neutral-700 space-y-1">
        <li v-for="file in files" :key="`${file.name}:${file.size}:${file.lastModified}`"
          class="flex items-center justify-between gap-3 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-1.5">
          <span class="truncate">{{ file.name }}</span>
          <span class="text-xs text-neutral-500 shrink-0">{{ size(file.size) }}</span>
        </li>
      </ul>

      <div class="flex flex-wrap items-center gap-3">
        <label :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
          {{ t('purchase_submissions.choose_files') }}
          <input id="inbox-files" type="file" multiple :accept="ALLOWED_FILES" class="hidden" @change="selectFiles" />
        </label>
        <span v-if="files.length" class="text-sm text-neutral-600">
          {{ t('purchase_submissions.files_selected', { n: files.length }) }}
        </span>
        <button v-if="files.length" type="button" :class="btnOutline('neutral')" :disabled="uploading" @click="clearFiles">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
          {{ t('purchase_submissions.clear_files') }}
        </button>
        <button type="button" data-testid="inbox-upload" :disabled="files.length === 0 || uploading"
          :class="btnFilled('primary')" @click="upload">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12l4 4L19 6" />
          </svg>
          {{ uploading ? t('purchase_submissions.uploading') : t('purchase_submissions.inbox_submit_action') }}
        </button>
      </div>
    </section>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-10">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0" boxed accent="success" icon="checkCircle" :title="t('purchase_submissions.inbox_empty')" />

    <div v-else class="grid grid-cols-1 xl:grid-cols-[minmax(280px,380px)_minmax(0,1fr)] gap-4 items-start">
      <div class="space-y-2">
        <button v-for="item in items" :key="item.id" type="button" @click="selected = item"
          class="w-full text-left bg-surface border rounded-lg p-3 transition"
          :class="selected?.id === item.id ? 'border-primary-500 ring-1 ring-primary-500/30' : 'border-neutral-200 hover:border-neutral-400'">
          <div class="flex items-start justify-between gap-2">
            <span class="font-medium text-sm truncate">{{ item.original_name }}</span>
            <span class="text-[11px] px-2 py-0.5 rounded font-medium shrink-0" :class="badge(item.status)">
              {{ t(`purchase_submissions.status.${item.status}`) }}
            </span>
          </div>
          <p class="text-xs text-neutral-500 mt-1">{{ item.submitted_by_name || '—' }} · {{ size(item.size_bytes) }}</p>
          <p v-if="item.note" class="text-xs text-neutral-600 mt-2 line-clamp-2">{{ item.note }}</p>
        </button>
      </div>

      <section v-if="selected" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="p-4 border-b border-neutral-200 space-y-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="font-semibold">{{ selected.original_name }}</h2>
              <p class="text-xs text-neutral-500 mt-1">
                {{ t('purchase_submissions.submitted_by', { name: selected.submitted_by_name || '—' }) }}
                · {{ new Date(selected.created_at).toLocaleString() }}
              </p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="badge(selected.status)">
              {{ t(`purchase_submissions.status.${selected.status}`) }}
            </span>
          </div>
          <p v-if="selected.note" class="text-sm text-neutral-700">{{ selected.note }}</p>
          <p v-if="selected.document_kind_hint" class="text-xs text-neutral-500">
            {{ t('purchase_submissions.kind_provided', { kind: kindLabel(selected) }) }}
          </p>
          <p v-if="selected.request_count > 0 || selected.bank_transaction_id || selected.supersedes_submission_id"
            class="text-xs text-neutral-500 flex flex-wrap gap-x-3 gap-y-1">
            <span v-if="selected.request_count > 0">
              {{ t('purchase_submissions.linked_requests', { n: selected.request_count }) }}
            </span>
            <span v-if="selected.bank_transaction_id">
              {{ t('purchase_submissions.linked_bank_transaction', { id: selected.bank_transaction_id }) }}
            </span>
            <span v-if="selected.supersedes_submission_id">
              {{ t('purchase_submissions.replaces_submission', { id: selected.supersedes_submission_id }) }}
            </span>
          </p>
          <p v-if="selected.status_reason" class="text-sm text-danger-600">{{ selected.status_reason }}</p>
          <p v-if="selected.extraction_error" class="text-xs rounded bg-warning-50 text-warning-700 px-3 py-2">
            {{ selected.extraction_error }}
          </p>

          <div class="flex flex-wrap gap-2">
            <button v-if="selected.status === 'submitted' && canWrite && canCreateInvoice" type="button"
              :disabled="acting" :class="btnFilled('primary')" @click="extract">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m9-9H3" /></svg>
              {{ acting ? t('purchase_submissions.processing') : t('purchase_submissions.extract') }}
            </button>
            <RouterLink v-if="selected.status === 'submitted' && canWrite && canCreateInvoice"
              :to="{ path: '/purchase-invoices/new', query: { submission_id: selected.id } }" :class="btnOutline('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L8 18l-4 1 1-4L16.5 3.5z" /></svg>
              {{ t('purchase_submissions.manual') }}
            </RouterLink>
            <RouterLink v-if="selected.purchase_invoice_id" :to="`/purchase-invoices/${selected.purchase_invoice_id}`" :class="btnFilled('success')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              {{ t('purchase_submissions.open_invoice') }}
            </RouterLink>
            <a :href="purchaseInvoiceSubmissionsApi.downloadUrl(selected.id)" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4 4-4M5 20h14" /></svg>
              {{ t('purchase_submissions.download') }}
            </a>
            <button v-if="canDeleteSelected" type="button" data-testid="inbox-delete" :disabled="acting"
              :class="btnOutline('danger')" @click="removeSelected">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16M10 7V4h4v3" /></svg>
              {{ t('purchase_submissions.delete') }}
            </button>
          </div>

          <div v-if="canWrite && selected.status === 'submitted'" class="space-y-2 pt-2 border-t border-neutral-100">
            <label class="block text-sm text-neutral-700">
              <span class="block mb-1">
                {{ hasClientToInform ? t('purchase_submissions.reason') : t('purchase_submissions.reason_internal') }}
              </span>
              <textarea v-model="reason" rows="2" maxlength="8000" class="w-full px-3 py-2 border border-neutral-300 rounded-md bg-surface"
                :placeholder="hasClientToInform
                  ? t('purchase_submissions.reason_placeholder')
                  : t('purchase_submissions.reason_internal_placeholder')" />
            </label>
            <div class="flex flex-wrap gap-2">
              <button v-if="hasClientToInform" type="button" :disabled="acting || !reason.trim()"
                :class="btnOutline('warning')" @click="setReviewStatus('needs_information')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M10.3 3.4L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.4a2 2 0 00-3.4 0z" /></svg>
                {{ t('purchase_submissions.needs_information') }}
              </button>
              <button type="button" :disabled="acting || (hasClientToInform && !reason.trim())"
                :class="btnOutline('danger')" @click="setReviewStatus('rejected')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ t('purchase_submissions.reject') }}
              </button>
            </div>
            <p class="text-xs text-neutral-500">{{ t('purchase_submissions.reject_keeps_original') }}</p>
          </div>
        </div>

        <div class="bg-neutral-50 min-h-[420px]">
          <iframe v-if="canPreviewInline && selected.doc_type === 'pdf'" :key="selected.id"
            :src="purchaseInvoiceSubmissionsApi.previewUrl(selected.id)" class="w-full h-[68vh] min-h-[420px]" :title="selected.original_name" />
          <div v-else-if="canPreviewInline && selected.doc_type === 'image'" class="p-4 flex justify-center">
            <img :src="purchaseInvoiceSubmissionsApi.previewUrl(selected.id)" :alt="selected.original_name" class="max-h-[68vh] object-contain" />
          </div>
          <div v-else class="min-h-[420px] flex items-center justify-center p-6 text-center text-sm text-neutral-500">
            {{ t('purchase_submissions.no_inline_preview') }}
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
