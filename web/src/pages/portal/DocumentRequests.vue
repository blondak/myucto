<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { portalDocumentRequestsApi, type DocumentRequest } from '@/api/documentRequests'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

const items = ref<DocumentRequest[]>([])
const loading = ref(true)
const noCompany = ref(false)
const error = ref('')
const uploadingId = ref<number | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  noCompany.value = false
  try {
    items.value = await portalDocumentRequestsApi.list()
  } catch (e: any) {
    items.value = []
    if (e?.response?.status === 403) {
      noCompany.value = true
    } else {
      error.value = e?.response?.data?.error?.message || t('common.error')
    }
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => supplierStore.currentSupplierId, () => { void load() })

const open = computed(() => items.value.filter(i => i.status !== 'resolved'))
const resolved = computed(() => items.value.filter(i => i.status === 'resolved'))

async function onFileSelected(item: DocumentRequest, e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploadingId.value = item.id
  try {
    await portalDocumentRequestsApi.upload(item.id, file)
    toast.success(t('portal.document_requests.uploaded'))
    await load()
  } catch (err: any) {
    toast.error(err?.response?.data?.error?.message || t('portal.document_requests.upload_failed'))
  } finally {
    uploadingId.value = null
    if (input) input.value = ''
  }
}

function statusBadge(s: string): string {
  if (s === 'uploaded') return 'bg-warning-50 text-warning-600'
  if (s === 'resolved') return 'bg-success-50 text-success-600'
  return 'bg-neutral-100 text-neutral-600'
}

function isOverdue(item: DocumentRequest): boolean {
  return item.status === 'requested' && !!item.deadline && item.deadline < new Date().toISOString().slice(0, 10)
}
</script>

<template>
  <div class="max-w-4xl space-y-5">
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="noCompany" boxed accent="neutral" icon="lock" :title="t('portal.no_company')" />

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <template v-else>
      <div>
        <h1 class="text-2xl font-semibold">{{ t('portal.document_requests.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('portal.document_requests.subtitle') }}</p>
      </div>

      <EmptyState v-if="open.length === 0" boxed accent="success" icon="checkCircle" :title="t('portal.document_requests.empty')" />

      <div v-else class="space-y-3">
        <div v-for="item in open" :key="item.id"
          class="bg-surface border rounded-lg shadow-sm p-4"
          :class="isOverdue(item) ? 'border-danger-500/50' : 'border-neutral-200'">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-neutral-800">{{ item.description }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(item.status)">
                  {{ t(`document_requests.status_${item.status}`) }}
                </span>
              </div>
              <div class="text-xs text-neutral-500 mt-1 flex flex-wrap gap-x-3">
                <span v-if="item.amount !== null">{{ formatMoney(item.amount, 'CZK') }}</span>
                <span v-if="item.context_date">{{ formatDate(item.context_date) }}</span>
                <span v-if="item.deadline" :class="isOverdue(item) ? 'text-danger-500 font-medium' : ''">
                  {{ t('portal.document_requests.deadline_label') }} {{ formatDate(item.deadline) }}
                </span>
              </div>
            </div>
            <label v-if="item.status === 'requested' && auth.canWrite('documents.submit')"
              class="cursor-pointer min-h-[44px] inline-flex items-center gap-2 px-4 shrink-0"
              :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M7 10l5-5m0 0l5 5m-5-5v12" />
              </svg>
              {{ uploadingId === item.id ? t('portal.document_requests.uploading') : t('portal.document_requests.upload') }}
              <input type="file" accept=".pdf,.jpg,.jpeg,.png,.isdoc,.xml,.isdocx,application/pdf,image/jpeg,image/png" class="hidden"
                :disabled="uploadingId === item.id" @change="onFileSelected(item, $event)" />
            </label>
            <span v-else-if="item.status === 'uploaded'" class="text-xs text-warning-600 shrink-0 pt-2">
              {{ t('portal.document_requests.awaiting_review') }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="resolved.length > 0">
        <h2 class="text-sm font-semibold text-neutral-500 uppercase tracking-wide mb-2">{{ t('portal.document_requests.resolved_title') }}</h2>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100">
          <div v-for="item in resolved" :key="item.id" class="p-3 flex items-center justify-between gap-3 text-sm text-neutral-500">
            <span class="line-through">{{ item.description }}</span>
            <span class="text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600">{{ t('document_requests.status_resolved') }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
