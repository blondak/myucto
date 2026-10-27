<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const dialog = ref<HTMLDialogElement | null>(null)

// Nativní modalita blokuje i klávesnici a obsah teleportovaný mimo layout.
onMounted(() => dialog.value?.showModal())
</script>

<template>
  <dialog ref="dialog" aria-labelledby="logout-progress-label" aria-busy="true"
          class="fixed inset-0 m-0 h-dvh w-screen max-h-none max-w-none border-0 bg-surface text-neutral-700 backdrop:bg-neutral-950/60"
          @cancel.prevent>
    <div class="flex h-full w-full flex-col items-center justify-center gap-4 p-6" role="status" aria-live="polite">
      <svg class="h-12 w-12 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
        <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
      </svg>
      <p id="logout-progress-label" class="text-lg font-medium">{{ t('nav.logging_out') }}</p>
    </div>
  </dialog>
</template>
