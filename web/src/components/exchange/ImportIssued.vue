<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { uploadImport, type ImportReport } from '@/api/imports'
import ImportReportPanel from '@/components/exchange/ImportReportPanel.vue'
import { apiErrorMessage } from '@/api/errors'
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
    // Musí to jít přes apiErrorMessage: `e.message` je u axiosu jen „Request failed with
    // status code 500". Backend přitom celý běh zastaví hláškou, která je NÁVOD (chybí
    // číselník sazeb členských států → spusťte php api/bin/migrate.php) — a ta žije
    // v `response.data.error.message`, kam se `e.message` nikdy nepodívá.
    error.value = apiErrorMessage(e, t('imports.upload_failed'))
  } finally {
    uploading.value = false
  }
}
function clear() {
  files.value = []
  report.value = null
  error.value = ''
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
    <ImportReportPanel v-if="report" :report="report" />
  </div>
</template>
