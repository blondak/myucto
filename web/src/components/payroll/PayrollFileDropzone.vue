<script setup lang="ts">
import { computed, ref } from 'vue'
import { ICONS } from '@/components/ui/buttonStyles'

export type PayrollFileRejectReason = 'unsupported_file' | 'file_too_large'

const props = withDefaults(defineProps<{
  accept?: string
  allowedExtensions?: string[]
  maxSizeBytes?: number
  disabled?: boolean
  selectedFileName?: string
  dropHint: string
  dropActiveHint: string
  fileHint: string
  error?: string
  selectedText?: string
  dropzoneTestId?: string
  inputTestId?: string
  selectedTestId?: string
}>(), {
  accept: '.csv,.xlsx',
  allowedExtensions: () => ['csv', 'xlsx'],
  maxSizeBytes: 5_000_000,
  disabled: false,
  error: '',
  selectedFileName: '',
  selectedText: '',
  dropzoneTestId: undefined,
  inputTestId: undefined,
  selectedTestId: undefined,
})

const emit = defineEmits<{
  selected: [file: File]
  rejected: [reason: PayrollFileRejectReason, file: File]
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const dragDepth = ref(0)
const isDragging = computed(() => dragDepth.value > 0 && !props.disabled)

function openPicker() {
  if (!props.disabled) fileInput.value?.click()
}

function handleFile(file: File) {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!props.allowedExtensions.map(item => item.toLowerCase()).includes(extension)) {
    emit('rejected', 'unsupported_file', file)
    return
  }
  if (file.size > props.maxSizeBytes) {
    emit('rejected', 'file_too_large', file)
    return
  }
  emit('selected', file)
}

function chooseFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) handleFile(file)
  input.value = ''
}

function dragEnter(event: DragEvent) {
  event.preventDefault()
  if (props.disabled) return
  dragDepth.value += 1
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function dragOver(event: DragEvent) {
  event.preventDefault()
  if (!props.disabled && event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function dragLeave(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

function dropFile(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = 0
  if (props.disabled) return
  const file = event.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}
</script>

<template>
  <div
    :data-testid="dropzoneTestId"
    role="button"
    :tabindex="disabled ? -1 : 0"
    :aria-disabled="disabled"
    class="flex min-h-36 flex-col items-center justify-center rounded-xl border-2 border-dashed px-5 py-6 text-center transition-colors focus:outline-none focus:ring-2 focus:ring-payroll-500/30"
    :class="[
      disabled ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-60' : 'cursor-pointer',
      isDragging
        ? 'border-payroll-500 bg-payroll-50'
        : error
          ? 'border-danger-500 bg-danger-50/50'
          : !disabled && 'border-neutral-300 bg-neutral-50 hover:border-payroll-400 hover:bg-payroll-50/50',
    ]"
    :aria-invalid="error ? 'true' : undefined"
    @click="openPicker"
    @keydown.enter.prevent="openPicker"
    @keydown.space.prevent="openPicker"
    @dragenter="dragEnter"
    @dragover="dragOver"
    @dragleave="dragLeave"
    @drop="dropFile"
  >
    <input
      ref="fileInput"
      :data-testid="inputTestId"
      type="file"
      :accept="accept"
      class="sr-only"
      :disabled="disabled"
      @change="chooseFile"
    >
    <svg
      class="h-8 w-8 text-payroll-600"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path :d="ICONS.upload" />
    </svg>
    <p class="mt-2 font-medium text-neutral-900">
      {{ isDragging ? dropActiveHint : dropHint }}
    </p>
    <p class="mt-1 text-xs text-neutral-500">{{ fileHint }}</p>
    <p v-if="error" role="alert" class="mt-2 text-sm font-medium text-danger-600">
      {{ error }}
    </p>
    <p
      v-if="selectedFileName"
      :data-testid="selectedTestId"
      :title="selectedFileName"
      class="mt-3 max-w-full truncate rounded-full bg-payroll-100 px-3 py-1 text-xs font-medium text-payroll-700"
    >
      {{ selectedText || selectedFileName }}
    </p>
  </div>
</template>
