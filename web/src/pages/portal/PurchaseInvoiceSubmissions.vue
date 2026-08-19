<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  portalPurchaseInvoiceSubmissionsApi,
  type PurchaseInvoiceSubmission,
  type PurchaseInvoiceSubmissionKindHint,
} from '@/api/purchaseInvoiceSubmissions'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()

const items = ref<PurchaseInvoiceSubmission[]>([])
const files = ref<File[]>([])
const note = ref('')
const kindHint = ref<PurchaseInvoiceSubmissionKindHint | null>(null)
const loading = ref(true)
const uploading = ref(false)
const error = ref('')
const replacingId = ref<number | null>(null)

const allowed = '.pdf,.jpg,.jpeg,.png,.isdoc,.xml,.isdocx,application/pdf,image/jpeg,image/png'
const openItems = computed(() => items.value.filter(i =>
  i.status !== 'processed' && i.status !== 'rejected' && i.replacement_submission_id === null,
))
const closedItems = computed(() => items.value.filter(i =>
  i.status === 'processed' || i.status === 'rejected' || i.replacement_submission_id !== null,
))

async function load() {
  loading.value = true
  error.value = ''
  try {
    items.value = (await portalPurchaseInvoiceSubmissionsApi.list()).items
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function selectFiles(event: Event) {
  files.value = Array.from((event.target as HTMLInputElement).files ?? [])
}

async function submit() {
  if (uploading.value || files.value.length === 0) return
  uploading.value = true
  try {
    const result = await portalPurchaseInvoiceSubmissionsApi.upload(files.value, note.value, kindHint.value)
    if (result.errors.length > 0) {
      toast.warning(t('purchase_submissions.uploaded_partial', {
        accepted: result.items.length,
        failed: result.errors.length,
      }))
    } else {
      toast.success(result.duplicates > 0
        ? t('purchase_submissions.uploaded_with_duplicates', { created: result.created, duplicates: result.duplicates })
        : t('purchase_submissions.uploaded', { n: result.created }))
    }
    files.value = []
    note.value = ''
    kindHint.value = null
    const input = document.getElementById('submission-files') as HTMLInputElement | null
    if (input) input.value = ''
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function resubmit(item: PurchaseInvoiceSubmission, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  replacingId.value = item.id
  try {
    await portalPurchaseInvoiceSubmissionsApi.resubmit(item.id, file, '')
    toast.success(t('purchase_submissions.replacement_uploaded'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    replacingId.value = null
    input.value = ''
  }
}

function badge(status: PurchaseInvoiceSubmission['status']): string {
  return {
    submitted: 'bg-warning-50 text-warning-600',
    processing: 'bg-primary-50 text-primary-700',
    needs_information: 'bg-danger-50 text-danger-600',
    processed: 'bg-success-50 text-success-600',
    rejected: 'bg-neutral-100 text-neutral-600',
  }[status]
}

function itemBadge(item: PurchaseInvoiceSubmission): string {
  return item.replacement_submission_id !== null
    ? 'bg-primary-50 text-primary-700'
    : badge(item.status)
}

function itemStatus(item: PurchaseInvoiceSubmission): string {
  return item.replacement_submission_id !== null
    ? t('purchase_submissions.replacement_received')
    : t(`purchase_submissions.status.${item.status}`)
}

function size(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} kB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

onMounted(load)
watch(() => supplierStore.currentSupplierId, () => { void load() })
</script>

<template>
  <div class="max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold">{{ t('purchase_submissions.portal_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-1">{{ t('purchase_submissions.portal_subtitle') }}</p>
    </header>

    <section v-if="auth.canWrite('documents.submit')" class="bg-surface border border-primary-500/30 rounded-lg shadow-sm p-5 space-y-4">
      <div class="flex items-start gap-3">
        <span class="w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
        </span>
        <div>
          <h2 class="font-semibold text-neutral-800">{{ t('purchase_submissions.submit_title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('purchase_submissions.submit_hint') }}</p>
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
          <span class="block mb-1">{{ t('purchase_submissions.note') }}</span>
          <input v-model="note" maxlength="8000" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"
            :placeholder="t('purchase_submissions.note_placeholder')" />
        </label>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <label :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
          {{ t('purchase_submissions.choose_files') }}
          <input id="submission-files" type="file" multiple :accept="allowed" class="hidden" @change="selectFiles" />
        </label>
        <span v-if="files.length" class="text-sm text-neutral-600">
          {{ t('purchase_submissions.files_selected', { n: files.length }) }}
        </span>
        <button type="button" :disabled="files.length === 0 || uploading" :class="btnFilled('primary')" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12l4 4L19 6" />
          </svg>
          {{ uploading ? t('purchase_submissions.uploading') : t('purchase_submissions.submit_action') }}
        </button>
      </div>
    </section>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-10">{{ t('common.loading') }}</div>

    <template v-else>
      <section class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_submissions.open_title') }}</h2>
        <EmptyState v-if="openItems.length === 0" boxed accent="success" icon="checkCircle" :title="t('purchase_submissions.empty')" />
        <article v-for="item in openItems" :key="item.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium truncate">{{ item.original_name }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="itemBadge(item)">
                  {{ itemStatus(item) }}
                </span>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ size(item.size_bytes) }} · {{ new Date(item.created_at).toLocaleString() }}</p>
              <p v-if="item.note" class="text-sm text-neutral-600 mt-2">{{ item.note }}</p>
              <p v-if="item.status_reason" class="text-sm text-danger-600 mt-2">{{ item.status_reason }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <a :href="portalPurchaseInvoiceSubmissionsApi.previewUrl(item.id)" target="_blank" rel="noopener" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12zm10 3a3 3 0 100-6 3 3 0 000 6z" /></svg>
                {{ t('purchase_submissions.preview') }}
              </a>
              <label v-if="item.status === 'needs_information' && auth.canWrite('documents.submit')" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" /></svg>
                {{ replacingId === item.id ? t('purchase_submissions.uploading') : t('purchase_submissions.replace') }}
                <input type="file" :accept="allowed" class="hidden" :disabled="replacingId === item.id" @change="resubmit(item, $event)" />
              </label>
            </div>
          </div>
        </article>
      </section>

      <section v-if="closedItems.length" class="space-y-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_submissions.closed_title') }}</h2>
        <div class="bg-surface border border-neutral-200 rounded-lg divide-y divide-neutral-100">
          <div v-for="item in closedItems" :key="item.id" class="p-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="truncate">{{ item.original_name }}</span>
            <div class="flex items-center gap-2">
              <a :href="portalPurchaseInvoiceSubmissionsApi.downloadUrl(item.id)" class="text-neutral-600 hover:underline">
                {{ t('purchase_submissions.download') }}
              </a>
              <RouterLink v-if="item.purchase_invoice_id && auth.canRead('purchase_invoices')" :to="`/purchase-invoices/${item.purchase_invoice_id}`" class="text-primary-700 hover:underline">
                {{ t('purchase_submissions.open_invoice') }}
              </RouterLink>
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="itemBadge(item)">{{ itemStatus(item) }}</span>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
