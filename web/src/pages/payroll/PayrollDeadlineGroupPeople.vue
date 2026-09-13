<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollDeadlineChecklistCompletePayload,
  type PayrollDeadlineChecklistFailure,
  type PayrollDeadlineGroup,
  type PayrollDeadlineGroupItemsPage,
  type PayrollDeadlinePersonItem,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import {
  btnFilled,
  btnOutline,
  BTN_DISABLED_NOTE,
  disabledTitle,
  ICONS,
} from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { PHASE_BADGE, usePayrollDeadlineLabels } from '@/pages/payroll/payrollDeadlineLabels'

/**
 * Lidé jedné skupiny přehledu termínů — stránkovaně, s hledáním a
 * s hromadným odškrtnutím.
 *
 * Why: import docházky umí založit nástupní checklist stovkám lidí naráz
 * a jejich smlouvy i přihlášky přitom vyřídil předchozí systém. Seznam se
 * proto dotahuje po stránkách (u 500 lidí se nepřenáší všechno) a vyřídit se
 * dá výběr i celá skupina jedním potvrzením. Poznámka je povinná: je to
 * jediná stopa, proč je položka splněná, a jde do události i auditu každé
 * položky zvlášť.
 */

const props = defineProps<{
  group: PayrollDeadlineGroup
  horizonDays: number
}>()

const emit = defineEmits<{ completed: [count: number] }>()

const PER_PAGE = 25
/** Pojistka proti nekonečné smyčce, kdyby server kurzor neposunul. */
const MAX_ROUNDS = 200

const { t } = useI18n()
const auth = useAuthStore()
const { titleFor, dueLabel, itemLink } = usePayrollDeadlineLabels()

const uid = props.group.key.replace(/[^a-z0-9_-]/gi, '-')
const page = ref(1)
const query = ref('')
const data = ref<PayrollDeadlineGroupItemsPage | null>(null)
const loading = ref(false)
const loadError = ref('')
const selected = ref<number[]>([])
const mode = ref<'selected' | 'all' | null>(null)
const note = ref('')
const running = ref(false)
const progress = ref({ done: 0, total: 0 })
const runError = ref('')
const failures = ref<PayrollDeadlineChecklistFailure[]>([])

/** Odškrtnout jde jen checklist; změny k ohlášení a dávky mají vlastní cestu. */
const canBulk = computed(
  () => props.group.source === 'checklist' && auth.canWrite('payroll.employment.write'),
)
const title = computed(() => titleFor(props.group.source, props.group.title))
const total = computed(() => data.value?.total ?? props.group.count)
const searching = computed(() => query.value.trim() !== '')
const noteValid = computed(() => note.value.trim() !== '')
const targetCount = computed(() => mode.value === 'selected' ? selected.value.length : total.value)
const pageIds = computed(() => (data.value?.items ?? [])
  .map(item => item.item_id)
  .filter((id): id is number => id !== undefined))
const allPageSelected = computed(
  () => pageIds.value.length > 0 && pageIds.value.every(id => selected.value.includes(id)),
)

let sequence = 0

async function fetchPage(): Promise<void> {
  const current = ++sequence
  loading.value = true
  loadError.value = ''
  try {
    const result = await payrollApi.deadlineGroupItems({
      phase: props.group.phase,
      source: props.group.source,
      title: props.group.title,
      ...(searching.value ? { q: query.value.trim() } : {}),
      offset: (page.value - 1) * PER_PAGE,
      limit: PER_PAGE,
      horizon_days: props.horizonDays,
    })
    if (current !== sequence) return
    // Po odškrtnutí se skupina zmenší; stránka za koncem by ukázala prázdno.
    if (result.items.length === 0 && result.total > 0 && page.value > 1) {
      page.value = Math.ceil(result.total / PER_PAGE)
      return
    }
    data.value = result
  } catch (error: unknown) {
    if (current === sequence) {
      loadError.value = apiErrorMessage(error, t('payroll.dashboard.deadlines.group.load_failed'))
    }
  } finally {
    if (current === sequence) loading.value = false
  }
}

watch(page, fetchPage)
watch(query, () => {
  if (page.value === 1) void fetchPage()
  else page.value = 1
})
// Po hromadné akci panel přenačte skupiny; změněný počet = nová data.
watch(() => props.group.count, fetchPage)

function isSelected(item: PayrollDeadlinePersonItem): boolean {
  return item.item_id !== undefined && selected.value.includes(item.item_id)
}

function toggleRow(item: PayrollDeadlinePersonItem): void {
  const id = item.item_id
  if (id === undefined) return
  selected.value = selected.value.includes(id)
    ? selected.value.filter(value => value !== id)
    : [...selected.value, id]
}

function togglePage(): void {
  selected.value = allPageSelected.value
    ? selected.value.filter(id => !pageIds.value.includes(id))
    : [...new Set([...selected.value, ...pageIds.value])]
}

function openConfirm(target: 'selected' | 'all'): void {
  mode.value = target
  note.value = t('payroll.dashboard.deadlines.group.note_default')
  runError.value = ''
}

