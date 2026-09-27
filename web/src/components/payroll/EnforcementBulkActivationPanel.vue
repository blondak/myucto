<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollEnforcementApi,
  type EnforcementBulkReadiness,
  type EnforcementBulkResult,
} from '@/api/payrollEnforcement'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'

/*
 * Hromadné ověření převzatých exekucí (C-9). Případy „Přijato — čeká na
 * ověření" mzdový běh nesráží; jednotlivě to bylo přes sto kliknutí. Panel
 * ukáže, komu co chybí (s proklikem do detailu případu), a u připravených
 * jedním potvrzením ověří pohledávky a evidenci a zahájí srážení.
 */
const props = defineProps<{ canWrite: boolean }>()
const emit = defineEmits<{
  'open-case': [caseId: number, employeeId: number]
  activated: []
}>()

const { t } = useI18n()
const rows = ref<EnforcementBulkReadiness[]>([])
const selected = ref<number[]>([])
const confirmed = ref(false)
const busy = ref(false)
const error = ref('')
const results = ref<EnforcementBulkResult[]>([])
const expanded = ref(false)

const readyRows = computed(() => rows.value.filter(row => row.missing.length === 0))
const incompleteRows = computed(() => rows.value.filter(row => row.missing.length > 0))

async function load(): Promise<void> {
  try {
    rows.value = await payrollEnforcementApi.bulkReadiness()
    selected.value = readyRows.value.map(row => row.case_id)
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.enforcement.bulk.load_failed'))
  }
}

function toggleAll(): void {
  selected.value = selected.value.length === readyRows.value.length
    ? []
    : readyRows.value.map(row => row.case_id)
}

async function activate(): Promise<void> {
  if (busy.value || !confirmed.value || selected.value.length === 0) return
  busy.value = true
  error.value = ''
  try {
    results.value = await payrollEnforcementApi.bulkActivate(
      rows.value
        .filter(row => selected.value.includes(row.case_id))
        .map(row => ({ case_id: row.case_id, row_version: row.row_version })),
    )
    confirmed.value = false
    await load()
    emit('activated')
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.enforcement.bulk.failed'))
  } finally {
    busy.value = false
  }
}

function rowName(caseId: number): string {
  return rows.value.find(row => row.case_id === caseId)?.employee_name ?? `#${caseId}`
}

const activatedCount = computed(() => results.value.filter(result => result.status === 'activated').length)
const failedResults = computed(() => results.value.filter(result => result.status === 'failed'))

onMounted(load)
</script>

<template>
  <section
    v-if="rows.length > 0 || results.length > 0"
    class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-neutral-700"
    data-test="enforcement-bulk-activation"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <p class="font-medium text-warning-900">
          {{ t('payroll.enforcement.bulk.title', { count: rows.length, ready: readyRows.length }) }}
        </p>
        <p class="mt-1">{{ t('payroll.enforcement.bulk.hint') }}</p>
      </div>
      <button
        v-if="rows.length > 0"
        type="button"
        :class="[btnOutline('warning'), 'whitespace-nowrap']"
        data-test="enforcement-bulk-toggle"
        @click="expanded = !expanded"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
        {{ expanded ? t('payroll.enforcement.bulk.collapse') : t('payroll.enforcement.bulk.expand') }}
      </button>
    </div>

    <p v-if="results.length" class="mt-2 font-medium text-success-700" data-test="enforcement-bulk-result">
      {{ t('payroll.enforcement.bulk.done', { activated: activatedCount, failed: failedResults.length }) }}
    </p>
    <ul v-if="failedResults.length" class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-danger-700">
      <li v-for="result in failedResults" :key="result.case_id">
        {{ rowName(result.case_id) }}: {{ result.message }}
      </li>
    </ul>
    <p v-if="error" class="mt-2 text-danger-700" role="alert">{{ error }}</p>

    <div v-if="expanded" class="mt-3 space-y-3">
      <div v-if="readyRows.length" class="rounded-lg border border-neutral-200 bg-surface p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium text-neutral-900">{{ t('payroll.enforcement.bulk.ready_title', { count: readyRows.length }) }}</p>
          <button type="button" class="text-xs font-medium text-payroll-700 underline" @click="toggleAll">
            {{ t('payroll.enforcement.bulk.toggle_all') }}
          </button>
        </div>
        <ul class="mt-2 space-y-1">
          <li v-for="row in readyRows" :key="row.case_id" class="flex flex-wrap items-center gap-2">
            <input
              v-model="selected"
              type="checkbox"
              :value="row.case_id"
              :data-test="`enforcement-bulk-select-${row.case_id}`"
            >
            <button type="button" class="font-medium underline" @click="emit('open-case', row.case_id, row.employee_id)">
              {{ row.employee_name }}
            </button>
            <span class="text-xs text-neutral-500">{{ t('payroll.enforcement.bulk.effective', { date: formatDate(row.effective_from) }) }}</span>
          </li>
        </ul>
        <label v-if="props.canWrite" class="mt-3 flex items-start gap-2 text-xs">
          <input v-model="confirmed" type="checkbox" data-test="enforcement-bulk-confirm">
          <span>{{ t('payroll.enforcement.bulk.confirm') }}</span>
        </label>
        <div v-if="props.canWrite" class="mt-2 flex flex-wrap gap-2">
          <button
            type="button"
            :class="[btnFilled('success'), 'whitespace-nowrap']"
            :disabled="busy || !confirmed || selected.length === 0"
            data-test="enforcement-bulk-activate"
            @click="activate"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.play" /></svg>
            {{ t('payroll.enforcement.bulk.activate', { count: selected.length }) }}
          </button>
        </div>
      </div>

      <div v-if="incompleteRows.length" class="rounded-lg border border-neutral-200 bg-surface p-3">
        <p class="font-medium text-neutral-900">{{ t('payroll.enforcement.bulk.incomplete_title', { count: incompleteRows.length }) }}</p>
        <ul class="mt-2 space-y-2">
          <li
            v-for="row in incompleteRows"
            :key="row.case_id"
            class="flex flex-wrap items-start justify-between gap-2"
            :data-test="`enforcement-bulk-incomplete-${row.case_id}`"
          >
            <div class="min-w-0 flex-1">
              <p class="font-medium text-neutral-900">{{ row.employee_name }}</p>
              <p class="text-xs text-warning-800">
                {{ row.missing.map(code => t(`payroll.enforcement.bulk.missing.${code}`)).join(' · ') }}
              </p>
            </div>
            <button
              type="button"
              :class="[btnOutline('warning'), 'whitespace-nowrap']"
              :data-test="`enforcement-bulk-open-${row.case_id}`"
              @click="emit('open-case', row.case_id, row.employee_id)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('payroll.enforcement.bulk.open_case') }}
            </button>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
