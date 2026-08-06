<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  crownsToMinor,
  minorToCrowns,
  payrollRulesetsApi,
  percentToRate,
  rateToPercent,
  type PayrollRuleParameter,
  type PayrollRulesetCommand,
  type PayrollRulesetDetail,
  type PayrollRulesetDiff,
  type PayrollRulesetOverview,
  type PayrollRulesetSummary,
} from '@/api/payrollRulesets'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const overview = ref<PayrollRulesetOverview | null>(null)
const detail = ref<PayrollRulesetDetail | null>(null)
const diff = ref<PayrollRulesetDiff | null>(null)
const TABS = ['parameters', 'diff', 'audit'] as const
type RulesetTab = (typeof TABS)[number]
const tab = ref<RulesetTab>('parameters')
const reason = ref('')
const drafts = reactive<Record<string, string>>({})

const canEdit = computed(() => auth.isSuperadmin)

const lifecycleClass: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  reviewed: 'bg-warning-50 text-warning-600',
  approved: 'bg-primary-50 text-primary-700',
  active: 'bg-success-50 text-success-600',
  superseded: 'bg-neutral-100 text-neutral-500',
}

async function load() {
  loading.value = true
  try {
    overview.value = await payrollRulesetsApi.overview()
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.load_failed')))
  } finally {
    loading.value = false
  }
}

async function open(summary: PayrollRulesetSummary) {
  tab.value = 'parameters'
  reason.value = ''
  Object.keys(drafts).forEach(key => delete drafts[key])
  try {
    detail.value = await payrollRulesetsApi.detail(summary.ruleset_id)
    diff.value = summary.has_default
      ? await payrollRulesetsApi.diff(summary.ruleset_id, 'default')
      : null
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.load_failed')))
  }
}

function close() {
  detail.value = null
  diff.value = null
}

/** Interní jednotky se v UI neukazují — haléře jako Kč, sazby jako procenta. */
function displayValue(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'money_minor' && typeof parameter.value === 'number') {
    return `${minorToCrowns(parameter.value).toLocaleString('cs-CZ', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })} ${t('payroll.rulesets.unit.czk')}`
  }
  if (parameter.type === 'decimal_rate' && typeof parameter.value === 'string') {
    return `${rateToPercent(parameter.value).toLocaleString('cs-CZ', {
      maximumFractionDigits: 4,
    })} %`
  }
  if (parameter.type === 'boolean') {
    return parameter.value ? t('common.yes') : t('common.no')
  }
  return String(parameter.value ?? '')
}

function editableValue(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'money_minor' && typeof parameter.value === 'number') {
    return String(minorToCrowns(parameter.value))
  }
  if (parameter.type === 'decimal_rate' && typeof parameter.value === 'string') {
    return String(rateToPercent(parameter.value))
  }
  return String(parameter.value ?? '')
}

function draftFor(parameter: PayrollRuleParameter): string {
  return drafts[parameter.key] ?? editableValue(parameter)
}

function setDraft(parameter: PayrollRuleParameter, value: string) {
  drafts[parameter.key] = value
}

function isEditable(parameter: PayrollRuleParameter): boolean {
  return ['money_minor', 'decimal_rate', 'integer', 'text'].includes(parameter.type)
}

function unitLabel(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'money_minor') return t('payroll.rulesets.unit.czk')
  if (parameter.type === 'decimal_rate') return '%'
  return ''
}

/** @returns patch pouze se skutečně změněnými parametry (merge per klíč na BE). */
function changedParameters(): Record<string, Record<string, unknown>> {
  const patch: Record<string, Record<string, unknown>> = {}
  for (const parameter of detail.value?.parameters ?? []) {
    const draft = drafts[parameter.key]
    if (draft === undefined || draft === editableValue(parameter)) continue
    if (parameter.type === 'money_minor') {
      patch[parameter.key] = { type: parameter.type, value: crownsToMinor(Number(draft)) }
    } else if (parameter.type === 'decimal_rate') {
      patch[parameter.key] = { type: parameter.type, value: percentToRate(Number(draft)) }
    } else if (parameter.type === 'integer') {
      patch[parameter.key] = { type: parameter.type, value: Math.round(Number(draft)) }
    } else {
      patch[parameter.key] = { type: parameter.type, value: draft }
    }
  }
  return patch
}

const hasChanges = computed(() => Object.keys(changedParameters()).length > 0)

