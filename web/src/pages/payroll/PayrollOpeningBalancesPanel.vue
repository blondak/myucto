<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollOpeningImportPreview,
  type PayrollOpeningMonth,
} from '@/api/payroll'
import { readFileAsBase64 } from '@/components/payroll/imports/importHelpers'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'

/**
 * Počáteční stavy mezd pro zaměstnance převzatého z jiného zpracování.
 *
 * Uživatel opisuje úhrny PO MĚSÍCÍCH, protože tak je má v sestavě z předchozího
 * programu; roční kumulaci z nich složí server. Bez nich osoba vypadne z dávky
 * zákonného výpočtu a celý mzdový běh skončí v „ručním posouzení".
 */
const props = withDefaults(defineProps<{
  personId: number
  /** Rok a první zpracovávané období (YYYY-MM). */
  startPeriod: string
  canWrite: boolean
  /** Před startem existují měsíce zpracované v jiném systému. */
  includePriorMonths?: boolean
  /** První skutečně zpracovaný měsíc zaměstnance v daném roce (1–12). */
  firstIncludedMonth: number | null
}>(), {
  includePriorMonths: true,
})

/**
 * Jsou úhrny doplněné? Ptá se na to karta vztahu, aby nad hotovou věcí nevisela
 * výzva k jejímu doplnění — a aby se kvůli tomu stavy nenačítaly dvakrát.
 */
const emit = defineEmits<{ loaded: [filled: boolean] }>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const locked = ref(false)
/** Konkrétní důvod ze serveru — podané hlášení, roční doklad, uzavřený rok. */
const lockReason = ref<string | null>(null)
/** Měsíce, které se počítaly nad tímhle stavem; oprava je sama nepřepočítá. */
const approvedPeriods = ref<string[]>([])
const hasSavedOpening = ref(false)
const error = ref('')
const sourceReference = ref('')

const year = computed(() => Number(props.startPeriod.slice(0, 4)))

/**
 * Počáteční stavy pokrývají skutečně zpracované měsíce před aktivací. Nástup
 * v březnu a první běh v srpnu tedy znamená interval 3–7.
 */
const monthNumbers = computed(() => {
  if (!props.includePriorMonths) return []
  const processedMonth = Number(props.startPeriod.slice(5, 7))
  const first = props.firstIncludedMonth
  if (first === null || !Number.isInteger(first) || first < 1 || first >= processedMonth) {
    return []
  }
  return Array.from(
    { length: processedMonth - first },
    (_, index) => first + index,
  )
})

function hasCompleteOpenings(openings: Record<string, number | null> | undefined): boolean {
  return openings?.social_insurance != null
    && openings.health_insurance != null
    && openings.income_tax != null
}

const AMOUNT_FIELDS = [
  'social_assessment_base_minor_units',
  'health_assessment_base_minor_units',
  'health_employee_contribution_minor_units',
  'health_employer_contribution_minor_units',
  'health_minimum_top_up_minor_units',
  'advance_base_minor_units',
  'advance_tax_minor_units',
  'withholding_base_minor_units',
  'withholding_tax_minor_units',
  'applied_non_refundable_credits_minor_units',
  'applied_child_credit_minor_units',
  'tax_bonus_minor_units',
  'bonus_qualifying_income_minor_units',
] as const

type AmountField = typeof AMOUNT_FIELDS[number]

/**
 * Třináct sloupců bez rozdělení je nečitelná zeď. Skupina nad hlavičkou říká,
 * které sloupce patří k čemu — základ SP a základ ZP se jinak pletou.
 */
const FIELD_GROUPS: { key: string, fields: AmountField[] }[] = [
  { key: 'social', fields: ['social_assessment_base_minor_units'] },
  {
    key: 'health',
    fields: [
      'health_assessment_base_minor_units',
      'health_employee_contribution_minor_units',
      'health_employer_contribution_minor_units',
      'health_minimum_top_up_minor_units',
    ],
  },
  {
    key: 'tax',
    fields: [
      'advance_base_minor_units',
      'advance_tax_minor_units',
      'withholding_base_minor_units',
      'withholding_tax_minor_units',
      'applied_non_refundable_credits_minor_units',
      'applied_child_credit_minor_units',
      'tax_bonus_minor_units',
      'bonus_qualifying_income_minor_units',
    ],
  },
]

