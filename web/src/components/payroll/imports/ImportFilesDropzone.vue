<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import {
  IMPORT_MAX_FILE_BYTES,
  IMPORT_MAX_FILES,
  IMPORT_MAX_TOTAL_BYTES,
  formatBytes,
  mergeImportFiles,
  type ImportFileRejection,
} from './importHelpers'

const props = withDefaults(defineProps<{
  files: File[]
  accept: string
  allowedExtensions: string[]
  dropHint: string
  disabled?: boolean
  testId?: string
}>(), {
  disabled: false,
  testId: undefined,
})

const emit = defineEmits<{
  'update:files': [files: File[]]
}>()

const { t, locale } = useI18n()

const input = ref<HTMLInputElement | null>(null)
const dragDepth = ref(0)
const rejected = ref<ImportFileRejection[]>([])
const noFile = ref(false)
const hintId = `import-files-hint-${useId()}`

const isDragging = computed(() => dragDepth.value > 0 && !props.disabled)
const totalBytes = computed(() => props.files.reduce((sum, file) => sum + file.size, 0))
const limitText = computed(() => t('payroll_imports.files.limits', {
  extensions: props.allowedExtensions.join(', '),
  count: IMPORT_MAX_FILES,
  size: formatBytes(IMPORT_MAX_FILE_BYTES, locale.value),
  total: formatBytes(IMPORT_MAX_TOTAL_BYTES, locale.value),
}))

function addFiles(list: FileList | File[] | null | undefined) {
  const incoming = list ? Array.from(list) : []
  noFile.value = incoming.length === 0
  if (incoming.length === 0) return
  const result = mergeImportFiles(props.files, incoming, props.allowedExtensions)
  rejected.value = result.rejected
  if (result.files.length !== props.files.length) emit('update:files', result.files)
}

function openPicker() {
  if (!props.disabled) input.value?.click()
}

function onSurfaceClick(event: MouseEvent) {
  if ((event.target as HTMLElement).closest('button')) return
  openPicker()
}

function onChange(event: Event) {
  const target = event.target as HTMLInputElement
  addFiles(target.files)
  // Bez vyčištění by výběr téhož souboru podruhé neodpálil `change`.
  target.value = ''
}

function onDragEnter(event: DragEvent) {
  event.preventDefault()
  if (props.disabled) return
  dragDepth.value += 1
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  if (!props.disabled && event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function onDragLeave(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

function onDrop(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = 0
  if (props.disabled) return
  addFiles(event.dataTransfer?.files)
}

function removeFile(index: number) {
  const next = [...props.files]
  next.splice(index, 1)
  rejected.value = []
  emit('update:files', next)
}

function clearAll() {
  rejected.value = []
  emit('update:files', [])
}
</script>

<template>
  <div class="space-y-3">
    <div
      :data-testid="testId"
      role="group"
      :aria-disabled="disabled"
      :aria-describedby="hintId"
      class="flex min-h-32 flex-col items-center justify-center rounded-xl border-2 border-dashed px-5 py-6 text-center transition-colors"
      :class="[
        disabled ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-60' : 'cursor-pointer',
        isDragging
          ? 'border-payroll-500 bg-payroll-50'
          : !disabled && 'border-neutral-300 bg-neutral-50 hover:border-payroll-400 hover:bg-payroll-50/50',
      ]"
      @click="onSurfaceClick"
      @dragenter="onDragEnter"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        ref="input"
        type="file"
        multiple
        :accept="accept"
        class="sr-only"
        :disabled="disabled"
        :aria-describedby="hintId"
        @change="onChange"
      >
      <svg class="h-8 w-8 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.upload" />
      </svg>
      <p class="mt-2 font-medium text-neutral-900">
        {{ isDragging ? t('payroll_imports.files.drop_active') : dropHint }}
      </p>
      <button type="button" :class="`${btnFilled('primary')} mt-3`" :disabled="disabled" @click="openPicker">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.upload" />
        </svg>
        {{ t('payroll_imports.files.choose') }}
      </button>
      <p :id="hintId" class="mt-2 text-xs text-neutral-500">{{ limitText }}</p>
    </div>

    <div v-if="rejected.length || noFile" role="status" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      <p v-if="noFile">{{ t('payroll_imports.files.no_file') }}</p>
      <p v-for="item in rejected" :key="`${item.name}-${item.reason}`">
        {{ t(`payroll_imports.files.reject.${item.reason}`, { name: item.name }) }}
      </p>
    </div>

    <div v-if="files.length" class="rounded-lg border border-neutral-200">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-3 py-2">
        <p class="text-xs font-medium text-neutral-600">
          {{ t('payroll_imports.files.selected', { count: files.length, size: formatBytes(totalBytes, locale) }) }}
        </p>
        <button type="button" :class="btnOutlineSm('neutral')" :disabled="disabled" @click="clearAll">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('payroll_imports.files.clear') }}
        </button>
      </div>
      <ul class="divide-y divide-neutral-100">
        <li v-for="(file, index) in files" :key="`${file.name}-${file.size}`" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
          <span class="flex min-w-0 items-center gap-2">
            <svg class="h-4 w-4 shrink-0 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
            <span class="truncate" :title="file.name">{{ file.name }}</span>
            <span class="shrink-0 text-xs text-neutral-500">{{ formatBytes(file.size, locale) }}</span>
          </span>
          <button
            type="button"
            :class="btnIconSm('danger')"
            :disabled="disabled"
            :title="t('payroll_imports.files.remove', { name: file.name })"
            :aria-label="t('payroll_imports.files.remove', { name: file.name })"
            @click="removeFile(index)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