async function run(): Promise<void> {
  if (!noteValid.value || mode.value === null) return
  running.value = true
  runError.value = ''
  failures.value = []
  progress.value = { done: 0, total: targetCount.value }
  const base = mode.value === 'selected'
    ? { item_ids: [...selected.value] }
    : {
        phase: props.group.phase,
        item_key: props.group.title,
        horizon_days: props.horizonDays,
        ...(searching.value ? { q: query.value.trim() } : {}),
      }
  let completed = 0
  let afterId = 0
  try {
    for (let round = 0; round < MAX_ROUNDS; round++) {
      const payload = {
        ...base,
        note: note.value.trim(),
        ...(afterId > 0 ? { after_id: afterId } : {}),
      } as PayrollDeadlineChecklistCompletePayload
      const result = await payrollApi.completeDeadlineChecklist(payload)
      completed += result.completed.length
      failures.value.push(...result.failed)
      progress.value.done += result.completed.length + result.skipped.length + result.failed.length
      if (result.complete) break
      afterId = result.next_after_id
    }
    selected.value = []
    mode.value = null
  } catch (error: unknown) {
    runError.value = apiErrorMessage(error, t('payroll.dashboard.deadlines.group.run_failed'))
  } finally {
    running.value = false
  }
  if (completed > 0) emit('completed', completed)
}

onMounted(fetchPage)
</script>

