<script setup lang="ts">
/*
 * PAM-09 — převzaté mzdy roku přechodu: co za rok je a nahrání zbytku.
 *
 * Panel stál dřív nahoře nad kontrolní sestavou. Patří ale k pořízení dat, ne
 * ke kontrole výsledku: nad týmiž řádky stojí evidenční list důchodového
 * pojištění i zpětná evidence plateb, takže „chybí měsíc" je tu stejně důležité
 * jako samotná čísla — a kontrolní sestava o díře v roce mlčí, protože nemá co
 * s čím porovnat.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollTakeoverWagesApi,
  type PayrollMigrationSource,
  type PayrollTakeoverImportPreview,
  type PayrollTakeoverOverview,
  type PayrollTakeoverPresence,
} from '@/api/payrollMigrationReconciliation'
import { apiErrorMessage } from '@/api/errors'
import { readFileAsBase64 } from './importHelpers'
import { useMigrationWorkspace } from './migrationWorkspace'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import MigrationYearFilter from './MigrationYearFilter.vue'

const { t } = useI18n()
const auth = useAuthStore()
const workspace = useMigrationWorkspace()
const canRead = computed(() => auth.canRead('payroll.reports'))
const canImport = computed(() => auth.canWrite('payroll.employment.write'))

const TAKEOVER_SOURCES: PayrollMigrationSource[] = ['other', 'pamica', 'pohoda', 'money_s3']

const takeover = ref<PayrollTakeoverOverview | null>(null)
const takeoverLoading = ref(false)
const importSource = ref<PayrollMigrationSource>('other')
const importFile = ref<File | null>(null)
const importPreview = ref<PayrollTakeoverImportPreview | null>(null)
const importResult = ref<string>('')
const importError = ref('')
const importBusy = ref(false)

const importFormat = computed<'csv' | 'xlsx' | null>(() => {
  const name = importFile.value?.name.toLowerCase() ?? ''
  if (name.endsWith('.csv')) return 'csv'
  if (name.endsWith('.xlsx')) return 'xlsx'
  return null
})

const canApplyImport = computed(() =>
  importPreview.value !== null
  && importPreview.value.errors.length === 0
  && importPreview.value.people.length > 0,
)

function money(minor: number | null): string {
  return minor === null ? '—' : formatMoneyMinor(minor)
}

function presenceClass(presence: PayrollTakeoverPresence): string {
  return {
    takeover_only: 'bg-payroll-50 text-payroll-800',
    calculated_only: 'bg-success-50 text-success-700',
    both: 'bg-warning-50 text-warning-700',
    none: 'bg-neutral-100 text-neutral-500',
  }[presence]
}

async function loadTakeover(): Promise<void> {
  if (!canRead.value) return
  takeoverLoading.value = true
  try {
    takeover.value = await payrollTakeoverWagesApi.overview(
      workspace.year.value,
      workspace.source.value || null,
    )
  } catch {
    // Přehled je doplněk nahrávání; jeho selhání nesmí zavřít formulář importu.
    takeover.value = null
  } finally {
    takeoverLoading.value = false
  }
}

function onImportFile(event: Event): void {
  const input = event.target as HTMLInputElement
  importFile.value = input.files?.[0] ?? null
  importPreview.value = null
  importResult.value = ''
  importError.value = ''
}

async function importPayload(): Promise<{
  source: PayrollMigrationSource
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
} | null> {
  const file = importFile.value
  const format = importFormat.value
  if (!file || format === null) {
    importError.value = t('payroll.migration_reconciliation.takeover.format_unsupported')
    return null
  }
  return {
    source: importSource.value,
    format,
    source_name: file.name,
    content_base64: await readFileAsBase64(file),
  }
}

async function runImportPreview(): Promise<void> {
  if (importBusy.value) return
  importBusy.value = true
  importError.value = ''
  importResult.value = ''
  try {
    const payload = await importPayload()
    if (payload === null) return
    importPreview.value = await payrollTakeoverWagesApi.importPreview(payload)
  } catch (error) {
    importPreview.value = null
    importError.value = apiErrorMessage(error, t('payroll.migration_reconciliation.takeover.preview_failed'))
  } finally {
    importBusy.value = false
  }
}

async function runImportApply(): Promise<void> {
  if (importBusy.value || !canApplyImport.value) return
  importBusy.value = true
  importError.value = ''
  try {
    const payload = await importPayload()
    if (payload === null) return
    const result = await payrollTakeoverWagesApi.importApply(payload)
    importResult.value = t('payroll.migration_reconciliation.takeover.apply_done', {
      rows: result.written,
      people: result.employee_count,
      months: result.periods.length,
    })
    importPreview.value = null
    importFile.value = null
    await loadTakeover()
    // Kontrola i Kontace čtou tatáž data; po nahrání se musí přepočítat, i když
    // je uživatel zrovna nemá otevřené.
    workspace.markImported()
  } catch (error) {
    importError.value = apiErrorMessage(error, t('payroll.migration_reconciliation.takeover.apply_failed'))
  } finally {
    importBusy.value = false
  }
}

watch([workspace.year, workspace.source], () => { void loadTakeover() })
onMounted(() => { void loadTakeover() })
</script>

<template>
  <div v-if="canRead" class="space-y-6" data-test="payroll-takeover-panel">
    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.migration_reconciliation.takeover.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">
            {{ t('payroll.migration_reconciliation.takeover.subtitle') }}
          </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <MigrationYearFilter />
          <a
            :href="payrollTakeoverWagesApi.importTemplateUrl"
            :class="[btnOutline('neutral'), 'whitespace-nowrap']"
            download
            data-test="takeover-template"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.download" />
            </svg>
            {{ t('payroll.migration_reconciliation.takeover.template') }}
          </a>
        </div>
      </div>

      <div v-if="takeoverLoading" class="mt-4 h-20 animate-pulse rounded-lg bg-neutral-100" />
      <template v-else-if="takeover">
        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
          <div
            v-for="item in takeover.periods"
            :key="`takeover-${item.period}`"
            class="rounded-lg border border-neutral-200 p-2"
            :data-test="`takeover-month-${item.period}`"
          >
            <p class="flex flex-wrap items-center gap-1 text-xs font-medium text-neutral-700">
              {{ formatPeriod(item.period) }}
              <span
                v-if="item.historical"
                class="rounded bg-neutral-100 px-1 py-0.5 text-[10px] text-neutral-500"
              >{{ t('payroll.migration_reconciliation.takeover.historical') }}</span>
            </p>
            <p class="mt-1">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="presenceClass(item.presence)">
                {{ t(`payroll.migration_reconciliation.takeover.presence.${item.presence}`) }}
              </span>
            </p>
            <p v-if="item.takeover_employee_count > 0" class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.migration_reconciliation.takeover.people_count', { count: item.takeover_employee_count }) }}
              ·
              {{ item.sources.map(code => t(`payroll.migration_reconciliation.source_name.${code}`)).join(', ') }}
            </p>
          </div>
        </div>
        <p v-if="takeover.missing_periods.length > 0" class="mt-3 text-sm text-warning-700">
          {{ t('payroll.migration_reconciliation.takeover.missing', {
            periods: takeover.missing_periods.map(formatPeriod).join(', '),
          }) }}
        </p>
        <p v-if="takeover.overlapping_periods.length > 0" class="mt-1 text-sm text-neutral-600">
          {{ t('payroll.migration_reconciliation.takeover.overlapping', {
            periods: takeover.overlapping_periods.map(formatPeriod).join(', '),
          }) }}
        </p>
      </template>

      <div v-if="canImport" class="mt-5 border-t border-neutral-200 pt-4">
        <h3 class="font-semibold text-neutral-900">
          {{ t('payroll.migration_reconciliation.takeover.upload_title') }}
        </h3>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.migration_reconciliation.takeover.upload_hint') }}
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.import_source') }}
            </span>
            <select
              v-model="importSource"
              class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              data-test="takeover-import-source"
            >
              <option v-for="item in TAKEOVER_SOURCES" :key="`src-${item}`" :value="item">
                {{ t(`payroll.migration_reconciliation.source_name.${item}`) }}
              </option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.file') }}
            </span>
            <input
              type="file"
              accept=".csv,.xlsx"
              class="h-9 text-sm text-neutral-700"
              data-test="takeover-import-file"
              @change="onImportFile"
            >
          </label>
          <button
            type="button"
            :class="[btnOutline('primary'), 'whitespace-nowrap']"
            :disabled="importBusy || !importFile"
            data-test="takeover-import-preview"
            @click="runImportPreview"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.eye" />
            </svg>
            {{ t('payroll.migration_reconciliation.takeover.preview') }}
          </button>
          <button
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="importBusy || !canApplyImport"
            data-test="takeover-import-apply"
            @click="runImportApply"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.upload" />
            </svg>
            {{ t('payroll.migration_reconciliation.takeover.apply') }}
          </button>
        </div>

        <p
          v-if="importError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="takeover-import-error"
        >{{ importError }}</p>
        <p v-if="importResult" class="mt-3 text-sm text-success-700" data-test="takeover-import-result">
          {{ importResult }}
        </p>

        <template v-if="importPreview">
          <p
            v-if="importPreview.errors.length > 0"
            class="mt-3 text-sm text-danger-700"
            data-test="takeover-import-invalid"
          >
            {{ t('payroll.migration_reconciliation.takeover.errors_block', {
              count: importPreview.errors.length,
            }) }}
          </p>
          <ul v-if="importPreview.errors.length > 0" class="mt-2 space-y-1 text-sm text-danger-700">
            <li v-for="item in importPreview.errors.slice(0, 20)" :key="`err-${item.row_number}-${item.error_code}`">
              {{ t('payroll.migration_reconciliation.takeover.error_row', { row: item.row_number }) }}
              {{ item.error_message }}
            </li>
          </ul>
          <div v-else class="mt-3 overflow-x-auto">
            <p class="text-sm text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.preview_summary', {
                rows: importPreview.row_count,
                people: importPreview.people.length,
                months: importPreview.periods.length,
              }) }}
            </p>
            <table class="mt-2 min-w-full text-sm">
              <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                <tr>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.person') }}</th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.takeover.months') }}
                  </th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.metric.gross') }}
                  </th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.takeover.net_payable') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="person in importPreview.people"
                  :key="`prev-${person.employee_id}`"
                  class="border-b border-neutral-100"
                >
                  <td class="px-2 py-2 text-neutral-700">{{ person.employee_name }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ person.month_count }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(person.gross_minor) }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(person.net_payable_minor) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </section>
  </div>
</template>
