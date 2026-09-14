<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDuration, parseDuration } from '@/utils/timeBilling'
import { evalMath } from '@/directives/vMath'

const props = defineProps<{
  modelValue: number | string
  durationMinutes?: number | null
  allowNegative?: boolean
  legacyDecimals?: number
}>()
const emit = defineEmits<{
  'update:modelValue': [value: number]
  'update:durationMinutes': [value: number | null]
}>()
const { t } = useI18n()
const input = ref<HTMLInputElement>()
const text = ref('')
const invalid = ref(false)
const focused = ref(false)
const display = () => props.durationMinutes != null ? formatDuration(props.durationMinutes) : String(props.modelValue ?? '')
watch(() => [props.modelValue, props.durationMinutes], () => {
  if (focused.value) return
  text.value = display()
  invalid.value = false
  input.value?.setCustomValidity('')
}, { immediate: true })

function update(event: Event) {
  const target = event.target as HTMLInputElement
  text.value = target.value
  let parsed = parseDuration(text.value, props.legacyDecimals ?? 3)
  if (!parsed) {
    const hours = evalMath(text.value)
    if (hours !== null) parsed = parseDuration(String(hours), props.legacyDecimals ?? 3)
  }
  invalid.value = !parsed || (!props.allowNegative && parsed.hours < 0)
  target.setCustomValidity(invalid.value ? t('time_billing.invalid') : '')
  if (!parsed || invalid.value) return
  emit('update:durationMinutes', parsed.duration_minutes)
  emit('update:modelValue', parsed.hours)
}
</script>

<template>
  <input ref="input" data-time-duration :value="text" type="text" inputmode="text"
    :aria-label="t('time_billing.duration')" :aria-invalid="invalid || undefined"
    :title="t('time_billing.hint')" :placeholder="t('time_billing.hours_minutes')"
    class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono bg-surface"
    @focus="focused = true" @blur="focused = false; !invalid && (text = display())" @input="update" />
</template>