<template>
  <div
    class="border-t border-neutral-200 bg-neutral-50/60 px-3 py-3"
    :data-test="`payroll-deadline-people-${group.key}`"
  >
    <div class="flex flex-wrap items-center gap-2">
      <label class="sr-only" :for="`deadline-search-${uid}`">
        {{ t('payroll.dashboard.deadlines.group.search') }}
      </label>
      <input
        :id="`deadline-search-${uid}`"
        v-model="query"
        type="search"
        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm sm:w-72"
        :placeholder="t('payroll.dashboard.deadlines.group.search')"
        data-test="payroll-deadline-people-search"
      >
      <template v-if="canBulk">
        <button
          type="button"
          :class="btnFilled('success')"
          :disabled="selected.length === 0 || running"
          :title="disabledTitle(selected.length === 0, t('payroll.dashboard.deadlines.group.no_selection'))"
          data-test="payroll-deadline-complete-selected"
          @click="openConfirm('selected')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ t('payroll.dashboard.deadlines.group.complete_selected', { n: selected.length }) }}
        </button>
        <button
          type="button"
          :class="btnOutline('success')"
          :disabled="running || total === 0"
          data-test="payroll-deadline-complete-all"
          @click="openConfirm('all')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.clipboardCheck" />
          </svg>
          {{ searching
            ? t('payroll.dashboard.deadlines.group.complete_found', { n: total })
            : t('payroll.dashboard.deadlines.group.complete_group', { n: total }) }}
        </button>
      </template>
    </div>
    <p
      v-if="canBulk && selected.length === 0 && mode === null"
      :class="[BTN_DISABLED_NOTE, 'mt-1']"
    >
      {{ t('payroll.dashboard.deadlines.group.no_selection') }}
    </p>

    <div
      v-if="mode !== null"
      class="mt-3 rounded-lg border border-success-500/40 bg-surface p-3"
      data-test="payroll-deadline-complete-confirm"
    >
      <p class="text-sm text-neutral-800">
        {{ t('payroll.dashboard.deadlines.group.confirm_text', { title, count: targetCount }) }}
      </p>
      <label class="mt-2 block text-xs font-medium text-neutral-600" :for="`deadline-note-${uid}`">
        {{ t('payroll.dashboard.deadlines.group.note_label') }}
      </label>
      <textarea
        :id="`deadline-note-${uid}`"
        v-model="note"
        rows="2"
        maxlength="500"
        class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
        data-test="payroll-deadline-complete-note"
      />
      <p v-if="!noteValid" class="mt-1 text-xs text-danger-700" data-test="payroll-deadline-complete-note-required">
        {{ t('payroll.dashboard.deadlines.group.note_required') }}
      </p>
      <p v-if="running" class="mt-2 text-xs text-neutral-600" data-test="payroll-deadline-complete-progress">
        {{ t('payroll.dashboard.deadlines.group.progress', progress) }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnFilled('success')"
          :disabled="!noteValid || running"
          data-test="payroll-deadline-complete-run"
          @click="run"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ t('payroll.dashboard.deadlines.group.confirm') }}
        </button>
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="running"
          data-test="payroll-deadline-complete-cancel"
          @click="mode = null"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('payroll.dashboard.deadlines.group.cancel') }}
        </button>
      </div>
    </div>

    <p
      v-if="runError"
      class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="payroll-deadline-complete-error"
    >
      {{ runError }}
    </p>
    <div
      v-if="failures.length > 0"
      class="mt-3 rounded-lg border border-warning-500/40 bg-warning-50 p-3 text-sm"
      role="alert"
      data-test="payroll-deadline-complete-failures"
    >
      <p class="font-medium text-warning-800">
        {{ t('payroll.dashboard.deadlines.group.failed_title', failures.length) }}
      </p>
      <ul class="mt-1 space-y-0.5 text-xs text-warning-800">
        <li v-for="failure in failures" :key="failure.item_id">
          {{ failure.subject || `#${failure.item_id}` }}: {{ failure.message }}
        </li>
      </ul>
    </div>

    <p
      v-if="loadError"
      class="mt-3 text-sm text-danger-700"
      role="alert"
      data-test="payroll-deadline-people-error"
    >
      {{ loadError }}
    </p>
    <div v-else-if="loading && !data" class="mt-3 space-y-2">
      <div v-for="index in 3" :key="index" class="h-8 animate-pulse rounded bg-neutral-100" />
    </div>
    <template v-else-if="data">
      <p
        v-if="data.items.length === 0"
        class="mt-3 text-sm text-neutral-500"
        data-test="payroll-deadline-people-empty"
      >
        {{ t('payroll.dashboard.deadlines.group.empty') }}
      </p>
      <template v-else>
        <table class="mt-3 hidden w-full text-sm md:table" data-test="payroll-deadline-people-table">
          <thead>
            <tr class="text-left text-xs text-neutral-500">
              <th v-if="canBulk" class="w-8 py-1.5 pr-2">
                <input
                  type="checkbox"
                  :checked="allPageSelected"
                  :aria-label="t('payroll.dashboard.deadlines.group.select_page')"
                  data-test="payroll-deadline-select-page"
                  @change="togglePage"
                >
              </th>
              <th class="py-1.5 pr-3 font-medium">{{ t('payroll.dashboard.deadlines.group.col_name') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('payroll.dashboard.deadlines.group.col_personal_number') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('payroll.dashboard.deadlines.group.col_due') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('payroll.dashboard.deadlines.group.col_state') }}</th>
              <th class="py-1.5 font-medium"><span class="sr-only">{{ t('payroll.dashboard.deadlines.group.open_card') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-200">
            <tr
              v-for="item in data.items"
              :key="item.reference"
              :data-test="`payroll-deadline-person-${item.reference}`"
            >
              <td v-if="canBulk" class="py-1.5 pr-2">
                <input
                  type="checkbox"
                  :checked="isSelected(item)"
                  :aria-label="t('payroll.dashboard.deadlines.group.select_row', { name: item.subject })"
                  :data-test="`payroll-deadline-select-${item.reference}`"
                  @change="toggleRow(item)"
                >
              </td>
              <td class="py-1.5 pr-3 font-medium break-words text-neutral-900">{{ item.subject }}</td>
              <td class="py-1.5 pr-3 font-mono text-xs text-neutral-600">{{ item.personal_number ?? '–' }}</td>
              <td class="py-1.5 pr-3 whitespace-nowrap">{{ item.due_on ? formatDate(item.due_on) : '–' }}</td>
              <td class="py-1.5 pr-3">
                <span class="rounded-full px-1.5 py-0.5 text-xs font-medium whitespace-nowrap" :class="PHASE_BADGE[item.phase]">
                  {{ dueLabel(item) }}
                </span>
              </td>
              <td class="py-1.5 text-right">
                <RouterLink
                  :to="itemLink(item)"
                  class="text-xs font-medium whitespace-nowrap text-primary-700 underline decoration-dotted underline-offset-2"
                  :data-test="`payroll-deadline-person-link-${item.reference}`"
                >
                  {{ t('payroll.dashboard.deadlines.group.open_card') }}
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>

        <ul class="mt-3 space-y-2 md:hidden" data-test="payroll-deadline-people-cards">
          <li
            v-for="item in data.items"
            :key="item.reference"
            class="flex min-w-0 items-start gap-3 rounded-md border border-neutral-200 bg-surface p-2.5"
          >
            <input
              v-if="canBulk"
              type="checkbox"
              class="mt-1"
              :checked="isSelected(item)"
              :aria-label="t('payroll.dashboard.deadlines.group.select_row', { name: item.subject })"
              @change="toggleRow(item)"
            >
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium break-words text-neutral-900">{{ item.subject }}</p>
              <p v-if="item.personal_number" class="font-mono text-xs text-neutral-500">{{ item.personal_number }}</p>
              <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                <span v-if="item.due_on" class="font-medium text-neutral-800">{{ formatDate(item.due_on) }}</span>
                <span class="rounded-full px-1.5 py-0.5 font-medium" :class="PHASE_BADGE[item.phase]">{{ dueLabel(item) }}</span>
                <RouterLink
                  :to="itemLink(item)"
                  class="ml-auto font-medium text-primary-700 underline decoration-dotted underline-offset-2"
                >
                  {{ t('payroll.dashboard.deadlines.group.open_card') }}
                </RouterLink>
              </div>
            </div>
          </li>
        </ul>

        <PaginationBar
          class="mt-3"
          :page="page"
          :per-page="PER_PAGE"
          :total="data.total"
          @update:page="page = $event"
        />
      </template>
    </template>
  </div>
</template>
