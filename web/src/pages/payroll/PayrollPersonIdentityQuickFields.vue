<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  PayrollPersonIdentifier,
  PayrollPersonIdentifierType,
} from '@/api/payroll'
import RequiredMark from '@/components/ui/RequiredMark.vue'

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

/**
 * EČP, VČP a zahraniční identifikátor potřebuje zlomek lidí, ale na kartě zabíraly
 * celý řádek hned pod jménem. Sbalí se — a samy se otevřou u toho, kdo je má
 * vyplněné, aby o nich nevěděl jen ten, kdo je zrovna zadává.
 */
const hasAlternativeIdentifier = computed(
  () => masked.value.ecp !== null
    || masked.value.vcp !== null
    || masked.value.foreign_tax_identifier !== null,
)
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
        {{ t('payroll.people.quick_edit.first_name') }} <RequiredMark />
        <input
          v-model="firstName"
          required
          autocomplete="given-name"
          :class="inputClass"
          data-test="first-name"
        >
      </label>
      <label :class="labelClass">
        {{ t('payroll.people.quick_edit.last_name') }} <RequiredMark />
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
        <!--
          Rodné číslo NENÍ povinné pro uložení karty — server ho na zápisu
          nevyžaduje. Povinné je až u přihlášky PREZEC (tam stačí i EČP)
          a u hromadného oznámení zdravotní pojišťovně. Věta to říká rovnou,
          ať uživatel nehádá, jestli mu bez něj karta projde.
        -->
        <span class="mt-1 block text-xs font-normal text-neutral-500">
          {{ t('payroll.people.quick_edit.sensitive_replace_hint') }}
          {{ t('payroll.people.quick_edit.birth_number_optional_hint') }}
        </span>
      </label>
    </div>

    <details
      class="group rounded-lg border border-neutral-200 bg-neutral-50"
      :open="hasAlternativeIdentifier"
      data-test="alternative-identifiers"
    >
      <summary class="flex cursor-pointer list-none items-center gap-2 p-3 sm:p-4">
        <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
        <span class="min-w-0">
          <span class="block text-sm font-medium text-neutral-800">
            {{ t('payroll.people.quick_edit.alternative_identifiers_title') }}
          </span>
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.people.quick_edit.alternative_identifiers_hint') }}
          </span>
        </span>
      </summary>
      <div class="grid grid-cols-1 gap-3 border-t border-neutral-200 p-3 sm:grid-cols-3 sm:p-4">
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
    </details>
  </fieldset>
</template>
