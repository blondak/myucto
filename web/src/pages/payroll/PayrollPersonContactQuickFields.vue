<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import CountrySelect from '@/components/ui/CountrySelect.vue'

defineProps<{
  currentAddressMasked: string | null
  currentEmailMasked: string | null
  currentPhoneMasked: string | null
  disabled: boolean
}>()

const streetLine = defineModel<string>('streetLine', { required: true })
const city = defineModel<string>('city', { required: true })
const postalCode = defineModel<string>('postalCode', { required: true })
const countryCode = defineModel<string>('countryCode', { required: true })
const email = defineModel<string>('email', { required: true })
const phone = defineModel<string>('phone', { required: true })

const { t } = useI18n()
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-100 disabled:text-neutral-500'
const labelClass = 'block text-xs font-medium text-neutral-600'
</script>

<template>
  <fieldset :disabled="disabled" class="space-y-4">
    <legend class="text-sm font-semibold text-neutral-900">
      {{ t('payroll.people.quick_edit.contact_title') }}
    </legend>
    <!--
      Celá sekce je nepovinná: server bydliště ani kontakty na zápisu profilu
      nevyžaduje (kolekce mají výchozí prázdný seznam). Proto tu není jediná
      hvězdička a proto to věta říká nahlas — dřív to vypadalo jako povinný
      blok, který drží uložení karty.
    -->
    <p class="-mt-3 text-xs text-neutral-500">
      {{ t('payroll.people.quick_edit.contact_optional_hint') }}
    </p>
    <p v-if="currentAddressMasked" class="-mt-1 text-xs text-neutral-500">
      {{ t('payroll.people.quick_edit.current_address') }}:
      {{ currentAddressMasked }}
    </p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
      <label :class="[labelClass, 'sm:col-span-2 lg:col-span-2']">
        {{ t('payroll.people.quick_edit.street_line') }}
        <input
          v-model="streetLine"
          autocomplete="street-address"
          :class="inputClass"
          :placeholder="t('payroll.people.quick_edit.keep_masked')"
          data-test="street-line"
        >
      </label>
      <label :class="[labelClass, 'lg:col-span-2']">
        {{ t('payroll.people.quick_edit.city') }}
        <input
          v-model="city"
          autocomplete="address-level2"
          :class="inputClass"
          data-test="city"
        >
      </label>
      <label :class="labelClass">
        {{ t('payroll.people.quick_edit.postal_code') }}
        <input
          v-model="postalCode"
          autocomplete="postal-code"
          :class="inputClass"
          data-test="postal-code"
        >
      </label>
      <label :class="labelClass">
        {{ t('payroll.people.quick_edit.country_code') }}
        <CountrySelect
          v-model="countryCode"
          class="mt-1"
          accent="payroll"
          data-test="country-code"
        />
      </label>
      <label :class="[labelClass, 'sm:col-span-1 lg:col-span-3']">
        {{ t('payroll.people.quick_edit.email') }}
        <input
          v-model="email"
          type="email"
          autocomplete="email"
          :placeholder="currentEmailMasked || t('payroll.people.quick_edit.not_set')"
          :class="inputClass"
          data-test="email"
        >
      </label>
      <label :class="[labelClass, 'sm:col-span-1 lg:col-span-3']">
        {{ t('payroll.people.quick_edit.phone') }}
        <input
          v-model="phone"
          type="tel"
          autocomplete="tel"
          :placeholder="currentPhoneMasked || t('payroll.people.quick_edit.not_set')"
          :class="inputClass"
          data-test="phone"
        >
      </label>
    </div>
    <p class="text-xs text-neutral-500">
      {{ t('payroll.people.quick_edit.contact_replace_hint') }}
    </p>
  </fieldset>
</template>
