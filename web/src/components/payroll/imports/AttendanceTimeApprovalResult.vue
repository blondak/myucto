<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  groupTimeIssues,
  summaryTimeIssues,
  timeIssueFixLinks,
  type AttendanceTimeApproval,
  type AttendanceTimeIssue,
  type AttendanceTimeSummaryResult,
} from '@/api/payrollAttendanceApproval'
import ExpandableList from '@/components/ui/ExpandableList.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * Pracovní měsíce po použití dávky docházky: kolik se schválilo, kolik ne a proč.
 *
 * Výjimka je práce pro účetní, varování jen informace — proto výjimky nahoře
 * seskupené podle důvodu (text jednou, pod ním lidé s odkazem na nápravu)
 * a varování zvlášť a tlumeně. U stovek lidí se seznam sbalí.
 */
const props = withDefaults(defineProps<{
  approval: AttendanceTimeApproval | null
  summary?: AttendanceTimeSummaryResult | null
  /** YYYY-MM dávky */
  period: string
  /** Jména vztahů pro výsledek samotného zápisu souhrnů, který je nenese. */
  labels?: ReadonlyMap<number, { name: string; code: string }>
}>(), {
  summary: null,
  labels: () => new Map(),
})

const { t, te } = useI18n()

const issues = computed(() => {
  if (props.approval) return { exceptions: props.approval.exceptions, warnings: props.approval.warnings }
  if (props.summary) return summaryTimeIssues(props.summary, props.labels)
  return { exceptions: [], warnings: [] }
})
const exceptionGroups = computed(() => groupTimeIssues(issues.value.exceptions))
const warningGroups = computed(() => groupTimeIssues(issues.value.warnings))
const written = computed(() => props.approval?.written ?? props.summary?.written ?? 0)
const replayed = computed(() => props.approval?.replayed ?? props.summary?.replayed ?? 0)

function codeTitle(code: string): string {
  const key = `payroll_imports.attendance_time.codes.${code}`
  return te(key) ? t(key) : t('payroll_imports.attendance_time.codes.other')
}

function personName(item: AttendanceTimeIssue): string {
  return item.name || t('payroll_imports.attendance_time.result.unknown_person', { id: item.employment_id })
}

const issueKey = (item: AttendanceTimeIssue, index: number) => `${item.employment_id}-${index}`
const issueSearch = (item: AttendanceTimeIssue) => `${item.name} ${item.employment_code}`
</script>

