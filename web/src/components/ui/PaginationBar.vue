<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ page: number; perPage: number; total: number; embedded?: boolean }>()
const emit = defineEmits<{ 'update:page': [number] }>()
const { t } = useI18n()

const totalPages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const from = computed(() => props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1)
const to = computed(() => Math.min(props.total, props.page * props.perPage))

function go(page: number) {
  emit('update:page', Math.min(Math.max(1, page), totalPages.value))
}
</script>

<template>
  <div v-if="total > perPage" class="flex flex-wrap items-center justify-between gap-3 bg-surface px-4 py-3 text-sm" :class="embedded ? 'border-t border-neutral-200' : 'rounded-lg border border-neutral-200'">
    <span class="text-neutral-500 tabular-nums">{{ t('common.pagination_range', { from, to, total }) }}</span>
    <!-- Šipky uvnitř tlačítek dávají směr i bez čtení popisku; číslo stránky
         je v mono, ať se lišta při přechodu 9 → 10 nehýbe. -->
    <div class="flex flex-wrap items-center gap-1.5">
      <button type="button" :disabled="page <= 1"
        class="cursor-pointer inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-neutral-300 h-8 px-3 transition-all duration-150 hover:bg-neutral-50 hover:border-neutral-400 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-40 disabled:active:translate-y-0"
        @click="go(page - 1)">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        {{ t('common.previous') }}
      </button>
      <span class="px-2.5 font-mono text-xs text-neutral-600">{{ page }} / {{ totalPages }}</span>
      <button type="button" :disabled="page >= totalPages"
        class="cursor-pointer inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-neutral-300 h-8 px-3 transition-all duration-150 hover:bg-neutral-50 hover:border-neutral-400 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-40 disabled:active:translate-y-0"
        @click="go(page + 1)">
        {{ t('common.next') }}
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>
  </div>
</template>
