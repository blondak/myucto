<script setup lang="ts">
defineProps<{ modelValue: boolean; disabled?: boolean; label?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <button type="button" role="switch" :aria-checked="modelValue" :aria-label="label" :title="label" :disabled="disabled"
          class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500/30 disabled:cursor-not-allowed disabled:opacity-50"
          :class="modelValue ? 'bg-success-500' : 'bg-neutral-300'"
          @click="emit('update:modelValue', !modelValue)">
    <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
          :class="modelValue ? 'translate-x-[1.125rem]' : 'translate-x-0.5'" />
  </button>
</template>
