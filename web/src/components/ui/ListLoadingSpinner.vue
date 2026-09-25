<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ show: boolean }>()
const { t } = useI18n()
</script>

<template>
  <Transition enter-active-class="transition-opacity duration-150" leave-active-class="transition-opacity duration-150"
    enter-from-class="opacity-0" leave-to-class="opacity-0">
    <div v-if="show" class="fixed inset-0 z-40 flex items-center justify-center pointer-events-none" role="status" aria-live="polite">
      <div class="rounded-full bg-surface/90 shadow-lg p-3 border border-neutral-200">
        <svg class="w-8 h-8 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
          <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <span class="sr-only">{{ t('common.loading_more') }}</span>
      </div>
    </div>
  </Transition>
</template>