async function save() {
  if (!detail.value) return
  const parameters = changedParameters()
  if (Object.keys(parameters).length === 0) {
    toast.warning(t('payroll.rulesets.nothing_changed'))
    return
  }
  if (reason.value.trim() === '') {
    toast.warning(t('payroll.rulesets.reason_required'))
    return
  }
  saving.value = true
  try {
    const updated = await payrollRulesetsApi.save(detail.value.ruleset_id, {
      reason: reason.value.trim(),
      row_version: detail.value.row_version,
      parameters,
    })
    detail.value = updated
    Object.keys(drafts).forEach(key => delete drafts[key])
    reason.value = ''
    diff.value = updated.has_default
      ? await payrollRulesetsApi.diff(updated.ruleset_id, 'default')
      : null
    await load()
    toast.success(t('payroll.rulesets.saved'))
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.save_failed')))
  } finally {
    saving.value = false
  }
}

async function runCommand(command: PayrollRulesetCommand | null) {
  if (!detail.value || command === null) return
  if (reason.value.trim() === '') {
    toast.warning(t('payroll.rulesets.reason_required'))
    return
  }
  saving.value = true
  try {
    const result = await payrollRulesetsApi.command(detail.value.ruleset_id, command, {
      reason: reason.value.trim(),
      row_version: detail.value.row_version,
    })
    detail.value = result.ruleset
    reason.value = ''
    await load()
    toast.success(
      result.changed
        ? t(`payroll.rulesets.command_done.${command}`)
        : t('payroll.rulesets.command_noop'),
    )
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.command_failed')))
  } finally {
    saving.value = false
  }
}

async function resetToDefault() {
  if (!detail.value) return
  if (!window.confirm(t('payroll.rulesets.reset_confirm'))) return
  saving.value = true
  try {
    const result = await payrollRulesetsApi.reset(
      detail.value.ruleset_id,
      reason.value.trim() || t('payroll.rulesets.reset_default_reason'),
    )
    await load()
    if (result.ruleset) {
      detail.value = result.ruleset
      diff.value = result.ruleset.has_default
        ? await payrollRulesetsApi.diff(result.ruleset.ruleset_id, 'default')
        : null
    } else {
      close()
    }
    Object.keys(drafts).forEach(key => delete drafts[key])
    reason.value = ''
    toast.success(t('payroll.rulesets.reset_done'))
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.save_failed')))
  } finally {
    saving.value = false
  }
}

function errorMessage(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { error?: { message?: string } } } })
    ?.response?.data?.error?.message
  return typeof message === 'string' && message !== '' ? message : fallback
}

