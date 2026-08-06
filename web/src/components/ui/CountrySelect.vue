<script setup lang="ts">
import { computed, onMounted, ref, useAttrs } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Country } from '@/api/codebooks'
import { loadCountries } from '@/composables/useCountries'
import SearchableSelect from './SearchableSelect.vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  modelValue: string
  disabled?: boolean
  clearable?: boolean
  required?: boolean
  accent?: 'primary' | 'payroll'
  /**
   * Nabízet jen členské státy EU. Pro režimy, které mimo Unii nedávají smysl
   * (OSS — daň se přiznává ve státě spotřeby, a ten je vždy členský stát).
   * Už uložený neunijní kód se v nabídce ponechá, ať ho uživatel vidí a může
   * ho opravit — jinak by pole vypadalo prázdné, přestože v datech hodnota je.
   */
  euOnly?: boolean
}>(), {
  disabled: false,
  clearable: true,
  required: false,
  accent: 'primary',
  euOnly: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const attrs = useAttrs()
const { locale, t } = useI18n()
const countries = ref<Country[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const selection = computed<string | null>({
  get: () => props.modelValue || null,
  set: value => emit('update:modelValue', value ?? ''),
})
const fallbackSelection = computed({
  get: () => props.modelValue,
  set: (value: string) =>
    emit('update:modelValue', value.trim().toUpperCase().slice(0, 2)),
})
const visibleCountries = computed(() => props.euOnly
  ? countries.value.filter(country => country.is_eu || country.iso2 === props.modelValue)
  : countries.value)
const options = computed(() => visibleCountries.value.map(country => ({
  value: country.iso2,
  label: locale.value === 'en' ? country.name_en : country.name_cs,
  secondary: `${country.iso2} · ${country.iso3}`,
})))

onMounted(async () => {
  loading.value = true
  loadFailed.value = false
  try {
    countries.value = await loadCountries()
  } catch {
    countries.value = []
    loadFailed.value = true
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <SearchableSelect
      v-if="!loadFailed"
      v-bind="attrs"
      v-model="selection"
      :options="options"
      :placeholder="t('common.country_placeholder')"
      :no-results-label="t('common.no_results')"
      :clearable="clearable && !required"
      :disabled="disabled || loading"
      :required="required"
      :accent="accent"
      :aria-label="t('common.country')"
    />
    <template v-else>
      <input
        v-bind="attrs"
        v-model="fallbackSelection"
        type="text"
        maxlength="2"
        autocomplete="country"
        class="h-10 w-full rounded-md border border-warning-400 bg-surface px-3 text-sm uppercase text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
        :disabled="disabled"
        :required="required"
        :aria-label="t('common.country')"
      >
      <p class="mt-1 text-xs text-warning-700" role="alert">
        {{ t('common.country_load_failed') }}
        {{ t('common.country_manual_fallback') }}
      </p>
    </template>
  </div>
</template>
