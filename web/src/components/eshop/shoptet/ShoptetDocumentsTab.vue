<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ShoptetSteps from './ShoptetSteps.vue'
import ImportReportPanel from '@/components/exchange/ImportReportPanel.vue'
import { shoptetApi, type ShoptetBatch, type ShoptetDocumentReport, type ShoptetSettings } from '@/api/shoptet'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ settings: ShoptetSettings; canWrite: boolean }>()
const emit = defineEmits<{ (e: 'open-settings'): void }>()
const { t } = useI18n()

const files = ref<File[]>([])
const busy = ref(false)
const error = ref('')
const report = ref<ShoptetDocumentReport | null>(null)
const batches = ref<ShoptetBatch[]>([])

function pick(e: Event) {
  files.value = Array.from((e.target as HTMLInputElement).files ?? [])
}
function drop(e: DragEvent) {
  e.preventDefault()
  files.value = Array.from(e.dataTransfer?.files ?? [])
}

async function submit() {
  if (files.value.length === 0) return
  busy.value = true
  error.value = ''
  try {
    report.value = await shoptetApi.importDocuments(files.value)
    files.value = []
    await reload()
  } catch (e) {
    error.value = apiErrorMessage(e, t('shoptet.documents.import_failed'))
  } finally {
    busy.value = false
  }
}

async function reload() {
  try {
    batches.value = await shoptetApi.batches('documents')
  } catch {
    batches.value = []
  }
}

onMounted(reload)
</script>

<template>
  <div class="space-y-4">
    <div v-if="props.settings.documents_issuer !== 'shoptet'"
      class="rounded-lg bg-warning-50 border border-warning-500/40 px-4 py-3 text-sm text-warning-600 flex flex-wrap gap-3 items-center justify-between"
      data-test="mode-warning">
      <div>
        <strong>{{ t('shoptet.documents.mode_myucto_title') }}.</strong>
        {{ t('shoptet.documents.mode_myucto_hint') }}
      </div>
      <button :class="btnOutline('warning')" @click="emit('open-settings')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
        {{ t('shoptet.documents.go_settings') }}
      </button>
    </div>

    <ShoptetSteps message-key="shoptet.documents.steps" />

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <div>
        <h2 class="text-lg font-semibold">{{ t('shoptet.documents.import_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('shoptet.documents.file_hint') }}</p>
      </div>
      <label v-if="canWrite && props.settings.documents_issuer === 'shoptet'" @dragover.prevent @drop="drop"
        class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-6 text-center cursor-pointer transition">
        <input type="file" multiple accept=".isdoc,.isdocx,.xml,.zip,application/xml,application/zip" class="hidden" @change="pick" />
        <div class="text-sm font-medium text-neutral-700">
          {{ files.length > 0 ? files.map(f => f.name).join(', ') : t('shoptet.documents.drop') }}
        </div>
      </label>
      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
      <div v-if="canWrite && props.settings.documents_issuer === 'shoptet'" class="flex flex-wrap gap-2">
        <button :class="btnFilled('primary')" :disabled="busy || files.length === 0" data-test="import-documents" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ busy ? t('shoptet.documents.importing') : t('shoptet.documents.import') }}
        </button>
      </div>
      <p v-if="report?.summary.linked_orders" class="text-sm text-success-600">
        {{ t('shoptet.documents.linked', { n: report.summary.linked_orders }) }}
      </p>
    </section>

    <ImportReportPanel v-if="report" :report="report" @batch-deleted="report = null; reload()" />

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.batches_title') }}</h2>
      <p v-if="batches.length === 0" class="text-sm text-neutral-500">{{ t('shoptet.batches_empty') }}</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="b in batches" :key="b.id" class="py-2">
          <span class="font-medium">{{ formatDateTime(b.applied_at ?? b.created_at) }}</span>
          <span class="ml-2 text-neutral-500 break-all">{{ b.file_name }}</span>
          <span class="block text-xs text-neutral-500">
            {{ t('shoptet.documents.batch_summary', {
              created: b.summary.created ?? 0, skipped: Number(b.summary.skipped ?? 0) + Number(b.summary.duplicates ?? 0),
              failed: b.summary.failed ?? 0, linked: b.summary.linked_orders ?? 0,
            }) }}
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>