function diffValue(entry: { type?: string; value?: unknown } | undefined): string {
  if (!entry) return '—'
  return displayValue({
    key: '',
    type: (entry.type ?? 'text') as PayrollRuleParameter['type'],
    value: (entry.value ?? null) as PayrollRuleParameter['value'],
    capability: null,
    note: null,
  })
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.rulesets.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.rulesets.subtitle') }}</p>
      </div>
      <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </header>

    <p
      v-if="!canEdit"
      class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
    >
      {{ t('payroll.rulesets.read_only_hint') }}
    </p>

    <p
      v-if="overview && !overview.override_storage_available"
      class="rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-600"
    >
      {{ t('payroll.rulesets.storage_unavailable') }}
    </p>

    <p
      v-if="overview?.degraded_reason"
      class="rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-500"
    >
      {{ t('payroll.rulesets.degraded', { reason: overview.degraded_reason }) }}
    </p>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <section
      v-for="group in overview?.domains ?? []"
      v-else
      :key="group.domain"
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      :data-test="`ruleset-domain-${group.domain}`"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t(`payroll.rulesets.domain.${group.domain}`) }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.rulesets.version_count', { count: group.version_count }) }}
          </p>
        </div>
        <span
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="group.calculation_ready
            ? 'bg-success-50 text-success-600'
            : 'bg-warning-50 text-warning-600'"
        >
          {{ t(group.calculation_ready
            ? 'payroll.rulesets.ready'
            : 'payroll.rulesets.blocked') }}
        </span>
      </div>

      <ul v-if="group.coverage_issues.length" class="mt-3 space-y-1">
        <li
          v-for="issue in group.coverage_issues"
          :key="`${group.domain}-${issue.code}-${issue.message}`"
          class="rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-500"
        >
          {{ issue.message }}
        </li>
      </ul>

      <div class="mt-4 hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-3 py-2">{{ t('payroll.rulesets.column.version') }}</th>
              <th class="px-3 py-2">{{ t('payroll.rulesets.column.effective') }}</th>
              <th class="px-3 py-2">{{ t('payroll.rulesets.column.lifecycle') }}</th>
              <th class="px-3 py-2">{{ t('payroll.rulesets.column.source') }}</th>
              <th class="px-3 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="version in group.versions" :key="version.ruleset_id">
              <td class="px-3 py-3">
                <div class="font-medium text-neutral-900">{{ version.version }}</div>
                <div class="text-xs text-neutral-500">{{ version.ruleset_id }}</div>
              </td>
              <td class="px-3 py-3 whitespace-nowrap text-neutral-600">
                {{ version.effective_from }} – {{ version.effective_to }}
              </td>
              <td class="px-3 py-3">
                <span
                  class="rounded-full px-2 py-1 text-xs font-medium"
                  :class="lifecycleClass[version.lifecycle]"
                >
                  {{ t(`payroll.rulesets.lifecycle.${version.lifecycle}`) }}
                </span>
                <span
                  v-if="!version.checksum_valid"
                  class="ml-2 rounded-full bg-danger-50 px-2 py-1 text-xs font-medium text-danger-500"
                >
                  {{ t('payroll.rulesets.checksum_invalid') }}
                </span>
              </td>
              <td class="px-3 py-3 text-neutral-600">
                {{ t(version.is_override
                  ? 'payroll.rulesets.source.override'
                  : 'payroll.rulesets.source.builtin') }}
              </td>
              <td class="px-3 py-3 text-right">
                <button :class="btnOutlineSm('primary')" @click="open(version)">
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path :d="ICONS.edit" />
                  </svg>
                  {{ t('payroll.rulesets.open') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-3 md:hidden">
        <article
          v-for="version in group.versions"
          :key="version.ruleset_id"
          class="rounded-lg border border-neutral-200 p-3"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="font-medium text-neutral-900">{{ version.version }}</h3>
              <p class="mt-0.5 text-xs break-all text-neutral-500">{{ version.ruleset_id }}</p>
            </div>
            <span
              class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
              :class="lifecycleClass[version.lifecycle]"
            >
              {{ t(`payroll.rulesets.lifecycle.${version.lifecycle}`) }}
            </span>
          </div>
          <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
            <div>
              <dt class="text-neutral-500">{{ t('payroll.rulesets.column.effective') }}</dt>
              <dd class="mt-0.5 text-neutral-800">
                {{ version.effective_from }} – {{ version.effective_to }}
              </dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('payroll.rulesets.column.source') }}</dt>
              <dd class="mt-0.5 text-neutral-800">
                {{ t(version.is_override
                  ? 'payroll.rulesets.source.override'
                  : 'payroll.rulesets.source.builtin') }}
              </dd>
            </div>
          </dl>
          <div class="mt-3 flex flex-wrap justify-end gap-2">
            <button :class="btnOutlineSm('primary')" @click="open(version)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.edit" />
              </svg>
              {{ t('payroll.rulesets.open') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <Modal
      v-if="detail"
      :title="`${t(`payroll.rulesets.domain.${detail.domain}`)} · ${detail.version}`"
      width-class="max-w-5xl"
      @close="close"
    >
      <div class="space-y-5">
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="lifecycleClass[detail.lifecycle]"
          >
            {{ t(`payroll.rulesets.lifecycle.${detail.lifecycle}`) }}
          </span>
          <span class="text-xs text-neutral-500">
            {{ detail.effective_from }} – {{ detail.effective_to }}
          </span>
          <span class="text-xs break-all text-neutral-400">{{ detail.ruleset_id }}</span>
        </div>

        <ul v-if="detail.blockers.length" class="space-y-1">
          <li
            v-for="issue in detail.blockers"
            :key="issue.code + issue.message"
            class="rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-500"
          >
            <strong>{{ t('payroll.rulesets.blocked_because') }}</strong> {{ issue.message }}
          </li>
        </ul>
        <ul v-if="detail.warnings.length" class="space-y-1">
          <li
            v-for="issue in detail.warnings"
            :key="issue.code + issue.message"
            class="rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-600"
          >
            {{ issue.message }}
          </li>
        </ul>

        <nav class="flex flex-wrap gap-2 border-b border-neutral-200 pb-2">
          <button
            v-for="key in TABS"
            :key="key"
            type="button"
            class="cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="tab === key
              ? 'bg-primary-50 text-primary-700'
              : 'text-neutral-600 hover:bg-neutral-50'"
            @click="tab = key"
          >
            {{ t(`payroll.rulesets.tab.${key}`) }}
          </button>
        </nav>

        <div v-if="tab === 'parameters'" class="space-y-3">
          <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.parameter') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.value') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.note') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="parameter in detail.parameters" :key="parameter.key">
                  <td class="px-3 py-2 font-medium break-all text-neutral-900">{{ parameter.key }}</td>
                  <td class="px-3 py-2">
                    <div v-if="canEdit && isEditable(parameter)" class="flex items-center gap-2">
                      <input
                        :value="draftFor(parameter)"
                        :type="parameter.type === 'text' ? 'text' : 'number'"
                        step="any"
                        class="h-8 w-40 rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-900"
                        @input="setDraft(parameter, ($event.target as HTMLInputElement).value)"
                      >
                      <span class="text-xs text-neutral-500">{{ unitLabel(parameter) }}</span>
                    </div>
                    <span v-else class="text-neutral-700">{{ displayValue(parameter) }}</span>
                  </td>
                  <td class="px-3 py-2 text-xs text-neutral-500">
                    {{ parameter.note ?? '' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <article
            v-for="parameter in detail.parameters"
            :key="`m-${parameter.key}`"
            class="rounded-lg border border-neutral-200 p-3 md:hidden"
          >
            <h4 class="text-sm font-medium break-all text-neutral-900">{{ parameter.key }}</h4>
            <div v-if="canEdit && isEditable(parameter)" class="mt-2 flex items-center gap-2">
              <input
                :value="draftFor(parameter)"
                :type="parameter.type === 'text' ? 'text' : 'number'"
                step="any"
                class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-900"
                @input="setDraft(parameter, ($event.target as HTMLInputElement).value)"
              >
              <span class="text-xs whitespace-nowrap text-neutral-500">{{ unitLabel(parameter) }}</span>
            </div>
            <p v-else class="mt-1 text-sm text-neutral-700">{{ displayValue(parameter) }}</p>
            <p v-if="parameter.note" class="mt-1 text-xs text-neutral-500">{{ parameter.note }}</p>
          </article>
        </div>

        <div v-else-if="tab === 'diff'" class="space-y-3">
          <p v-if="!diff" class="text-sm text-neutral-500">
            {{ t('payroll.rulesets.diff_unavailable') }}
          </p>
          <template v-else>
            <p class="text-sm text-neutral-500">
              {{ t('payroll.rulesets.diff_hint', {
                unchanged: diff.parameters.unchanged_count,
              }) }}
            </p>
            <p v-if="diff.parameters.identical" class="text-sm text-neutral-600">
              {{ t('payroll.rulesets.diff_identical') }}
            </p>
            <div v-else class="overflow-x-auto">
              <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.parameter') }}</th>
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.before') }}</th>
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.after') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in diff.parameters.changed" :key="`c-${row.key}`">
                    <td class="px-3 py-2 break-all text-neutral-900">{{ row.key }}</td>
                    <td class="px-3 py-2 text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                    <td class="px-3 py-2 font-medium text-neutral-900">{{ diffValue(row.after) }}</td>
                  </tr>
                  <tr v-for="row in diff.parameters.added" :key="`a-${row.key}`">
                    <td class="px-3 py-2 break-all text-neutral-900">{{ row.key }}</td>
                    <td class="px-3 py-2 text-neutral-400">{{ t('payroll.rulesets.diff_added') }}</td>
                    <td class="px-3 py-2 font-medium text-success-600">{{ diffValue(row.after) }}</td>
                  </tr>
                  <tr v-for="row in diff.parameters.removed" :key="`r-${row.key}`">
                    <td class="px-3 py-2 break-all text-neutral-900">{{ row.key }}</td>
                    <td class="px-3 py-2 text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                    <td class="px-3 py-2 text-danger-500">{{ t('payroll.rulesets.diff_removed') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>

        <div v-else class="space-y-2">
          <p v-if="!detail.audit.length" class="text-sm text-neutral-500">
            {{ t('payroll.rulesets.audit_empty') }}
          </p>
          <article
            v-for="row in detail.audit"
            :key="row.id"
            class="rounded-lg border border-neutral-200 p-3 text-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-neutral-900">
                {{ t(`payroll.rulesets.action.${row.action}`) }}
              </span>
              <span class="text-xs text-neutral-500">{{ row.created_at }}</span>
            </div>
            <p class="mt-1 text-neutral-600">{{ row.reason }}</p>
            <p class="mt-1 text-xs text-neutral-400">
              {{ t('payroll.rulesets.audit_actor', { user: row.actor_user_id ?? '—' }) }}
            </p>
          </article>
        </div>

        <div v-if="canEdit" class="space-y-3 border-t border-neutral-200 pt-4">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.rulesets.reason_label') }}
            </span>
            <input
              v-model="reason"
              type="text"
              maxlength="1000"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              :placeholder="t('payroll.rulesets.reason_placeholder')"
            >
          </label>

          <div class="flex flex-wrap justify-end gap-2">
            <button
              v-if="detail.is_override"
              :class="btnOutline('warning')"
              :disabled="saving"
              @click="resetToDefault"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.uturn" />
              </svg>
              {{ t('payroll.rulesets.reset') }}
            </button>
            <button
              v-if="detail.next_command"
              :class="btnOutline('success')"
              :disabled="saving || detail.blockers.length > 0"
              :title="detail.blockers[0]?.message"
              @click="runCommand(detail.next_command)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.badgeCheck" />
              </svg>
              {{ t(`payroll.rulesets.command.${detail.next_command}`) }}
            </button>
            <button :class="btnFilled('primary')" :disabled="saving || !hasChanges" @click="save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.check" />
              </svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
