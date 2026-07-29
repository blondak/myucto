<script setup lang="ts">
/**
 * Panel Přílohy přijaté faktury (Epic F7) — link/unlink DMS dokumentů přes
 * /api/purchase-invoices/{id}/documents (document_links). Nesahá na vendor-PDF/source
 * download tlačítka (fixní pdf/source sloupce PF). ActionBar koncept.
 */
import { ref, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { documentsApi, type DocItem } from '@/api/documents'
import { docTypeBadge, formatBytes } from '@/components/documents/docFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ invoiceId: number }>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const docs = ref<DocItem[]>([])
const loading = ref(false)
const attaching = ref(false)
const query = ref('')
const candidates = ref<DocItem[]>([])
const searchInput = ref<HTMLInputElement | null>(null)
let debounce: ReturnType<typeof setTimeout> | null = null

function toggleAttach() {
  attaching.value = !attaching.value
  if (attaching.value) nextTick(() => searchInput.value?.focus())
}

async function load() {
  loading.value = true
  try { docs.value = await purchaseInvoicesApi.listDmsDocuments(props.invoiceId) }
  catch { docs.value = [] }
  finally { loading.value = false }
}

watch(query, (q) => {
  if (debounce) clearTimeout(debounce)
  if (q.trim().length < 2) { candidates.value = []; return }
  debounce = setTimeout(async () => {
    const linked = new Set(docs.value.map(d => d.id))
    try { candidates.value = (await documentsApi.search(q.trim())).filter(d => !linked.has(d.id)) }
    catch { candidates.value = [] }
  }, 220)
})

async function attach(doc: DocItem) {
  try {
    docs.value = await purchaseInvoicesApi.linkDmsDocument(props.invoiceId, doc.id)
    query.value = ''
    candidates.value = []
    attaching.value = false
    toast.success(t('documents.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('documents.upload_failed'))
  }
}

async function unlink(doc: DocItem) {
  try { docs.value = await purchaseInvoicesApi.unlinkDmsDocument(props.invoiceId, doc.id) }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('documents.upload_failed')) }
}

onMounted(load)
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-neutral-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
        {{ t('documents.dms_panel.title') }}
        <span v-if="docs.length" class="text-xs text-neutral-400">({{ docs.length }})</span>
      </h3>
      <button v-if="auth.canWrite('purchase_invoices')" type="button" :class="btnOutline('primary')" @click="toggleAttach">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
        {{ t('documents.dms_panel.attach') }}
      </button>
    </div>

    <div v-if="attaching" class="mb-3">
      <input ref="searchInput" v-model="query" type="text"
        class="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 outline-none"
        :placeholder="t('documents.search_placeholder')" />
      <ul v-if="candidates.length" class="mt-1 border border-neutral-200 rounded-lg divide-y max-h-56 overflow-auto">
        <li v-for="c in candidates" :key="c.id" class="flex items-center gap-2 px-2 py-1.5 hover:bg-neutral-50 cursor-pointer" @click="attach(c)">
          <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(c.doc_type).class]">{{ docTypeBadge(c.doc_type).label }}</span>
          <span class="text-sm text-neutral-700 truncate">{{ c.title }}</span>
        </li>
      </ul>
    </div>

    <div v-if="loading" class="text-sm text-neutral-400">{{ t('common.loading') }}</div>
    <p v-else-if="docs.length === 0" class="text-sm text-neutral-400">{{ t('documents.dms_panel.empty') }}</p>
    <ul v-else class="space-y-1">
      <li v-for="d in docs" :key="d.id" class="flex items-center gap-3 px-2 py-1.5 rounded hover:bg-neutral-50 group">
        <span :class="['shrink-0 w-8 h-8 flex items-center justify-center rounded text-[9px] font-semibold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span>
        <button type="button" class="min-w-0 flex-1 text-left" @click="router.push({ name: 'document-detail', params: { id: d.id } })">
          <span class="block text-sm text-neutral-800 truncate hover:text-primary-600">{{ d.title }}</span>
          <span class="block text-xs text-neutral-400">{{ formatBytes(d.size_bytes) }}</span>
        </button>
        <a :href="documentsApi.downloadUrl(d.id)" class="text-neutral-400 hover:text-primary-600 shrink-0" :title="t('common.download')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        </a>
        <button v-if="auth.canWrite('purchase_invoices')" type="button" class="opacity-0 group-hover:opacity-100 text-neutral-400 hover:text-warning-600 shrink-0" :title="t('documents.unlink_hint')" @click="unlink(d)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244M3 3l18 18" /></svg>
        </button>
      </li>
    </ul>
  </div>
</template>
