<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useDimensions } from '@/composables/useDimensions'

/**
 * Výběr jedné hodnoty dimenze daného typu. Hodnoty jdou ve stromovém pořadí,
 * druhý řádek volby nese cestu nadřízených — hledání „Morava" najde i Brno.
 */
const props = withDefaults(defineProps<{
  typeId: number
  modelValue: number | null | undefined
  disabled?: boolean
  placeholder?: string
  ariaLabel?: string
  teleport?: boolean
  compact?: boolean
}>(), {
  disabled: false,
  placeholder: undefined,
  ariaLabel: undefined,
  teleport: false,
  compact: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()

const { t } = useI18n()
const dims = useDimensions()

const options = computed(() => dims.options(props.typeId, props.modelValue ?? null)
  .map(o => ({ value: o.value, label: o.label, secondary: o.secondary })))
</script>

<template>
  <SearchableSelect
    :model-value="modelValue ?? null"
    :options="options"
    :placeholder="placeholder ?? t('dimensions.picker_placeholder')"
    :no-results-label="t('dimensions.picker_no_results')"
    :clear-label="t('dimensions.picker_clear')"
    :aria-label="ariaLabel"
    :disabled="disabled"
    :teleport="teleport"
    :input-class="compact ? '!h-8 text-xs' : ''"
    data-test="dimension-picker"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>
