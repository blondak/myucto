<script setup lang="ts">
/**
 * Volba data úhrady mzdové dávky: podle splatnosti (výchozí), dnes, nebo
 * vlastní datum.
 *
 * Zaplatit dřív smí účetní vždy. Pozdější datum než splatnost znamená platbu
 * po lhůtě (penále u pojistného, úrok z prodlení u daně a mzdy), proto se
 * ukáže výrazné varování a datum projde jen se zaškrtnutým potvrzením.
 * Minulé datum pole nepustí - banka by příkaz se zpětným datem odmítla.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { formatDate } from '@/composables/useFormat'
import {
  effectivePaymentDate,
  isLatePaymentDate,
  type PaymentDateChoice,
  type PaymentDateMode,
} from './payrollPaymentDate'

const props = defineProps<{
  modelValue: PaymentDateChoice
  /** Datum „podle splatnosti" (poslední včasné datum příkazu). */
  defaultDate: string | null
  today: string
  lateConfirmed: boolean
  disabled?: boolean
  testId?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: PaymentDateChoice]
  'update:lateConfirmed': [value: boolean]
}>()

const { t } = useI18n()

const modes: PaymentDateMode[] = ['statutory', 'today', 'custom']

const effective = computed(() =>
  effectivePaymentDate(props.modelValue, props.defaultDate, props.today),
)
const late = computed(() => isLatePaymentDate(effective.value, props.defaultDate))
const earlier = computed(() =>
  effective.value !== null
  && props.defaultDate !== null
  && effective.value < props.defaultDate,
)
const statutoryInPast = computed(() =>
  props.modelValue.mode === 'statutory'
  && props.defaultDate !== null
  && props.defaultDate < props.today,
)
const customInPast = computed(() =>
  props.modelValue.mode === 'custom'
  && props.modelValue.customDate !== ''
  && props.modelValue.customDate < props.today,
)

function optionLabel(mode: PaymentDateMode): string {
  if (mode === 'statutory') {
    return props.defaultDate
      ? t('payroll.payments.payment_date.statutory_with_date', { date: formatDate(props.defaultDate) })
      : t('payroll.payments.payment_date.statutory')
  }
  if (mode === 'today') {
    return t('payroll.payments.payment_date.today_with_date', { date: formatDate(props.today) })
  }
  return t('payroll.payments.payment_date.custom')
}

function setMode(mode: PaymentDateMode): void {
  emit('update:modelValue', {
    mode,
    customDate: mode === 'custom' && props.modelValue.customDate === ''
      ? props.today
      : props.modelValue.customDate,
  })
  emit('update:lateConfirmed', false)
}

function setCustomDate(value: string): void {
  emit('update:modelValue', { mode: 'custom', customDate: value })
  emit('update:lateConfirmed', false)
}
</script>

<template>
  <fieldset class="block min-w-0" :data-test="testId ?? 'payment-date-choice'">
    <legend class="mb-1 block text-sm font-medium text-neutral-700">
      {{ t('payroll.payments.payment_date.label') }}
    </legend>
    <div class="flex flex-wrap items-center gap-2">
      <label
        v-for="mode in modes"
        :key="mode"
        class="inline-flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm"
        :class="modelValue.mode === mode
          ? 'border-payroll-400 bg-payroll-50 text-payroll-800'
          : 'border-neutral-300 bg-surface text-neutral-700 hover:bg-neutral-50'"
        :data-test="`payment-date-mode-${mode}`"
      >
        <input
          type="radio"
          class="accent-payroll-600"
          :checked="modelValue.mode === mode"
          :disabled="disabled"
          :value="mode"
          @change="setMode(mode)"
        >
        {{ optionLabel(mode) }}
      </label>
      <DateInput
        v-if="modelValue.mode === 'custom'"
        :model-value="modelValue.customDate"
        :min="today"
        :invalid="customInPast"
        :disabled="disabled"
        accent="payroll"
        class="w-40"
        :aria-label="t('payroll.payments.payment_date.custom')"
        data-test="payment-date-custom"
        @update:model-value="setCustomDate"
      />
    </div>
    <p
      v-if="customInPast"
      class="mt-2 text-sm text-danger-700"
      data-test="payment-date-past"
    >
      {{ t('payroll.payments.payment_date.past') }}
    </p>
    <p
      v-else-if="statutoryInPast"
      class="mt-2 text-sm text-warning-700"
      data-test="payment-date-statutory-past"
    >
      {{ t('payroll.payments.payment_date.statutory_past', { date: formatDate(defaultDate) }) }}
    </p>
    <p
      v-else-if="earlier"
      class="mt-2 text-sm text-neutral-600"
      data-test="payment-date-earlier"
    >
      {{ t('payroll.payments.payment_date.earlier_hint', { date: formatDate(defaultDate) }) }}
    </p>
    <div
      v-if="late && !customInPast"
      class="mt-3 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-900"
      role="alert"
      data-test="payment-date-late"
    >
      <p class="font-semibold">
        {{ t('payroll.payments.payment_date.late_title', { date: formatDate(defaultDate) }) }}
      </p>
      <p class="mt-1">{{ t('payroll.payments.payment_date.late_body') }}</p>
      <label class="mt-2 flex cursor-pointer items-start gap-2">
        <input
          type="checkbox"
          class="mt-0.5 accent-danger-600"
          :checked="lateConfirmed"
          :disabled="disabled"
          data-test="payment-date-late-confirm"
          @change="emit('update:lateConfirmed', ($event.target as HTMLInputElement).checked)"
        >
        <span>{{ t('payroll.payments.payment_date.late_confirm') }}</span>
      </label>
    </div>
  </fieldset>
</template>
