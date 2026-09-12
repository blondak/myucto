<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/** Krátký návod krok za krokem; kroky jsou pole překladů pod klíčem `messageKey`. */
const props = defineProps<{ messageKey: string }>()
const { t, tm, rt } = useI18n()

const steps = computed<string[]>(() => {
  const raw = tm(props.messageKey) as unknown
  return Array.isArray(raw) ? raw.map(item => rt(item as Parameters<typeof rt>[0])) : []
})
</script>

<template>
  <details class="bg-primary-50/60 border border-primary-200 rounded-lg px-4 py-3 text-sm" open>
    <summary class="cursor-pointer font-medium text-primary-700">{{ t('shoptet.steps_title') }}</summary>
    <ol class="list-decimal ml-5 mt-2 space-y-1 text-neutral-700">
      <li v-for="(step, i) in steps" :key="i">{{ step }}</li>
    </ol>
  </details>
</template>
