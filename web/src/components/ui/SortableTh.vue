<script setup lang="ts">
import { computed } from 'vue'
import type { SortPref } from '@/api/preferences'

const props = withDefaults(defineProps<{
  label: string
  sortKey: string
  sort: SortPref | null
  align?: 'left' | 'right'
}>(), { align: 'left' })

const emit = defineEmits<{ toggle: [key: string] }>()

const active = computed(() => props.sort?.key === props.sortKey)
const dir = computed<'asc' | 'desc' | null>(() => (active.value ? props.sort!.dir : null))
const ariaSort = computed<'ascending' | 'descending' | 'none'>(() =>
  !active.value ? 'none' : dir.value === 'asc' ? 'ascending' : 'descending',
)
</script>

<template>
  <th
    scope="col"
    :aria-sort="ariaSort"
    @click="emit('toggle', sortKey)"
    class="cursor-pointer select-none py-2 px-3 text-xs uppercase tracking-wide font-medium text-neutral-500 hover:text-neutral-800 transition-colors"
    :class="align === 'right' ? 'text-right' : 'text-left'"
  >
    <span class="inline-flex items-center gap-1" :class="align === 'right' ? 'flex-row-reverse' : ''">
      <span>{{ label }}</span>
      <!-- neutrální stav bez ikony; šipka jen u aktivního sloupce -->
      <span v-if="dir" aria-hidden="true" class="text-[10px] text-primary-600 leading-none">
        {{ dir === 'asc' ? '▲' : '▼' }}
      </span>
    </span>
  </th>
</template>