<template>
  <section class="mt-3 space-y-3 rounded-lg border border-neutral-200 bg-surface p-3" data-testid="attendance-time-result">
    <h5 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance_time.result.title') }}</h5>

    <div class="flex flex-wrap gap-2">
      <article v-if="approval" class="min-w-28 rounded-lg bg-success-50 px-3 py-2" data-testid="attendance-time-approved">
        <p class="text-xs text-success-700">{{ t('payroll_imports.attendance_time.result.approved') }}</p>
        <p class="text-lg font-semibold tabular-nums text-success-700">{{ approval.approved }}</p>
      </article>
      <article v-if="approval?.already_approved" class="min-w-28 rounded-lg bg-neutral-50 px-3 py-2" data-testid="attendance-time-already-approved">
        <p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance_time.result.already_approved') }}</p>
        <p class="text-lg font-semibold tabular-nums">{{ approval.already_approved }}</p>
      </article>
      <article class="min-w-28 rounded-lg bg-neutral-50 px-3 py-2" data-testid="attendance-time-written">
        <p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance_time.result.written') }}</p>
        <p class="text-lg font-semibold tabular-nums">{{ written }}</p>
      </article>
      <article v-if="replayed" class="min-w-28 rounded-lg bg-neutral-50 px-3 py-2">
        <p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance_time.result.replayed') }}</p>
        <p class="text-lg font-semibold tabular-nums">{{ replayed }}</p>
      </article>
      <article class="min-w-28 rounded-lg px-3 py-2" :class="issues.exceptions.length ? 'bg-warning-50' : 'bg-neutral-50'" data-testid="attendance-time-exception-count">
        <p class="text-xs" :class="issues.exceptions.length ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll_imports.attendance_time.result.exceptions') }}</p>
        <p class="text-lg font-semibold tabular-nums" :class="issues.exceptions.length ? 'text-warning-700' : ''">{{ issues.exceptions.length }}</p>
      </article>
    </div>

    <p v-if="approval && issues.exceptions.length === 0" class="text-sm text-success-700" data-testid="attendance-time-all-approved">
      {{ t('payroll_imports.attendance_time.result.all_approved') }}
    </p>
    <p v-else-if="!approval" class="text-xs text-neutral-600" data-testid="attendance-time-not-approved">
      {{ t('payroll_imports.attendance_time.result.not_approved') }}
    </p>

    <div v-if="exceptionGroups.length" class="space-y-2" data-testid="attendance-time-exceptions">
      <p class="text-sm font-medium text-neutral-900">{{ t('payroll_imports.attendance_time.result.exceptions_title') }}</p>
      <div v-for="group in exceptionGroups" :key="group.code" class="rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-time-exception-group">
        <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance_time.result.group', { title: codeTitle(group.code), count: group.items.length }) }}</p>
        <p v-if="group.message" class="mt-0.5 max-w-3xl text-xs text-warning-700" data-testid="attendance-time-group-message">{{ group.message }}</p>
        <ExpandableList
          class="mt-2"
          :items="group.items"
          :item-key="issueKey"
          :search-text="issueSearch"
          list-class="divide-y divide-warning-500/20"
          item-class="py-1.5"
          :test-id="`attendance-time-exception-people-${group.code}`"
        >
          <template #item="{ item }">
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
              <div class="min-w-0">
                <span class="font-medium text-neutral-900">{{ personName(item) }}</span>
                <span v-if="item.employment_code" class="ml-1 text-xs text-neutral-500">{{ item.employment_code }}</span>
                <p v-if="!group.message" class="max-w-2xl text-xs text-warning-700">{{ item.message }}</p>
              </div>
              <div class="flex flex-wrap gap-1.5">
                <RouterLink
                  v-for="link in timeIssueFixLinks(group.code, item.employment_id, period)"
                  :key="link.label"
                  :to="link.to"
                  :class="btnOutlineSm('primary')"
                  :data-testid="`attendance-time-fix-${link.label}`"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS[link.icon]" /></svg>
                  {{ t(`payroll_imports.attendance_time.fix.${link.label}`) }}
                </RouterLink>
              </div>
            </div>
          </template>
        </ExpandableList>
      </div>
    </div>

    <div v-if="warningGroups.length" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3" data-testid="attendance-time-warnings">
      <p class="text-xs font-medium text-neutral-700">{{ t('payroll_imports.attendance_time.result.warnings_title', { count: issues.warnings.length }) }}</p>
      <div v-for="group in warningGroups" :key="group.code" class="mt-2" data-testid="attendance-time-warning-group">
        <p class="text-xs font-medium text-neutral-700">{{ t('payroll_imports.attendance_time.result.group', { title: codeTitle(group.code), count: group.items.length }) }}</p>
        <p v-if="group.message" class="max-w-3xl text-xs text-neutral-600">{{ group.message }}</p>
        <ExpandableList
          class="mt-1"
          :items="group.items"
          :item-key="issueKey"
          :search-text="issueSearch"
          :preview-limit="5"
          list-class="flex flex-wrap gap-x-3 gap-y-1"
          :test-id="`attendance-time-warning-people-${group.code}`"
        >
          <template #item="{ item }">
            <span class="text-xs text-neutral-600">
              <RouterLink
                :to="{ name: 'payroll-time', query: { employment: String(item.employment_id), period } }"
                class="text-neutral-700 hover:text-payroll-600 hover:underline"
              >{{ personName(item) }}</RouterLink><template v-if="!group.message">: {{ item.message }}</template>
            </span>
          </template>
        </ExpandableList>
      </div>
    </div>
  </section>
</template>
