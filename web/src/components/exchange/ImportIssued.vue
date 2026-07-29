<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { uploadImport, type ImportReport, type ImportResultRow } from '@/api/imports'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()

// ── Vystavené (existující flow) ─────────────────────────────────────────────
const files = ref<File[]>([])
const uploading = ref(false)
const error = ref('')
const report = ref<ImportReport | null>(null)

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  if (!input.files) return
  files.value = Array.from(input.files)
  report.value = null
  error.value = ''
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  const dropped = e.dataTransfer?.files
  if (!dropped) return
  files.value = Array.from(dropped)
  report.value = null
  error.value = ''
}
async function submit() {
  if (files.value.length === 0) return
  uploading.value = true
  error.value = ''
  report.value = null
  try {
    report.value = await uploadImport(files.value, 'issued')
  } catch (e: any) {
    error.value = e?.message || t('imports.upload_failed')
  } finally {
    uploading.value = false
  }
}
function clear() {
  files.value = []
  report.value = null
  error.value = ''
}
const rows = computed<ImportResultRow[]>(() => report.value?.results ?? [])
const statusBadge = (s: string) => {
  if (s === 'created') return 'bg-success-50 text-success-600 border-success-500/40'
  if (s === 'skipped') return 'bg-warning-50 text-warning-600 border-warning-500/40'
  return 'bg-danger-50 text-danger-500 border-danger-500/40'
}
</script>

<template>
  <div>
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm max-w-3xl">
      <div class="p-5 space-y-4">
        <div class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
          <strong>{{ t('imports.supplier_required_title') }}:</strong>
          {{ t('imports.supplier_required_hint') }}
        </div>
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.status_rule_title') }}:</strong>
          {{ t('imports.status_rule_hint') }}
        </div>
        <label
          @dragover.prevent
          @drop="onDrop"
          class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
        >
          <input
            type="file"
            multiple
            accept=".xml,.isdoc,.isdocx,.zip,.pdf,application/xml,application/zip,application/x-isdoc,application/pdf"
            @change="onPick"
            class="hidden"
          />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">{{ t('imports.drop_or_click') }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('imports.formats_hint') }}</div>
        </label>

        <div v-if="files.length > 0" class="border border-neutral-200 rounded-md p-3 bg-neutral-50">
          <div class="text-xs font-medium text-neutral-700 mb-2">{{ t('imports.selected_files') }} ({{ files.length }})</div>
          <ul class="text-sm space-y-1 font-mono">
            <li v-for="f in files" :key="f.name" class="flex justify-between text-neutral-700">
              <span class="truncate">{{ f.name }}</span>
              <span class="text-neutral-400 ml-2">{{ Math.round(f.size / 1024) }} kB</span>
            </li>
          </ul>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <div class="flex gap-2">
          <button
            @click="submit"
            :disabled="uploading || files.length === 0"
            :class="btnFilled('primary')"
            class="flex-1 justify-center"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload"/></svg>
            {{ uploading ? t('imports.uploading') : t('imports.upload') }}
          </button>
          <button
            v-if="files.length > 0 || report"
            @click="clear"
            :disabled="uploading"
            :class="btnOutline('neutral')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/></svg>
            {{ t('common.close') }}
          </button>
        </div>

        <p class="text-xs text-neutral-500">{{ t('imports.hint') }}</p>
      </div>
    </div>

    <!-- Report -->
    <div v-if="report" class="mt-6 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm max-w-3xl">
      <div class="flex items-center gap-4 mb-4 text-sm">
        <div><span class="font-semibold text-success-600">{{ report.summary.created }}</span> {{ t('imports.summary_created') }}</div>
        <div><span class="font-semibold text-warning-600">{{ report.summary.skipped }}</span> {{ t('imports.summary_skipped') }}</div>
        <div><span class="font-semibold text-danger-500">{{ report.summary.failed }}</span> {{ t('imports.summary_failed') }}</div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200">
              <th class="py-2 pr-3">{{ t('imports.col_file') }}</th>
              <th class="py-2 pr-3">{{ t('imports.col_status') }}</th>
              <th class="py-2 pr-3">{{ t('imports.col_varsymbol') }}</th>
              <th class="py-2">{{ t('imports.col_detail') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i" class="border-b border-neutral-100">
              <td class="py-2 pr-3 font-mono text-xs truncate max-w-xs">{{ r.file }}</td>
              <td class="py-2 pr-3">
                <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="statusBadge(r.status)">
                  {{ t('imports.status_' + r.status) }}
                </span>
              </td>
              <td class="py-2 pr-3 font-mono">{{ r.varsymbol || '—' }}</td>
              <td class="py-2 text-neutral-600">
                <span v-if="r.status === 'created'">
                  <!-- Vystavená faktura -->
                  <a v-if="r.invoice_id" :href="`/invoices/${r.invoice_id}`" class="text-primary-700 hover:underline">#{{ r.invoice_id }}</a>
                  <!-- Přijatá faktura (purchase route) -->
                  <a v-if="r.purchase_invoice_id" :href="`/purchase-invoices/${r.purchase_invoice_id}`" class="text-primary-700 hover:underline">
                    #{{ r.purchase_invoice_id }}
                  </a>
                  <!-- Kind badge — vystavená vs přijatá -->
                  <span
                    v-if="r.kind"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.kind === 'purchase'
                      ? 'bg-warning-50 text-warning-600 border-warning-500/40'
                      : 'bg-primary-50 text-primary-700 border-primary-500/40'"
                  >
                    {{ t('imports.kind_' + r.kind) }}
                  </span>
                  <span
                    v-if="r.imported_status"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.imported_status === 'paid' ? 'bg-success-50 text-success-600 border-success-500/40' : 'bg-neutral-50 text-neutral-600 border-neutral-200'"
                  >
                    {{ t('imports.imported_as_' + r.imported_status) }}
                  </span>
                  <span v-if="r.client_created" class="ml-2 text-xs text-success-600">{{ t('imports.new_client') }}</span>
                  <span v-if="r.project_id" class="ml-2 text-xs text-primary-700">{{ t('imports.new_project') }}</span>
                </span>
                <span v-else class="text-xs">{{ r.reason || '—' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
