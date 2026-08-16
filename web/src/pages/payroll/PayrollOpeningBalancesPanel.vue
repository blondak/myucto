<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { payrollApi, type PayrollOpeningMonth } from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'

/**
 * Počáteční stavy mezd pro zaměstnance převzatého z jiného zpracování.
 *
 * Uživatel opisuje úhrny PO MĚSÍCÍCH, protože tak je má v sestavě z předchozího
 * programu; roční kumulaci z nich složí server. Bez nich osoba vypadne z dávky
 * zákonného výpočtu a celý mzdový běh skončí v „ručním posouzení".
 */
const props = defineProps<{
  personId: number
  /** Období, od kterého firma vede mzdy v MyÚčtu (YYYY-MM). */
  startPeriod: string
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const locked = ref(false)
const error = ref('')
const sourceReference = ref('')

const year = computed(() => Number(props.startPeriod.slice(0, 4)))

/**
 * Počáteční stavy pokrývají měsíce PŘED prvním zpracovaným obdobím. Pro start
 * v srpnu jsou to leden až červenec; pro start v lednu není co doplňovat.
 */
const monthNumbers = computed(() => {
  const first = Number(props.startPeriod.slice(5, 7))
  return Array.from({ length: first - 1 }, (_, index) => index + 1)
})

const AMOUNT_FIELDS = [
  'social_assessment_base_minor_units',
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

/** Zadal uživatel vůbec něco? Prázdný formulář nemá smysl ukládat. */
const hasAnyAmount = computed(
  () => AMOUNT_FIELDS.some(field => totals.value[field] !== 0),
)

async function load() {
  loading.value = true
  try {
    const saved = await payrollApi.statutoryOpenings(props.personId, year.value)
    locked.value = saved.locked
    for (const month of monthNumbers.value) drafts.value[month] = emptyRow()
    for (const row of saved.months) {
      const draft = emptyRow()
      for (const field of AMOUNT_FIELDS) draft[field] = toInput(row[field])
      drafts.value[row.month] = draft
    }
    if (saved.months.length > 0) sourceReference.value = t('payroll.people.openings.source_default')
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
    toast.success(t('payroll.people.openings.saved'))
  } catch (exception) {
    // Hláška ze serveru jmenuje konkrétní důvod (zamčeno schválenou mzdou,
    // chybějící zdroj) — nesmí ji přebít obecný text.
    error.value = apiErrorMessage(exception, t('payroll.people.openings.save_failed'))
  } finally {
    saving.value = false
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

      <p
        v-else-if="monthNumbers.length === 0"
        class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
      >
        {{ t('payroll.people.openings.nothing_to_fill') }}
      </p>

      <template v-else>
        <p
          v-if="locked"
          class="mb-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
          data-test="openings-locked"
        >
          {{ t('payroll.people.openings.locked') }}
        </p>

        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead>
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
            :disabled="saving || !hasAnyAmount"
            data-test="openings-save"
            @click="save"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </template>
    </div>
  </details>
</template>
