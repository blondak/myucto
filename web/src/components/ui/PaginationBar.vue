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
    <span class="text-neutral-500">{{ t('common.pagination_range', { from, to, total }) }}</span>
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" :disabled="page <= 1" class="cursor-pointer whitespace-nowrap rounded border border-neutral-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40" @click="go(page - 1)">{{ t('common.previous') }}</button>
      <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
      <button type="button" :disabled="page >= totalPages" class="cursor-pointer whitespace-nowrap rounded border border-neutral-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40" @click="go(page + 1)">{{ t('common.next') }}</button>
    </div>
  </div>
</template>
