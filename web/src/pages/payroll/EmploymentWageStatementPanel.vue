<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollWageStatementDocument,
  type PayrollWageStatementList,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{
  employmentId: number
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const loadError = ref('')
const formError = ref('')
const generating = ref(false)
const downloadingId = ref<number | null>(null)
const showForm = ref(false)
const data = ref<PayrollWageStatementList | null>(null)
const effectiveFrom = ref('')
const paymentPlace = ref('')
const note = ref('')
const pendingIdempotencyKey = ref('')

const readiness = computed(() => data.value?.readiness ?? null)
const documents = computed<PayrollWageStatementDocument[]>(() => data.value?.items ?? [])

const formValid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom.value)
  && paymentPlace.value.trim() !== ''
  && paymentPlace.value.trim().length <= 255
  && note.value.trim().length <= 500)

const blockReason = computed(() => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom.value)) return t('payroll.people.wage_statement.effective_from_required')
  if (paymentPlace.value.trim() === '') return t('payroll.people.wage_statement.payment_place_required')
  return ''
})

// Klíče vypsané doslova: složený klíč by statická analýza jmenných prostorů i18n nenašla.
function readinessLabel(code: string | null | undefined): string {
  switch (code) {
    case 'wage_statement_wage_missing': return t('payroll.people.wage_statement.readiness.wage_missing')
    case 'wage_statement_payday_missing': return t('payroll.people.wage_statement.readiness.payday_missing')
    case 'wage_statement_employer_missing': return t('payroll.people.wage_statement.readiness.employer_missing')
    case 'wage_statement_relation_unsupported': return t('payroll.people.wage_statement.readiness.relation_unsupported')
    case 'wage_statement_employment_closed': return t('payroll.people.wage_statement.readiness.employment_closed')
    default: return t('payroll.people.wage_statement.readiness.unknown')
  }
}

function createIdempotencyKey(): string {
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `wage-statement-${props.employmentId}-${random}`
}

function openForm(): void {
  showForm.value = !showForm.value
  if (!showForm.value) return
  effectiveFrom.value = readiness.value?.effective_from ?? ''
  paymentPlace.value = readiness.value?.payment_place ?? ''
  note.value = ''
  formError.value = ''
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await payrollApi.wageStatements(props.employmentId)
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.wage_statement.load_failed'))
  } finally {
    loading.value = false
  }
}

async function generate(): Promise<void> {
  if (!formValid.value || generating.value) return
  generating.value = true
  formError.value = ''
  pendingIdempotencyKey.value ||= createIdempotencyKey()
  try {
    await payrollApi.generateWageStatement(
      props.employmentId,
      {
        effective_from: effectiveFrom.value,
        payment_place: paymentPlace.value.trim(),
        note: note.value.trim() || null,
      },
      pendingIdempotencyKey.value,
    )
    pendingIdempotencyKey.value = ''
    showForm.value = false
    toast.success(t('payroll.people.wage_statement.created'))
    await load()
  } catch (error) {
    formError.value = apiErrorMessage(error, t('payroll.people.wage_statement.create_failed'))
    toast.error(formError.value)
  } finally {
    generating.value = false
  }
}

async function download(document: PayrollWageStatementDocument): Promise<void> {
  if (downloadingId.value !== null) return
  downloadingId.value = document.id
  try {
    await payrollApi.downloadDocument(document)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.wage_statement.download_failed')))
  } finally {
    downloadingId.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 sm:p-4" data-test="wage-statement-panel">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.wage_statement.title') }}</h4>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.wage_statement.subtitle') }}</p>
      </div>
      <button
        v-if="canWrite && readiness?.available"
        type="button"
        :class="[btnFilled('primary'), 'whitespace-nowrap']"
        data-test="open-wage-statement-form"
        @click="openForm"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="showForm ? ICONS.x : ICONS.doc" /></svg>
        {{ t(showForm ? 'common.cancel' : 'payroll.people.wage_statement.generate') }}
      </button>
    </div>

    <div v-if="loading" class="mt-3 h-16 animate-pulse rounded-lg bg-neutral-100" />
    <div v-else-if="loadError" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
      {{ loadError }}
    </div>
    <template v-else>
      <div
        v-if="readiness && !readiness.available"
        class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-warning-800"
        role="status"
        data-test="wage-statement-blocker"
      >
        <p class="text-sm font-medium">{{ readinessLabel(readiness.readiness_code) }}</p>
        <p v-if="readiness.message" class="mt-1 text-xs">{{ readiness.message }}</p>
        <RouterLink
          v-if="readiness.readiness_code === 'wage_statement_payday_missing' || readiness.readiness_code === 'wage_statement_employer_missing'"
          :to="{ name: 'payroll-settings' }"
          :class="[btnOutlineSm('warning'), 'mt-2 whitespace-nowrap']"
          data-test="wage-statement-fix-settings"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
          {{ t('payroll.people.wage_statement.open_settings') }}
        </RouterLink>
      </div>

      <form
        v-if="showForm && readiness?.available"
        class="mt-3 space-y-3 rounded-lg border border-payroll-500/30 bg-surface p-3 sm:p-4"
        data-test="wage-statement-form"
        @submit.prevent="generate"
      >
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.wage_statement.effective_from') }}
            <DateInput v-model="effectiveFrom" data-test="wage-statement-effective-from" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            <span class="mt-1 block text-neutral-500">{{ t('payroll.people.wage_statement.effective_from_hint') }}</span>
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.wage_statement.payment_place') }}
            <input
              v-model="paymentPlace"
              data-test="wage-statement-payment-place"
              type="text"
              maxlength="255"
              required
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            >
            <span class="mt-1 block text-neutral-500">{{ t('payroll.people.wage_statement.payment_place_hint') }}</span>
          </label>
        </div>
        <label class="block text-xs text-neutral-600">
          {{ t('payroll.people.wage_statement.note') }}
          <textarea v-model="note" data-test="wage-statement-note" rows="2" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
        </label>
        <p class="text-xs text-neutral-500">{{ t('payroll.people.wage_statement.sources_hint') }}</p>
        <p v-if="formError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="wage-statement-error">
          {{ formError }}
        </p>
        <p v-if="blockReason" class="rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-800" data-test="wage-statement-block-reason">
          {{ blockReason }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="showForm = false">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="!formValid || generating" data-test="generate-wage-statement">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
            {{ t('payroll.people.wage_statement.generate') }}
          </button>
        </div>
      </form>

      <div class="mt-3 space-y-2">
        <p v-if="documents.length === 0" class="text-sm text-neutral-500" data-test="wage-statement-empty">
          {{ t('payroll.people.wage_statement.empty') }}
        </p>
        <article
          v-for="document in documents"
          :key="document.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3"
          data-test="wage-statement-document"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-neutral-900">{{ document.suggested_filename }}</p>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t('payroll.people.wage_statement.valid_from', { date: formatDate(document.effective_from ?? '') }) }} ·
              {{ t('payroll.people.wage_statement.version', { version: document.wage_statement_revision_no ?? 1 }) }} ·
              {{ formatDate(document.created_at) }}
            </p>
          </div>
          <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="downloadingId !== null" data-test="download-wage-statement" @click="download(document)">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
            {{ t('common.download') }}
          </button>
        </article>
      </div>
    </template>
  </section>
</template>
