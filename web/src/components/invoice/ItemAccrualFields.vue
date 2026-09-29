<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import AccrualSuggestionHint from '@/components/accounting/AccrualSuggestionHint.vue'
import { detectAccrualPeriod } from '@/utils/accrualPeriod'

/**
 * Období časového rozlišení výnosu (384) u řádku vydané faktury. Vzhled drží nákupní
 * stranu: odkaz „přidat období", pole od–do a návrh období rozpoznaného z textu položky
 * ({@link AccrualSuggestionHint}). Návrh se nikdy neaplikuje sám.
 */
const props = defineProps<{
  from: string | null | undefined
  to: string | null | undefined
  description: string
  stacked?: boolean
}>()
const emit = defineEmits<{
  'update:from': [value: string | null]
  'update:to': [value: string | null]
}>()

const { t } = useI18n()
const open = ref(false)
// Zamítnutí platí pro konkrétní text — po změně popisu se návrh může nabídnout znovu.
const dismissedFor = ref<string | null>(null)

const visible = computed(() => open.value || !!props.from || !!props.to)
const suggestion = computed(() => {
  if (props.from || props.to || dismissedFor.value === (props.description ?? '')) return null
  return detectAccrualPeriod(props.description ?? '')
})

function toggle() {
  if (visible.value) {
    open.value = false
    emit('update:from', null)
    emit('update:to', null)
    return
  }
  open.value = true
}

function applySuggestion() {
  if (!suggestion.value) return
  emit('update:from', suggestion.value.from)
  emit('update:to', suggestion.value.to)
  open.value = true
}
</script>

<template>
  <div class="mt-1 text-xs">
    <button type="button" class="text-primary-600 hover:underline" :title="t('accrual_period.hint')" @click="toggle">
      {{ visible ? t('accrual_period.remove') : t('accrual_period.add') }}
    </button>
    <AccrualSuggestionHint v-if="suggestion" :from="suggestion.from" :to="suggestion.to"
      @apply="applySuggestion" @dismiss="dismissedFor = description ?? ''" />
    <div v-if="visible" :class="stacked ? 'mt-1 grid grid-cols-2 gap-2' : 'mt-1 flex flex-wrap items-center gap-2'">
      <label class="flex items-center gap-1 text-neutral-600">
        <span class="whitespace-nowrap">{{ t('accrual_period.from') }}</span>
        <DateInput :model-value="from ?? null" class="h-8 px-1 border border-neutral-300 rounded bg-surface text-xs"
          @update:model-value="(v: string) => emit('update:from', v || null)" />
      </label>
      <label class="flex items-center gap-1 text-neutral-600">
        <span class="whitespace-nowrap">{{ t('accrual_period.to') }}</span>
        <DateInput :model-value="to ?? null" class="h-8 px-1 border border-neutral-300 rounded bg-surface text-xs"
          @update:model-value="(v: string) => emit('update:to', v || null)" />
      </label>
    </div>
  </div>
</template>
