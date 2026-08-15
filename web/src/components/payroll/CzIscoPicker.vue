<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { payrollApi, type PayrollCzIscoOption } from '@/api/payroll'

/**
 * Našeptávač kódu CZ-ISCO (klasifikace zaměstnání ČSÚ).
 *
 * Hledá **na serveru** přes `GET /api/payroll/cz-isco` — číselník má skoro dva
 * tisíce položek a do bundlu nepatří. Hledá se podle kódu i podle názvu a
 * diakritika nevadí („ucetni" najde „Účetní všeobecní").
 *
 * Prázdný výsledek a selhání dotazu jsou **rozlišené**. Když endpoint spadne,
 * nesmí to vypadat jako „nic nenalezeno" — pole v takovém případě navíc nabídne
 * ruční zápis kódu, aby uživatele výpadek číselníku nezablokoval.
 */

type Option = { value: string; label: string; secondary?: string }

const MIN_QUERY_LENGTH = 2

const props = withDefaults(defineProps<{
  modelValue: string | null
  disabled?: boolean
  required?: boolean
  limit?: number
}>(), {
  disabled: false,
  required: false,
  limit: 20,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const { t } = useI18n()

const options = ref<Option[]>([])
const selectedOption = ref<Option | null>(null)
const loading = ref(false)
/** Poslední dotaz selhal (síť / 5xx). NENÍ totéž co prázdný výsledek. */
const failed = ref(false)
/** Uživatel zatím napsal míň než MIN_QUERY_LENGTH znaků — taky není prázdný výsledek. */
const tooShort = ref(false)
const manualCode = ref('')

// Odpovědi můžou dorazit v jiném pořadí, než odešly (uživatel píše dál).
// Bez tokenu by pomalejší starší odpověď přepsala novější výsledek.
let requestToken = 0

function toOption(item: PayrollCzIscoOption): Option {
  return {
    value: item.code,
    label: `${item.code} — ${item.label}`,
    secondary: item.parent_label ?? undefined,
  }
}

async function onSearch(rawQuery: string): Promise<void> {
  const query = rawQuery.trim()
  const token = ++requestToken

  if (query.length < MIN_QUERY_LENGTH) {
    options.value = []
    tooShort.value = true
    failed.value = false
    loading.value = false
    return
  }

  tooShort.value = false
  failed.value = false
  loading.value = true
  try {
    const result = await payrollApi.searchCzIsco(query, props.limit)
    if (token !== requestToken) return
    options.value = result.items.map(toOption)
  } catch {
    if (token !== requestToken) return
    options.value = []
    failed.value = true
  } finally {
    if (token === requestToken) loading.value = false
  }
}

/** Doplní popisek k už uloženému kódu, aby v poli nesvítilo jen holé číslo. */
async function resolveLabel(code: string | null): Promise<void> {
  if (code === null || code === '') {
    selectedOption.value = null
    manualCode.value = ''
    return
  }
  manualCode.value = code
  selectedOption.value = { value: code, label: code }
  if (code.length < MIN_QUERY_LENGTH) return
  try {
    const result = await payrollApi.searchCzIsco(code, 5)
    const hit = result.items.find(item => item.code === code)
    selectedOption.value = hit
      ? toOption(hit)
      // Kód, který v aktuální klasifikaci není. Uložení kvůli němu nepadne
      // (server historickou hodnotu toleruje), ale uživatel to má vidět.
      : { value: code, label: code, secondary: t('payroll.people.cz_isco.unknown_code') }
  } catch {
    // Selhání dotazu nesmí uživateli sebrat hodnotu, kterou má uloženou.
    selectedOption.value = { value: code, label: code }
  }
}

function onChange(value: string | number | null): void {
  const code = value === null || value === '' ? null : String(value)
  selectedOption.value = code === null
    ? null
    : options.value.find(option => option.value === code) ?? { value: code, label: code }
  manualCode.value = code ?? ''
  emit('update:modelValue', code)
}

function commitManualCode(): void {
  const code = manualCode.value.trim()
  emit('update:modelValue', code === '' ? null : code)
  selectedOption.value = code === '' ? null : { value: code, label: code }
}

function retry(): void {
  failed.value = false
  options.value = []
}

/**
 * Text v prázdné nabídce. Tři různé stavy, tři různé věty — „žádné výsledky"
 * u spadlého dotazu je přesně ta lež, na kterou projekt už dvakrát doplatil.
 */
const emptyStateLabel = computed(() => {
  if (failed.value) return t('payroll.people.cz_isco.search_failed')
  if (tooShort.value) return t('payroll.people.cz_isco.min_chars')
  return t('payroll.people.cz_isco.no_results')
})

onMounted(() => { void resolveLabel(props.modelValue) })
watch(() => props.modelValue, value => {
  if (value !== (selectedOption.value?.value ?? null)) void resolveLabel(value)
})
</script>

<template>
  <div>
    <SearchableSelect
      :model-value="modelValue"
      remote
      accent="payroll"
      :options="options"
      :selected-option="selectedOption"
      :loading="loading"
      :loading-label="t('payroll.people.cz_isco.searching')"
      :no-results-label="emptyStateLabel"
      :placeholder="t('payroll.people.cz_isco.placeholder')"
      :aria-label="t('payroll.people.cz_isco_code')"
      :disabled="disabled"
      :required="required"
      :invalid="failed"
      :clearable="!required"
      @search="onSearch"
      @update:model-value="onChange"
    />
    <p
      v-if="!failed"
      class="mt-1 text-xs text-neutral-500"
    >
      {{ t('payroll.people.cz_isco.hint') }}
    </p>
    <div
      v-else
      class="mt-1 rounded-md border border-warning-400 bg-warning-50 px-3 py-2"
      role="alert"
      data-testid="cz-isco-search-failed"
    >
      <p class="text-xs text-warning-700">
        {{ t('payroll.people.cz_isco.search_failed') }}
        {{ t('payroll.people.cz_isco.search_failed_hint') }}
      </p>
      <div class="mt-2 flex items-center gap-2">
        <input
          v-model="manualCode"
          type="text"
          inputmode="numeric"
          maxlength="16"
          :disabled="disabled"
          :aria-label="t('payroll.people.cz_isco.manual_label')"
          :placeholder="t('payroll.people.cz_isco.manual_placeholder')"
          class="h-9 w-32 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          @change="commitManualCode"
          @keydown.enter.prevent="commitManualCode"
        >
        <button
          type="button"
          class="cursor-pointer rounded-md border border-neutral-300 px-3 py-1.5 text-xs hover:bg-neutral-50"
          @click="retry"
        >
          {{ t('payroll.people.cz_isco.retry') }}
        </button>
      </div>
    </div>
  </div>
</template>
