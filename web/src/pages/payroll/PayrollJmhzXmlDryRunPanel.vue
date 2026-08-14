<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzControlFinding,
  type PayrollJmhzControlReport,
  type PayrollJmhzXmlDryRun,
  type PayrollRun,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ runs: PayrollRun[] }>()

interface DryRunState {
  running: boolean
  error: string
  result: PayrollJmhzXmlDryRun | null
  showXml: boolean
}

const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const states = ref<Record<number, DryRunState>>({})

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function state(run: PayrollRun): DryRunState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

async function run(payrollRun: PayrollRun) {
  const id = revisionId(payrollRun)
  if (id === null || !canWrite.value) return
  const current: DryRunState = {
    running: true,
    error: '',
    result: null,
    showXml: false,
  }
  states.value = { ...states.value, [id]: current }
  try {
    const preparation = await payrollApi.freezeJmhzPreparation(
      id,
      crypto.randomUUID(),
    )
    states.value[id].result = await payrollApi.jmhzXmlDryRun(preparation.id)
  } catch (exception) {
    states.value[id].error = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_dry_run_failed'),
    )
  } finally {
    states.value[id].running = false
  }
}

function blockerLabel(code: string): string {
  const key = `payroll.submissions.overview.jmhz_dry_run_blockers.${code}`
  const translated = t(key)
  return translated === key ? code : translated
}

interface ControlGroup {
  key: string
  title: string
  tone: string
  findings: PayrollJmhzControlFinding[]
}

/**
 * Tři skupiny nálezů se liší dopadem, ne závažností textu: nepropustná vada
 * podání zneplatní, propustná ho nechá projít s chybnými daty a mezera
 * v pokrytí znamená, že jsme kontrolu vůbec nevykonali.
 */
function controlGroups(report: PayrollJmhzControlReport): ControlGroup[] {
  return ([
    {
      key: 'blocking',
      title: 'payroll.submissions.overview.jmhz_controls_blocking',
      tone: 'border-danger-500/30 bg-danger-50 text-danger-700',
      findings: report.blocking,
    },
    {
      key: 'gaps',
      title: 'payroll.submissions.overview.jmhz_controls_gaps',
      tone: 'border-warning-500/30 bg-warning-50 text-warning-700',
      findings: report.coverage_gaps,
    },
    {
      key: 'warnings',
      title: 'payroll.submissions.overview.jmhz_controls_warnings',
      tone: 'border-neutral-300 bg-neutral-50 text-neutral-700',
      findings: report.warnings,
    },
  ] satisfies ControlGroup[]).filter(group => group.findings.length > 0)
}