/** V UI se pracuje s korunami, kumulace je v haléřích. */
const drafts = ref<Record<number, Record<AmountField, string>>>({})

function emptyRow(): Record<AmountField, string> {
  return Object.fromEntries(AMOUNT_FIELDS.map(field => [field, ''])) as Record<AmountField, string>
}

function toMinor(value: string): number {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return 0
  const amount = Number(normalized)
  return Number.isFinite(amount) ? Math.round(amount * 100) : Number.NaN
}

function toInput(minor: number): string {
  return minor === 0 ? '' : String(minor / 100)
}

const totals = computed(() => {
  const sums = Object.fromEntries(AMOUNT_FIELDS.map(f => [f, 0])) as Record<AmountField, number>
  for (const month of monthNumbers.value) {
    for (const field of AMOUNT_FIELDS) {
      const minor = toMinor(drafts.value[month]?.[field] ?? '')
      if (Number.isFinite(minor)) sums[field] += minor
    }
  }
  return sums
})

async function load() {
  loading.value = true
  try {
    const saved = await payrollApi.statutoryOpenings(props.personId, year.value)
    locked.value = saved.locked
    lockReason.value = saved.lock_reason ?? null
    // Starší odpověď serveru pole nenese; bez výchozí hodnoty by na ní panel spadl.
    approvedPeriods.value = saved.approved_periods ?? []
    hasSavedOpening.value = hasCompleteOpenings(saved.openings)
    for (const month of monthNumbers.value) drafts.value[month] = emptyRow()
    for (const row of saved.months) {
      const draft = emptyRow()
      for (const field of AMOUNT_FIELDS) draft[field] = toInput(row[field])
      drafts.value[row.month] = draft
    }
    sourceReference.value = saved.source_reference
    emit('loaded', hasSavedOpening.value)
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.people.openings.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  if (saving.value) return
  error.value = ''

  const payload: PayrollOpeningMonth[] = []
  for (const month of monthNumbers.value) {
    const row = drafts.value[month] ?? emptyRow()
    const values = {} as Record<AmountField, number>
    for (const field of AMOUNT_FIELDS) {
      const minor = toMinor(row[field])
      if (!Number.isFinite(minor) || minor < 0) {
        error.value = t('payroll.people.openings.amount_invalid', { month })
        return
      }
      values[field] = minor
    }
    payload.push({ month, ...values })
  }

  saving.value = true
  try {
    const saved = await payrollApi.saveStatutoryOpenings(props.personId, {
      year: year.value,
      source_reference: sourceReference.value.trim(),
      months: payload,
    })
    locked.value = saved.locked
    lockReason.value = saved.lock_reason ?? null
    // Starší odpověď serveru pole nenese; bez výchozí hodnoty by na ní panel spadl.
    approvedPeriods.value = saved.approved_periods ?? []
    hasSavedOpening.value = hasCompleteOpenings(saved.openings)
    emit('loaded', hasSavedOpening.value)
    toast.success(t('payroll.people.openings.saved'))
  } catch (exception) {
    // Hláška ze serveru jmenuje konkrétní důvod (např. zamčeno schválenou
    // mzdou) — nesmí ji přebít obecný text.
    error.value = apiErrorMessage(exception, t('payroll.people.openings.save_failed'))
  } finally {
    saving.value = false
  }
}

/**
 * Tabulkový import za celou firmu. Soubor nese `employee_id`, takže jeden
 * převod vyřídí všechny převzaté zaměstnance naráz — mřížka výš je pro
 * doplnění jednotlivce, ne pro převod stovky lidí po devíti polích.
 */
const importFileName = ref('')
const importFormat = ref<'csv' | 'xlsx'>('csv')
const importContent = ref('')
const importBusy = ref(false)
const importError = ref('')
const importPreview = ref<PayrollOpeningImportPreview | null>(null)
const importSummary = ref('')

async function pickImportFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  importPreview.value = null
  importSummary.value = ''
  importError.value = ''
  importContent.value = ''
  importFileName.value = ''
  if (!file) return
  const extension = file.name.split('.').pop()?.toLowerCase()
  if (extension !== 'csv' && extension !== 'xlsx') {
    importError.value = t('payroll.people.openings.import.format_invalid')
    return
  }
  importFormat.value = extension
  importFileName.value = file.name
  importContent.value = await readFileAsBase64(file)
}

