<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ variant: 'auto' | 'suggested' | 'ai' }>()
const { t } = useI18n()

const classes = computed(() => props.variant === 'auto'
  ? 'bg-primary-50 text-primary-700'
  : props.variant === 'ai'
    ? 'bg-warning-50 text-warning-600'
    : 'bg-warning-50 text-warning-600')
const label = computed(() => t(`automation.badge_${props.variant}`))
</script>

<template>
  <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="classes">
    {{ label }}
  </span>
</template>