async function copyXml(payrollRun: PayrollRun) {
  const xml = state(payrollRun)?.result?.xml
  if (xml) await navigator.clipboard.writeText(xml)
}
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-xml-dry-run"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_dry_run_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_dry_run_description') }}
      </p>
    </div>
    <p v-if="runs.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_dry_run_empty') }}
    </p>
    <div v-else class="space-y-4 p-4">
      <article
        v-for="payrollRun in runs"
        :key="payrollRun.revision_id ?? payrollRun.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.jmhz_dry_run_card', {
              period: payrollRun.period_start.slice(0, 7),
              revision: payrollRun.revision_no,
            }) }}
          </h3>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canWrite || state(payrollRun)?.running"
            :data-test="`jmhz-dry-run-start-${payrollRun.revision_id}`"
            @click="run(payrollRun)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ state(payrollRun)?.running
              ? t('common.loading')
              : t('payroll.submissions.overview.jmhz_dry_run_start') }}
          </button>
        </div>

        <template v-if="state(payrollRun)?.result">
          <div
            v-if="state(payrollRun)!.result!.status !== 'blocked'"
            class="mt-3 rounded-lg p-3"
            :class="state(payrollRun)!.result!.status === 'dry_run_valid'
              ? 'border border-success-500/30 bg-success-50'
              : 'border border-warning-500/30 bg-warning-50'"
          >
            <p
              class="text-sm font-medium"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid'
                ? 'text-success-700'
                : 'text-warning-700'"
            >
              {{ state(payrollRun)!.result!.status === 'dry_run_valid'
                ? t('payroll.submissions.overview.jmhz_dry_run_valid', {
                  version: state(payrollRun)!.result!.schema?.data_version ?? '',
                })
                : t('payroll.submissions.overview.jmhz_dry_run_incomplete') }}
            </p>
            <p
              class="mt-1 break-all text-xs"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid'
                ? 'text-success-700'
                : 'text-warning-700'"
            >
              {{ state(payrollRun)!.result!.xml_sha256?.slice(0, 16) }}…
            </p>

            <div
              v-if="state(payrollRun)!.result!.controls"
              class="mt-3 space-y-3"
              data-test="jmhz-dry-run-controls"
            >
              <p class="text-xs text-neutral-600">
                {{ t('payroll.submissions.overview.jmhz_controls_summary', {
                  passed: state(payrollRun)!.result!.controls!.counts.passed,
                  failed: state(payrollRun)!.result!.controls!.counts.failed,
                  remote: state(payrollRun)!.result!.controls!.counts.not_evaluable,
                  gaps: state(payrollRun)!.result!.controls!.coverage_gaps.length,
                }) }}
              </p>
              <div
                v-for="group in controlGroups(state(payrollRun)!.result!.controls!)"
                :key="group.key"
                class="rounded-lg border p-3"
                :class="group.tone"
                :data-test="`jmhz-controls-${group.key}`"
              >
                <p class="text-sm font-medium">
                  {{ t(group.title, { count: group.findings.length }) }}
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                  <li v-for="finding in group.findings" :key="`${group.key}-${finding.control_id}-${finding.form_ordinal ?? ''}`">
                    <span class="font-mono text-xs opacity-75">
                      {{ finding.error_code ?? finding.control_id }}
                    </span>
                    {{ finding.message }}
                    <span
                      v-if="finding.form_ordinal !== null"
                      class="text-xs opacity-75"
                    >
                      ({{ t('payroll.submissions.overview.jmhz_controls_form', {
                        ordinal: finding.form_ordinal + 1,
                      }) }})
                    </span>
                  </li>
                </ul>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="state(payrollRun)!.showXml = !state(payrollRun)!.showXml"
              >
                {{ state(payrollRun)!.showXml
                  ? t('payroll.submissions.overview.jmhz_dry_run_hide_xml')
                  : t('payroll.submissions.overview.jmhz_dry_run_show_xml') }}
              </button>
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="copyXml(payrollRun)"
              >
                {{ t('payroll.submissions.overview.jmhz_dry_run_copy_xml') }}
              </button>
            </div>
            <pre
              v-if="state(payrollRun)!.showXml"
              class="mt-3 max-h-96 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
            >{{ state(payrollRun)!.result!.xml }}</pre>
          </div>
          <div
            v-else
            class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
          >
            <p class="text-sm font-medium text-warning-700">
              {{ t('payroll.submissions.overview.jmhz_dry_run_blocked', {
                count: state(payrollRun)!.result!.blockers.length,
              }) }}
            </p>
            <ul class="mt-2 space-y-1 text-sm text-warning-700">
              <li
                v-for="blocker in state(payrollRun)!.result!.blockers"
                :key="`${blocker.code}-${blocker.entity_type}-${blocker.entity_id ?? ''}`"
              >
                {{ blockerLabel(blocker.code) }}
                <span v-if="blocker.attribute_ids.length" class="text-xs opacity-75">
                  ({{ blocker.attribute_ids.join(', ') }})
                </span>
              </li>
            </ul>
          </div>
          <p class="mt-3 text-xs text-neutral-500">
            {{ state(payrollRun)!.result!.official_submission.reason }}
          </p>
        </template>

        <p
          v-if="state(payrollRun)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(payrollRun)?.error }}
        </p>
      </article>
    </div>
  </section>
</template>