function importPayload() {
  return {
    format: importFormat.value,
    source_name: importFileName.value,
    content_base64: importContent.value,
  }
}

async function runImportPreview() {
  if (importBusy.value || !importContent.value) return
  importBusy.value = true
  importError.value = ''
  importSummary.value = ''
  try {
    importPreview.value = await payrollApi.previewStatutoryOpeningImport(importPayload())
  } catch (exception) {
    importPreview.value = null
    importError.value = apiErrorMessage(exception, t('payroll.people.openings.import.preview_failed'))
  } finally {
    importBusy.value = false
  }
}

async function runImportApply() {
  if (importBusy.value || !importContent.value) return
  importBusy.value = true
  importError.value = ''
  try {
    const result = await payrollApi.applyStatutoryOpeningImport(importPayload())
    importSummary.value = t('payroll.people.openings.import.applied', {
      saved: result.saved,
      unchanged: result.unchanged,
      skipped: result.skipped.length,
    })
    importPreview.value = null
    await load()
  } catch (exception) {
    importError.value = apiErrorMessage(exception, t('payroll.people.openings.import.apply_failed'))
  } finally {
    importBusy.value = false
  }
}

async function downloadTemplate() {
  try {
    const blob = await payrollApi.statutoryOpeningImportTemplate()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'pocatecni-stavy-vzor.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (exception) {
    importError.value = apiErrorMessage(exception, t('payroll.people.openings.import.template_failed'))
  }
}

onMounted(load)
</script>

