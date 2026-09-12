<script setup lang="ts">
defineProps<{ step: number; title: string; hint?: string }>()
</script>

<template>
  <div class="flex min-w-0 items-start gap-3">
    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-700">{{ step }}</span>
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold">{{ title }}</h3>
        <slot name="actions" />
      </div>
      <p v-if="hint" class="mt-0.5 max-w-prose text-sm text-neutral-500">{{ hint }}</p>
    </div>
  </div>
</template>
