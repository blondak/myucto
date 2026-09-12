<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ code: string; label?: string; testId?: string }>()
const { t } = useI18n()
const toast = useToast()

async function copy() {
  try {
    await navigator.clipboard.writeText(props.code)
    toast.success(t('eshop.integrations.copied'))
  } catch {
    toast.error(t('eshop.integrations.copy_failed'))
  }
}
</script>

<template>
  <div class="min-w-0 rounded-md border border-neutral-200 bg-neutral-50">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-3 py-1.5">
      <span class="text-xs font-medium text-neutral-600">{{ label }}</span>
      <button type="button" :class="btnOutlineSm('neutral')" :data-test="testId ? `${testId}-copy` : undefined" @click="copy">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.copy" /></svg>{{ t('eshop.integrations.copy') }}
      </button>
    </div>
    <pre class="overflow-x-auto p-3 text-xs leading-relaxed"><code :data-test="testId">{{ code }}</code></pre>
  </div>
</template>