<template>
  <details class="group rounded-lg border border-payroll-500/30 bg-surface" data-test="opening-balances">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.openings.panel_title', { year }) }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.openings.panel_hint') }}
        </span>
      </span>
    </summary>

    <div class="border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

      <template v-else>
        <p class="mb-3 rounded-md bg-payroll-50 px-3 py-2 text-xs text-payroll-700">
          {{ t(includePriorMonths
            ? 'payroll.people.openings.zero_hint'
            : 'payroll.people.openings.new_hire_zero_hint') }}
        </p>
        <p
          v-if="locked"
          class="mb-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
          data-test="openings-locked"
        >
          {{ lockReason ?? t('payroll.people.openings.locked') }}
        </p>
        <p
          v-else-if="approvedPeriods.length > 0"
          class="mb-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
          data-test="openings-approved-periods"
        >
          {{ t('payroll.people.openings.approved_warning', { periods: approvedPeriods.join(', ') }) }}
        </p>

        <p
          v-if="monthNumbers.length === 0"
          class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
        >
          {{ t('payroll.people.openings.nothing_to_fill') }}
        </p>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead>
              <tr class="text-left text-neutral-500">
                <th class="py-1 pr-3" />
                <th
                  v-for="group in FIELD_GROUPS"
                  :key="group.key"
                  :colspan="group.fields.length"
                  class="border-b border-neutral-200 px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-600"
                >{{ t(`payroll.people.openings.group.${group.key}`) }}</th>
              </tr>
              <tr class="text-left text-neutral-500">
                <th class="py-1 pr-3 font-medium">{{ t('payroll.people.openings.month') }}</th>
                <th v-for="field in AMOUNT_FIELDS" :key="field" class="px-2 py-1 font-medium">
                  {{ t(`payroll.people.openings.field.${field}`) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="month in monthNumbers" :key="month" class="border-t border-neutral-100">
                <th class="py-1 pr-3 text-left font-normal text-neutral-700">{{ month }}</th>
                <td v-for="field in AMOUNT_FIELDS" :key="field" class="px-1 py-1">
                  <input
                    v-model="drafts[month]![field]"
                    inputmode="decimal"
                    :disabled="!canWrite || locked || saving"
                    :data-test="`opening-${month}-${field}`"
                    class="w-24 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100"
                  >
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t border-neutral-300 font-medium text-neutral-800">
                <th class="py-1 pr-3 text-left">{{ t('payroll.people.openings.total') }}</th>
                <td
                  v-for="field in AMOUNT_FIELDS"
                  :key="field"
                  class="px-2 py-1 text-right tabular-nums"
                  :data-test="`opening-total-${field}`"
                >{{ (totals[field] / 100).toLocaleString('cs-CZ') }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <label class="mt-3 block text-xs text-neutral-600">
          {{ t('payroll.people.openings.source') }}
          <input
            v-model="sourceReference"
            :disabled="!canWrite || locked || saving"
            :placeholder="t('payroll.people.openings.source_placeholder')"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100"
            data-test="openings-source"
          >
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.openings.source_hint') }}</span>
        </label>

        <p
          v-if="error"
          class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
          role="alert"
          data-test="openings-error"
        >{{ error }}</p>

        <div v-if="canWrite && !locked" class="mt-3 flex justify-end gap-2">
          <button
            type="button"
            :class="btnOutline('neutral')"
            :disabled="saving"
            @click="load"
          >{{ t('common.cancel') }}</button>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="saving"
            data-test="openings-save"
            @click="save"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>

        <div v-if="canWrite" class="mt-4 rounded-md border border-neutral-200 p-3" data-test="openings-import">
          <p class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.openings.import.title') }}
          </p>
          <p class="mt-1 text-xs text-neutral-600">
            {{ t('payroll.people.openings.import.hint') }}
          </p>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <button
              type="button"
              :class="btnOutline('neutral')"
              class="whitespace-nowrap"
              data-test="openings-import-template"
              @click="downloadTemplate"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('payroll.people.openings.import.template') }}
            </button>
            <input
              type="file"
              accept=".csv,.xlsx"
              :disabled="importBusy"
              data-test="openings-import-file"
              class="max-w-full text-xs text-neutral-700 file:mr-2 file:rounded-md file:border file:border-neutral-300 file:bg-surface file:px-2 file:py-1 file:text-xs"
              @change="pickImportFile"
            >
            <button
              type="button"
              :class="btnOutline('primary')"
              class="whitespace-nowrap"
              :disabled="importBusy || !importContent"
              data-test="openings-import-preview"
              @click="runImportPreview"
            >{{ t('payroll.people.openings.import.preview') }}</button>
            <button
              type="button"
              :class="btnFilled('primary')"
              class="whitespace-nowrap"
              :disabled="importBusy || !importContent"
              data-test="openings-import-apply"
              @click="runImportApply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ t('payroll.people.openings.import.apply') }}
            </button>
          </div>

          <p
            v-if="importError"
            class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
            role="alert"
            data-test="openings-import-error"
          >{{ importError }}</p>

          <p
            v-if="importSummary"
            class="mt-3 rounded-md bg-success-50 px-3 py-2 text-xs text-success-800"
            data-test="openings-import-summary"
          >{{ importSummary }}</p>

          <div v-if="importPreview" class="mt-3 space-y-2" data-test="openings-import-preview-result">
            <p class="text-xs text-neutral-600">
              {{ t('payroll.people.openings.import.rows', { count: importPreview.row_count }) }}
            </p>
            <ul
              v-if="importPreview.errors.length > 0"
              class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
            >
              <li v-for="row in importPreview.errors" :key="`${row.row_number}-${row.error_code}`">
                {{ t('payroll.people.openings.import.error_row', { row: row.row_number }) }}
                {{ row.error_message }}
              </li>
            </ul>
            <ul v-if="importPreview.people.length > 0" class="space-y-1 text-xs">
              <li
                v-for="person in importPreview.people"
                :key="`${person.employee_id}-${person.year}`"
                class="flex flex-wrap items-baseline gap-2 border-t border-neutral-100 pt-1"
              >
                <span class="font-medium text-neutral-800">{{ person.employee_name }}</span>
                <span class="text-neutral-500">{{ person.year }}</span>
                <span class="text-neutral-500">
                  {{ t('payroll.people.openings.import.months', { count: person.months.length }) }}
                </span>
                <span class="text-neutral-700">
                  {{ t(`payroll.people.openings.import.status.${person.status}`) }}
                </span>
                <span v-if="person.reason" class="text-neutral-500">{{ person.reason }}</span>
              </li>
            </ul>
          </div>
        </div>
      </template>
    </div>
  </details>
</template>
