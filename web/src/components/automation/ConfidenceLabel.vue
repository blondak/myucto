<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ confidence: number }>()
const { t } = useI18n()

const normalized = computed(() => Math.min(1, Math.max(0, props.confidence)))
const level = computed(() => normalized.value >= 0.9 ? 'high' : normalized.value >= 0.7 ? 'medium' : 'low')
const label = computed(() => t('automation.confidence_label', {
  level: t(`automation.confidence.${level.value}`),
  pct: Math.round(normalized.value * 100),
}))
</script>

<template>
  <span class="text-xs text-neutral-600 whitespace-nowrap">{{ label }}</span>
</template>
