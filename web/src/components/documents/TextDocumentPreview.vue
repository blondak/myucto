<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  MAX_TEXT_PREVIEW_BYTES,
  decodeTextPreview,
  documentPreviewKind,
  formatXmlPreview,
  parseCsvPreview,
  plainTextPreview,
  type CsvPreview,
} from './textPreview'

const props = defineProps<{
  document: Pick<DocItem, 'id' | 'doc_type' | 'original_name' | 'mime_type' | 'size_bytes'>
}>()

const { t } = useI18n()
const loading = ref(false)
const failed = ref(false)
const tooLarge = computed(() => props.document.size_bytes > MAX_TEXT_PREVIEW_BYTES)
const lines = ref<string[]>([])
const truncated = ref(false)
const invalidXml = ref(false)
const csv = ref<CsvPreview | null>(null)
const kind = computed(() => documentPreviewKind(props.document))
let requestVersion = 0

async function load() {
  const version = ++requestVersion
  failed.value = false
  lines.value = []
  csv.value = null
  truncated.value = false
  invalidXml.value = false
  if (tooLarge.value || !['xml', 'txt', 'csv'].includes(kind.value ?? '')) return

  loading.value = true
  try {
    const buffer = await documentsApi.previewBytes(props.document.id)
    if (version !== requestVersion) return
    const text = decodeTextPreview(buffer)
    if (kind.value === 'csv') {
      csv.value = parseCsvPreview(text)
      truncated.value = csv.value.truncated || csv.value.columnsTruncated
    } else if (kind.value === 'xml') {
      const preview = formatXmlPreview(text)
      lines.value = preview.lines
      truncated.value = preview.truncated
      invalidXml.value = !preview.valid
    } else {
      const preview = plainTextPreview(text)
      lines.value = preview.lines
      truncated.value = preview.truncated
    }
  } catch {
    if (version === requestVersion) failed.value = true
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

function delimiterLabel(delimiter: string): string {
  if (delimiter === ';') return t('documents.text_preview.delimiter_semicolon')
  if (delimiter === '\t') return t('documents.text_preview.delimiter_tab')
  return t('documents.text_preview.delimiter_comma')
}

function xmlLineClass(line: string): string {
  const trimmed = line.trimStart()
  if (trimmed.startsWith('<!--')) return 'text-neutral-400 dark:text-neutral-500'
  if (trimmed.startsWith('<?')) return 'text-accent-500'
  if (trimmed.startsWith('<![CDATA[')) return 'text-success-500'
  return 'text-primary-200 dark:text-primary-300'
}

watch(() => props.document.id, load, { immediate: true })
</script>

<template>
  <div v-if="loading" class="min-h-48 flex items-center justify-center text-sm text-neutral-500">
    {{ t('documents.text_preview.loading') }}
  </div>
  <div v-else-if="tooLarge" class="min-h-48 flex items-center justify-center px-6 text-center text-sm text-neutral-500">
    {{ t('documents.text_preview.too_large', { mb: Math.round(MAX_TEXT_PREVIEW_BYTES / 1024 / 1024) }) }}
  </div>
  <div v-else-if="failed" class="min-h-48 flex items-center justify-center px-6 text-center text-sm text-danger-500">
    {{ t('documents.text_preview.load_failed') }}
  </div>
  <div v-else class="relative">
    <div v-if="invalidXml || truncated" class="flex flex-wrap gap-x-4 gap-y-1 px-4 py-2 text-xs bg-warning-50 text-warning-700 border-b border-warning-100">
      <span v-if="invalidXml">{{ t('documents.text_preview.invalid_xml') }}</span>
      <span v-if="truncated">{{ t('documents.text_preview.truncated') }}</span>
    </div>

    <div v-if="kind === 'csv' && csv" class="max-h-[72vh] overflow-auto bg-surface">
      <div class="sticky left-0 px-3 py-1.5 text-[11px] text-neutral-500 bg-neutral-50 border-b border-neutral-200">
        {{ t('documents.text_preview.csv_summary', {
          rows: csv.rows.length,
          columns: csv.rows[0]?.length ?? 0,
          delimiter: delimiterLabel(csv.delimiter),
        }) }}
      </div>
      <table v-if="csv.rows.length" class="min-w-full text-xs border-separate border-spacing-0">
        <thead>
          <tr>
            <th
              v-for="(cell, col) in csv.rows[0]"
              :key="col"
              class="sticky top-[25px] z-10 px-3 py-2 text-left font-semibold text-neutral-700 bg-neutral-100 border-r border-b border-neutral-200 whitespace-pre-wrap break-words max-w-[32rem]"
            >{{ cell || ' ' }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, rowIndex) in csv.rows.slice(1)" :key="rowIndex" class="odd:bg-surface even:bg-neutral-50">
            <td
              v-for="col in (csv.rows[0]?.length ?? 0)"
              :key="col"
              class="px-3 py-1.5 align-top text-neutral-700 border-r border-b border-neutral-100 whitespace-pre-wrap break-words max-w-[32rem]"
            >{{ row[col - 1] || ' ' }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="p-6 text-center text-sm text-neutral-400">{{ t('documents.text_preview.empty') }}</p>
    </div>

    <div v-else class="max-h-[72vh] overflow-auto bg-neutral-900 dark:bg-neutral-100">
      <ol v-if="lines.length" class="min-w-max py-3 font-mono text-xs leading-5">
        <li v-for="(line, index) in lines" :key="index" class="flex">
          <span class="sticky left-0 w-14 shrink-0 pr-3 text-right select-none text-neutral-500 bg-neutral-900 dark:bg-neutral-100 border-r border-neutral-700 dark:border-neutral-300">{{ index + 1 }}</span>
          <code class="block pl-4 pr-6 whitespace-pre" :class="kind === 'xml' ? xmlLineClass(line) : 'text-neutral-100 dark:text-neutral-900'">{{ line || ' ' }}</code>
        </li>
      </ol>
      <p v-else class="p-6 text-center text-sm text-neutral-400">{{ t('documents.text_preview.empty') }}</p>
    </div>
  </div>
</template>
