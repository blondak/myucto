<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollImportsApi,
  type PohodaOicApplyResult,
  type PohodaOicPreview,
  type PohodaOicRow,
  type RegistrationEnvironment,
} from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { personalNumberLabel } from '@/pages/payroll/employmentLifecycleUi'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import { filesFingerprint, filesToPayload } from './importHelpers'
import {
  oicApplyBlock,
  oicResultClass,
  oicStatusClass,
  pruneOicSelection,
  selectableOicKeys,
  visibleOicRows,
} from './pohodaOicHelpers'

const props = defineProps<{
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()

const SUMMARY_ITEMS = ['total', 'ready', 'already_stored', 'conflict', 'blocked'] as const

const environment = ref<RegistrationEnvironment>('production')
const files = ref<File[]>([])
const preview = ref<PohodaOicPreview | null>(null)
const previewFingerprint = ref('')
const selected = ref<string[]>([])
const evidenceConfirmed = ref(false)
const result = ref<PohodaOicApplyResult | null>(null)
const busy = ref<'preview' | 'apply' | null>(null)
const error = ref('')

const fingerprint = computed(() => `${environment.value}#${filesFingerprint(files.value)}`)
const rows = computed<PohodaOicRow[]>(() => visibleOicRows(preview.value?.rows ?? []))
const selectableKeys = computed(() => selectableOicKeys(rows.value))
const allSelectableSelected = computed(() =>
  selectableKeys.value.length > 0 && selectableKeys.value.every(key => selected.value.includes(key)))

const applyBlock = computed(() => oicApplyBlock({
  hasPreview: preview.value !== null,
  selectedCount: selected.value.length,
  confirmed: evidenceConfirmed.value,
}))
const applyBlockedReason = computed<string>(() =>
  applyBlock.value === null ? '' : t(`payroll_imports.pohoda_oic.reason.${applyBlock.value}`))
const canApply = computed(() => props.canWrite && busy.value === null && applyBlock.value === null)

// Jiné soubory nebo prostředí = jiný náhled; výběr z toho starého by klamal.
watch(fingerprint, value => {
  if (preview.value && value !== previewFingerprint.value) {
    preview.value = null
    selected.value = []
    evidenceConfirmed.value = false
  }
  result.value = null
})

async function runPreview(options: { keepResult?: boolean } = {}) {
  if (files.value.length === 0) return
  error.value = ''
  if (!options.keepResult) result.value = null
  busy.value = 'preview'
  try {
    const current = fingerprint.value
    const response = await payrollImportsApi.previewPohodaOic({
      environment: environment.value,
      files: await filesToPayload(files.value),
    })
    preview.value = response
    previewFingerprint.value = current
    selected.value = options.keepResult
      ? pruneOicSelection(selected.value, response.rows)
      : selectableOicKeys(response.rows)
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.pohoda_oic.preview_failed'))
  } finally {
    busy.value = null
  }
}

async function runApply() {
  if (!canApply.value) return
  error.value = ''
  busy.value = 'apply'
  try {
    const response = await payrollImportsApi.applyPohodaOic({
      environment: environment.value,
      files: await filesToPayload(files.value),
      keys: [...selected.value],
      evidence_confirmed: evidenceConfirmed.value,
    })
    result.value = response
    if (response.summary.failed > 0) toast.warning(t('payroll_imports.pohoda_oic.applied_with_errors', response.summary))
    else toast.success(t('payroll_imports.pohoda_oic.applied', response.summary))
    evidenceConfirmed.value = false
    selected.value = []
    busy.value = null
    // Nový náhled ukáže stav po zápisu (zapsaná OIČ vyjdou jako uložená).
    await runPreview({ keepResult: true })
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.pohoda_oic.apply_failed'))
  } finally {
    busy.value = null
  }
}

function toggle(key: string) {
  selected.value = selected.value.includes(key)
    ? selected.value.filter(item => item !== key)
    : [...selected.value, key]
}

function toggleAll() {
  selected.value = allSelectableSelected.value ? [] : [...selectableKeys.value]
}

function employmentText(row: PohodaOicRow): string {
  const code = personalNumberLabel(t, row.employment_code)
  return code ? t('payroll_imports.pohoda_oic.employment', { code }) : ''
}
</script>

<template>
  <section class="space-y-4" data-testid="pohoda-oic-import">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.pohoda_oic.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.pohoda_oic.hint') }}</p>
    </div>

    <p v-if="!canWrite" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.pohoda_oic.no_permission') }}
    </p>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <fieldset class="mb-4">
        <legend class="mb-2 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.environment') }}</legend>
        <div class="flex flex-wrap gap-4">
          <label v-for="env in (['production', 'test'] as const)" :key="env" class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="environment" type="radio" :value="env" class="border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
            {{ t(`payroll_imports.registration.environments.${env}`) }}
          </label>
        </div>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll_imports.pohoda_oic.environment_hint') }}</p>
      </fieldset>

      <ImportFilesDropzone
        v-model:files="files"
        test-id="pohoda-oic-dropzone"
        accept=".xlsx,.csv"
        :allowed-extensions="['xlsx', 'csv']"
        :drop-hint="t('payroll_imports.pohoda_oic.drop_hint')"
        :disabled="!canWrite || busy !== null"
      />

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          data-testid="pohoda-oic-preview"
          :class="btnOutline('primary')"
          class="whitespace-nowrap"
          :disabled="!canWrite || busy !== null || files.length === 0"
          :title="disabledTitle(files.length === 0, t('payroll_imports.pohoda_oic.reason.no_files'))"
          @click="runPreview()"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t(preview ? 'payroll_imports.pohoda_oic.refresh_preview' : 'payroll_imports.pohoda_oic.preview') }}
        </button>
      </div>

      <p v-if="error" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>
    </section>

    <template v-if="preview">
      <section class="space-y-3">
        <div v-if="preview.files.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface">
          <ul class="divide-y divide-neutral-100">
            <li v-for="(file, index) in preview.files" :key="`${file.sha256}-${index}`" class="px-3 py-2 text-sm">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="min-w-0 truncate font-medium text-neutral-800" :title="file.name">{{ file.name }}</span>
                <span class="flex flex-wrap items-center gap-2 text-xs">
                  <span v-if="file.sheet" class="text-neutral-500">{{ file.sheet }}</span>
                  <span v-if="file.company_ico" class="whitespace-nowrap rounded-full bg-neutral-100 px-2 py-0.5 text-neutral-700">{{ t('payroll_imports.pohoda_oic.company_ico', { ico: file.company_ico }) }}</span>
                  <span class="text-neutral-500">{{ t('payroll_imports.pohoda_oic.row_count', { count: file.row_count }) }}</span>
                  <span v-if="file.error" class="rounded-full bg-danger-50 px-2 py-0.5 text-danger-600">{{ file.error }}</span>
                </span>
              </div>
              <ul v-if="file.warnings.length" class="mt-1 space-y-0.5 text-xs text-warning-700">
                <li v-for="warning in file.warnings" :key="warning">{{ warning }}</li>
              </ul>
            </li>
          </ul>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <article v-for="item in SUMMARY_ITEMS" :key="item" class="rounded-lg p-3"
            :class="item === 'blocked' && preview.summary.blocked > 0 ? 'bg-danger-50' : item === 'conflict' && preview.summary.conflict > 0 ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs text-neutral-500">{{ t(`payroll_imports.pohoda_oic.summary.${item}`) }}</p>
            <p class="mt-1 text-lg font-semibold"
              :class="item === 'blocked' && preview.summary.blocked > 0 ? 'text-danger-600' : item === 'conflict' && preview.summary.conflict > 0 ? 'text-warning-700' : 'text-neutral-900'">{{ preview.summary[item] }}</p>
          </article>
        </div>
        <p v-if="preview.summary.without_oic > 0" class="text-xs text-neutral-500">
          {{ t('payroll_imports.pohoda_oic.without_oic', { count: preview.summary.without_oic }) }}
        </p>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.pohoda_oic.rows_title') }}</h3>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-neutral-500">{{ t('payroll_imports.pohoda_oic.selected_count', { count: selected.length, total: selectableKeys.length }) }}</span>
            <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" :disabled="selectableKeys.length === 0 || !canWrite" @click="toggleAll">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="allSelectableSelected ? ICONS.x : ICONS.check" /></svg>
              {{ t(allSelectableSelected ? 'payroll_imports.pohoda_oic.select_none' : 'payroll_imports.pohoda_oic.select_all') }}
            </button>
          </div>
        </div>

        <p v-if="rows.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.pohoda_oic.no_rows') }}</p>

        <div v-else class="hidden max-h-[70vh] overflow-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="sticky top-0 z-10 w-10 bg-surface px-3 py-2">
                  <input type="checkbox" :checked="allSelectableSelected" :disabled="selectableKeys.length === 0 || !canWrite"
                    :aria-label="t('payroll_imports.pohoda_oic.select_all')" @change="toggleAll">
                </th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.pohoda_oic.columns.person') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.pohoda_oic.columns.personal_number') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.pohoda_oic.columns.oic') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.pohoda_oic.columns.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.key" class="align-top">
                <td class="px-3 py-2">
                  <input v-if="row.selectable" type="checkbox" :checked="selected.includes(row.key)" :disabled="!canWrite"
                    :aria-label="t('payroll_imports.pohoda_oic.select_row', { name: row.name })" @change="toggle(row.key)">
                </td>
                <td class="px-3 py-2">
                  <RouterLink v-if="row.employee_id" :to="{ name: 'payroll-person', params: { id: row.employee_id } }" class="font-medium text-payroll-600 hover:underline">
                    {{ row.employee_name ?? row.name }}
                  </RouterLink>
                  <p v-else class="font-medium text-neutral-900">{{ row.name || '—' }}</p>
                  <p class="font-mono text-xs text-neutral-500">{{ row.birth_number_masked ?? '—' }}</p>
                  <p class="text-[11px] text-neutral-400">{{ t('payroll_imports.pohoda_oic.source_row', { row: row.row }) }}</p>
                </td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-700">{{ row.personal_number ?? '—' }}</td>
                <td class="whitespace-nowrap px-3 py-2 font-mono text-neutral-900">{{ row.oic_masked ?? '—' }}</td>
                <td class="max-w-md px-3 py-2">
                  <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="oicStatusClass(row.status)">{{ t(`payroll_imports.pohoda_oic.status.${row.status}`) }}</span>
                  <p class="mt-1 text-xs text-neutral-600">{{ row.message }}</p>
                  <p v-if="row.status === 'ready'" class="mt-0.5 text-xs text-neutral-500">
                    {{ employmentText(row) }}<template v-if="row.valid_from"> · {{ t('payroll_imports.pohoda_oic.valid_from', { date: formatDate(row.valid_from) }) }}</template>
                  </p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="rows.length" class="space-y-2 p-3 md:hidden">
          <article v-for="row in rows" :key="row.key" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
            <div class="flex items-start gap-3">
              <input v-if="row.selectable" type="checkbox" class="mt-1" :checked="selected.includes(row.key)" :disabled="!canWrite"
                :aria-label="t('payroll_imports.pohoda_oic.select_row', { name: row.name })" @change="toggle(row.key)">
              <div class="min-w-0 flex-1">
                <p class="font-medium text-neutral-900">{{ row.employee_name ?? row.name }}</p>
                <p class="font-mono text-xs text-neutral-500">{{ row.birth_number_masked ?? '—' }} · {{ row.personal_number ?? '—' }}</p>
                <p class="font-mono text-xs text-neutral-700">{{ t('payroll_imports.pohoda_oic.columns.oic') }}: {{ row.oic_masked ?? '—' }}</p>
                <span class="mt-2 inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="oicStatusClass(row.status)">{{ t(`payroll_imports.pohoda_oic.status.${row.status}`) }}</span>
                <p class="mt-1 text-xs text-neutral-600">{{ row.message }}</p>
                <RouterLink v-if="row.employee_id" :to="{ name: 'payroll-person', params: { id: row.employee_id } }" class="mt-1 block text-xs text-payroll-600 hover:underline">
                  {{ t('payroll_imports.common.open_person') }}
                </RouterLink>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
        <label class="flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="evidenceConfirmed" type="checkbox" data-testid="pohoda-oic-confirm" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
          <span>
            <span class="font-medium">{{ t('payroll_imports.pohoda_oic.confirm') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.pohoda_oic.confirm_hint') }}</span>
          </span>
        </label>
        <div class="mt-4 flex flex-col items-start gap-1.5">
          <button
            type="button"
            data-testid="pohoda-oic-apply"
            :class="btnFilled('success')"
            class="whitespace-nowrap"
            :disabled="!canApply"
            :title="disabledTitle(!canApply, applyBlockedReason)"
            @click="runApply"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ busy === 'apply' ? t('payroll_imports.common.working') : t('payroll_imports.pohoda_oic.apply', { count: selected.length }) }}
          </button>
          <p v-if="applyBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
        </div>
      </section>
    </template>

    <section v-if="result" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="pohoda-oic-result">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.pohoda_oic.result_title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll_imports.pohoda_oic.result_summary', result.summary) }}</p>
      <ul v-if="result.results.length" class="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
        <li v-for="item in result.results" :key="item.key" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-sm">
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">{{ item.name || item.key }}</p>
            <p class="mt-0.5 text-xs" :class="item.status === 'failed' ? 'text-danger-600' : 'text-neutral-600'">{{ item.message }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <RouterLink v-if="item.employee_id" :to="{ name: 'payroll-person', params: { id: item.employee_id } }" class="text-xs text-payroll-600 hover:underline">{{ t('payroll_imports.common.open_person') }}</RouterLink>
            <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="oicResultClass(item.status)">{{ t(`payroll_imports.pohoda_oic.result_status.${item.status}`) }}</span>
          </div>
        </li>
      </ul>
    </section>
  </section>
</template>
