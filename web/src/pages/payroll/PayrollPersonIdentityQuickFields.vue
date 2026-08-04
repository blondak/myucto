<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  PayrollPersonIdentifier,
  PayrollPersonIdentifierType,
} from '@/api/payroll'

const props = defineProps<{
  identifiers: PayrollPersonIdentifier[]
  disabled: boolean
}>()

const firstName = defineModel<string>('firstName', { required: true })
const lastName = defineModel<string>('lastName', { required: true })
const birthNumber = defineModel<string>('birthNumber', { required: true })
const ecp = defineModel<string>('ecp', { required: true })
const vcp = defineModel<string>('vcp', { required: true })
const foreignTaxIdentifier = defineModel<string>('foreignTaxIdentifier', {
  required: true,
})

const { t } = useI18n()
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-100 disabled:text-neutral-500'
const labelClass = 'block text-xs font-medium text-neutral-600'

const masked = computed<Record<PayrollPersonIdentifierType, string | null>>(() => {
  const values: Record<PayrollPersonIdentifierType, string | null> = {
    birth_number: null,
    ecp: null,
    vcp: null,
    foreign_tax_identifier: null,
  }
  for (const identifier of props.identifiers) {
    values[identifier.identifier_type] = identifier.value_masked
  }

  return values
})

function placeholder(type: PayrollPersonIdentifierType): string {
  return masked.value[type] ?? t('payroll.people.quick_edit.not_set')
}
</script>

<template>
  <fieldset :disabled="disabled" class="space-y-4">
    <legend class="text-sm font-semibold text-neutral-900">
      {{ t('payroll.people.quick_edit.personal_title') }}
    </legend>
    <p class="-mt-3 text-xs text-neutral-500">
      {{ t('payroll.people.quick_edit.structured_name_hint') }}
    </p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <label :class="labelClass">
        {{ t('payroll.people.quick_edit.first_name') }}
        <input
          v-model="firstName"
          required
          autocomplete="given-name"
          :class="inputClass"
          data-test="first-name"
        >
      </label>
      <label :class="labelClass">
        {{ t('payroll.people.quick_edit.last_name') }}
        <input
          v-model="lastName"
          required
          autocomplete="family-name"
          :class="inputClass"
          data-test="last-name"
        >
      </label>
      <label :class="[labelClass, 'sm:col-span-2']">
        {{ t('payroll.people.quick_edit.birth_number') }}
        <input
          v-model="birthNumber"
          autocomplete="off"
          inputmode="numeric"
          :placeholder="placeholder('birth_number')"
          :class="inputClass"
          data-test="birth-number"
        >
        <span class="mt-1 block text-xs font-normal text-neutral-500">
          {{ t('payroll.people.quick_edit.sensitive_replace_hint') }}
        </span>
      </label>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 sm:p-4">
      <div>
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.people.quick_edit.alternative_identifiers_title') }}
        </h3>
        <p class="mt-1 text-xs text-neutral-500">
          {{ t('payroll.people.quick_edit.alternative_identifiers_hint') }}
        </p>
      </div>
      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label :class="labelClass">
          {{ t('payroll.people.profile.identifier_type.ecp') }}
          <input
            v-model="ecp"
            autocomplete="off"
            :placeholder="placeholder('ecp')"
            :class="inputClass"
            data-test="identifier-ecp"
          >
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.profile.identifier_type.vcp') }}
          <input
            v-model="vcp"
            autocomplete="off"
            :placeholder="placeholder('vcp')"
            :class="inputClass"
            data-test="identifier-vcp"
          >
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.profile.identifier_type.foreign_tax_identifier') }}
          <input
            v-model="foreignTaxIdentifier"
            autocomplete="off"
            :placeholder="placeholder('foreign_tax_identifier')"
            :class="inputClass"
            data-test="identifier-foreign-tax"
          >
        </label>
      </div>
    </div>
  </fieldset>
</template>
