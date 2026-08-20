<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzOrdinaryEvidence,
  type PayrollJmhzOrdinaryEvidenceScope,
  type PayrollRun,
} from '@/api/payroll'
import { btnFilled } from '@/components/ui/buttonStyles'
import { ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ runs: PayrollRun[] }>()

type FactKey =
  | 'reportable_wage_deductions_recorded'
  | 'employee_social_discount_claimed'
  | 'specific_legal_fact_occurred'
  | 'ozp_employment_support_claimed'
  | 'deep_mining_work_occurred'

/** Ordinary evidence se potvrzuje ZA PRACOVNÍ VZTAH, ne za revizi. */
interface ScopeState {
  scope: PayrollJmhzOrdinaryEvidenceScope
  saving: boolean
  error: string
  evidence: PayrollJmhzOrdinaryEvidence | null
  checks: Record<FactKey, boolean>
}

interface EvidenceState {
  loading: boolean
  error: string
  scopes: ScopeState[]
}

const factKeys: FactKey[] = [
  'reportable_wage_deductions_recorded',
  'employee_social_discount_claimed',
  'specific_legal_fact_occurred',
  'ozp_employment_support_claimed',
  'deep_mining_work_occurred',
]
const { locale, t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const states = ref<Record<number, EvidenceState>>({})

function emptyState(): EvidenceState {
  return { loading: true, error: '', scopes: [] }
}

function emptyScopeState(scope: PayrollJmhzOrdinaryEvidenceScope): ScopeState {
  return {
    scope,
    saving: false,
    error: '',
    evidence: null,
    checks: {
      reportable_wage_deductions_recorded: false,
      employee_social_discount_claimed: false,
      specific_legal_fact_occurred: false,
      ozp_employment_support_claimed: false,
      deep_mining_work_occurred: false,
    },
  }
}

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function state(run: PayrollRun): EvidenceState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

function confirmed(entry: ScopeState): boolean {
  return entry.evidence !== null || entry.scope.confirmed
}

function pendingCount(run: PayrollRun): number {
  return state(run)?.scopes.filter(entry => !confirmed(entry)).length ?? 0
}

function allChecked(entry: ScopeState): boolean {
  return factKeys.every(key => entry.checks[key])
}

function scopeLabel(entry: ScopeState): string {
  return entry.scope.employee_name === ''
    ? t('payroll.submissions.overview.jmhz_evidence_scope_unnamed', {
        employment: entry.scope.employment_id,
      })
    : t('payroll.submissions.overview.jmhz_evidence_scope', {
        name: entry.scope.employee_name,
        employment: entry.scope.employment_id,
      })
}

async function load() {
  const next: Record<number, EvidenceState> = {}
  for (const run of props.runs) {
    const id = revisionId(run)
    if (id !== null) next[id] = emptyState()
  }
  states.value = next
  await Promise.all(props.runs.map(async run => {
    const id = revisionId(run)
    if (id === null) return
    try {
      const result = await payrollApi.jmhzOrdinaryEvidence(id)
      states.value[id].scopes = result.scopes.map(scope => {
        const entry = emptyScopeState(scope)
        entry.evidence = result.evidences.find(
          evidence => evidence.employment_id === scope.employment_id,
        ) ?? null
        return entry
      })
    } catch (exception) {
      states.value[id].error = apiErrorMessage(
        exception,
        t('payroll.submissions.overview.jmhz_evidence_load_failed'),
      )
    } finally {
      states.value[id].loading = false
    }
  }))
}

async function confirm(run: PayrollRun, entry: ScopeState) {
  const id = revisionId(run)
  if (id === null || entry.saving || !allChecked(entry)) return
  entry.saving = true
  entry.error = ''
  try {
    entry.evidence = await payrollApi.confirmJmhzOrdinaryEvidence(
      id,
      entry.scope.employment_id,
      crypto.randomUUID(),
    )
  } catch (exception) {
    entry.error = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_evidence_save_failed'),
    )
  } finally {
    entry.saving = false
  }
}

function formatTimestamp(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : 'cs-CZ', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

watch(() => props.runs, load, { immediate: true, deep: true })
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-ordinary-evidence"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_evidence_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_evidence_description') }}
      </p>
    </div>
    <p v-if="runs.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_evidence_empty') }}
    </p>
    <div v-else class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
      <article
        v-for="run in runs"
        :key="run.revision_id ?? run.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <h3 class="font-semibold text-neutral-900">
          {{ t('payroll.submissions.overview.jmhz_evidence_card', {
            period: run.period_start.slice(0, 7),
            revision: run.revision_no,
          }) }}
        </h3>
        <p v-if="state(run)?.loading" class="mt-3 text-sm text-neutral-500">
          {{ t('common.loading') }}
        </p>
        <template v-else>
          <p
            v-if="pendingCount(run) > 0"
            class="mt-2 text-sm text-warning-700"
            data-test="jmhz-ordinary-evidence-pending"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_pending', pendingCount(run)) }}
          </p>
          <p v-if="state(run)?.scopes.length === 0" class="mt-3 text-sm text-neutral-500">
            {{ t('payroll.submissions.overview.jmhz_evidence_no_scopes') }}
          </p>
          <div
            v-for="entry in state(run)?.scopes ?? []"
            :key="entry.scope.employment_id"
            class="mt-4 border-t border-neutral-200 pt-4 first:mt-3 first:border-t-0 first:pt-0"
            data-test="jmhz-ordinary-evidence-scope"
          >
            <p class="text-sm font-semibold text-neutral-900">{{ scopeLabel(entry) }}</p>
            <div
              v-if="confirmed(entry)"
              class="mt-2 rounded-lg border border-success-500/30 bg-success-50 p-3"
            >
              <div class="flex flex-wrap items-center gap-2 text-sm font-medium text-success-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.check" />
                </svg>
                {{ t('payroll.submissions.overview.jmhz_evidence_confirmed') }}
              </div>
              <p v-if="entry.evidence" class="mt-1 text-xs text-success-700">
                {{ formatTimestamp(entry.evidence.confirmed_at) }} ·
                {{ entry.evidence.source_manifest_sha256.slice(0, 12) }}…
              </p>
            </div>
            <template v-else>
              <p class="mt-2 text-sm font-medium text-neutral-700">
                {{ t('payroll.submissions.overview.jmhz_evidence_confirm_each') }}
              </p>
              <div class="mt-3 space-y-3">
                <label
                  v-for="key in factKeys"
                  :key="key"
                  class="flex items-start gap-3 text-sm text-neutral-700"
                >
                  <input
                    v-model="entry.checks[key]"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-success-600 focus:ring-success-500"
                    :disabled="!canWrite || entry.saving"
                  >
                  <span>{{ t(`payroll.submissions.overview.jmhz_evidence_facts.${key}`) }}</span>
                </label>
              </div>
              <button
                type="button"
                :class="btnFilled('success')"
                class="mt-4"
                :disabled="!canWrite || !allChecked(entry) || entry.saving"
                @click="confirm(run, entry)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.check" />
                </svg>
                {{ t('payroll.submissions.overview.jmhz_evidence_confirm') }}
              </button>
            </template>
            <p
              v-if="entry.error"
              class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
              role="alert"
            >
              {{ entry.error }}
            </p>
          </div>
        </template>
        <p
          v-if="state(run)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(run)?.error }}
        </p>
      </article>
    </div>
  </section>
</template>
