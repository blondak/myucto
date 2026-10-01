<script setup lang="ts">
/**
 * Rok přechodu z jiného mzdového programu — převzaté měsíce (PAM-17/PAM-18).
 *
 * Panel svítí jen tehdy, když firma má hranici mzdového modulu a pod ní leží
 * převzaté měsíce. U firmy, která začala v MyÚčtu, se neukáže vůbec.
 *
 * ⚠️ Doložení plateb NENÍ platba. Nevzniká tu závazek, dávka ani úhrada, takže
 * se to neobjeví v saldu ani v účetním deníku — a nemá, protože za historický
 * měsíc MyÚčto nikdy nic nedlužilo a jeho zaúčtování je v knihách z převodu.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollTakeoverRunsApi,
  type PayrollTakeoverOverview,
  type PayrollTakeoverOverviewPeriod,
  type PayrollTakeoverRunDetail,
} from '@/api/payrollTakeoverRuns'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatMoneyMinor as money, formatPeriod, formatPeriodRange } from '@/composables/useFormat'
import { usePerUserFlag } from '@/composables/usePerUserFlag'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  year: number
  canWrite: boolean
}>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const { t } = useI18n()
const toast = useToast()

const overview = ref<PayrollTakeoverOverview | null>(null)
const loading = ref(false)
const busy = ref('')
const details = ref<Record<string, PayrollTakeoverRunDetail>>({})
const openPeriod = ref('')
const discarding = ref<string>('')
const discardReason = ref('')

const periods = computed<PayrollTakeoverOverviewPeriod[]>(
  () => overview.value?.periods.filter(row => row.historical) ?? [],
)

/*
 * Převzaté měsíce jsou hotová historie, se kterou se běžně nic nedělá; panel
 * proto drží jen jeden řádek. Rozbalí se sám, jen když některý měsíc čeká na
 * převzetí. Mimo rok přechodu se neukazuje vůbec, pokud tam zrovna něco
 * nečeká (firma začínající v lednu převezme loňský rok právě tam).
 */
const transitionYear = computed(() => overview.value?.payroll_start_period?.slice(0, 4) ?? null)
const visible = computed(() =>
  periods.value.length > 0
  && (transitionYear.value === String(props.year) || buildable.value.length > 0),
)
const rangeLabel = computed(() => {
  const list = periods.value.map(row => row.period).sort()
  return list.length === 0 ? '' : formatPeriodRange(list[0], list[list.length - 1])
})

const expandedFlag = usePerUserFlag('payroll.takeover-panel.expanded')
const expanded = ref(expandedFlag.read() ?? false)

function toggleExpanded() {
  expanded.value = !expanded.value
  expandedFlag.write(expanded.value)
}

/** Id převzatého běhu měsíce; `null`, dokud běh neexistuje. */
function runIdFor(period: string): number | null {
  return periods.value.find(row => row.period === period)?.run_id ?? null
}

async function load() {
  loading.value = true
  try {
    overview.value = await payrollTakeoverRunsApi.overview(props.year)
    if (buildable.value.length > 0) expanded.value = true
  } catch {
    // Tichý neúspěch: panel je pomůcka roku přechodu, ne závora. Seznam běhů
    // nad ním musí zůstat použitelný i tehdy, když se přehled nenačte.
    overview.value = null
  } finally {
    loading.value = false
  }
}

watch(() => props.year, () => void load(), { immediate: true })

async function build(period: string) {
  busy.value = period
  try {
    const detail = await payrollTakeoverRunsApi.build(period)
    details.value = { ...details.value, [period]: detail }
    openPeriod.value = period
    toast.success(t('payroll.runs.takeover.built', { period: formatPeriod(period) }))
    await load()
    emit('changed')
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.runs.takeover.build_failed')))
  } finally {
    busy.value = ''
  }
}

/*
 * Po převodu jsou k převzetí všechny zpracované měsíce roku najednou — dřív se
 * převzaly jen klikáním po jednom. Běhy vznikají po sobě (každý je vlastní
 * rozhodnutí se svým auditem); první chyba dávku zastaví a řekne, u kterého
 * měsíce, aby se nepřeskočil potichu.
 */
const buildable = computed(() => periods.value.filter(row => !row.has_takeover_run && row.row_count > 0))
const buildingAll = ref(false)

async function buildAll() {
  if (buildingAll.value || buildable.value.length === 0) return
  buildingAll.value = true
  let built = 0
  try {
    for (const row of [...buildable.value]) {
      busy.value = row.period
      try {
        await payrollTakeoverRunsApi.build(row.period)
        built++
      } catch (error) {
        toast.error(t('payroll.runs.takeover.build_all_failed', {
          period: formatPeriod(row.period),
          built,
          reason: apiErrorMessage(error, t('payroll.runs.takeover.build_failed')),
        }))
        return
      }
    }
    toast.success(t('payroll.runs.takeover.built_all', { count: built }))
  } finally {
    busy.value = ''
    buildingAll.value = false
    await load()
    if (built > 0) emit('changed')
  }
}

async function toggle(period: string) {
  if (openPeriod.value === period) {
    openPeriod.value = ''
    return
  }
  openPeriod.value = period
  if (details.value[period] !== undefined) {
    return
  }
  const runId = runIdFor(period)
  if (runId === null) {
    return
  }
  busy.value = period
  try {
    details.value = {
      ...details.value,
      [period]: await payrollTakeoverRunsApi.detail(runId),
    }
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.runs.takeover.detail_failed')))
  } finally {
    busy.value = ''
  }
}

async function discard() {
  const period = discarding.value
  const runId = runIdFor(period)
  if (runId === null || discardReason.value.trim() === '') {
    return
  }
  busy.value = period
  try {
    await payrollTakeoverRunsApi.discard(runId, discardReason.value.trim())
    const next = { ...details.value }
    delete next[period]
    details.value = next
    discarding.value = ''
    discardReason.value = ''
    toast.success(t('payroll.runs.takeover.discarded'))
    await load()
    emit('changed')
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.runs.takeover.discard_failed')))
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <section
    v-if="visible"
    class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
    data-testid="payroll-takeover-panel"
  >
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="min-w-0 text-sm text-neutral-700" data-testid="payroll-takeover-summary">
        <span class="font-semibold text-neutral-900">{{ t('payroll.runs.takeover.title') }}:</span>
        {{ t('payroll.runs.takeover.summary', { range: rangeLabel, count: periods.length }) }}
        <template v-if="overview?.payroll_start_period">
          · {{ t('payroll.runs.takeover.boundary', {
            period: formatPeriod(overview.payroll_start_period),
          }) }}
        </template>
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="expanded && canWrite && buildable.length > 1"
          :class="btnFilled('primary')"
          :disabled="busy !== '' || buildingAll"
          data-testid="payroll-takeover-build-all"
          class="whitespace-nowrap"
          @click="buildAll"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.download" />
          </svg>
          {{ t('payroll.runs.takeover.build_all', { count: buildable.length }) }}
        </button>
        <button
          type="button"
          :class="btnOutlineSm('neutral')"
          class="whitespace-nowrap"
          :aria-expanded="expanded"
          data-testid="payroll-takeover-toggle"
          @click="toggleExpanded"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="expanded ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
          </svg>
          {{ expanded ? t('payroll.runs.takeover.collapse') : t('payroll.runs.takeover.expand') }}
        </button>
      </div>
    </div>

    <template v-if="expanded">
    <p class="mt-2 text-sm text-neutral-500">
      {{ t('payroll.runs.takeover.subtitle') }}
    </p>
    <p class="mt-1 text-sm text-neutral-500" data-testid="payroll-takeover-jmhz-note">
      {{ t('payroll.runs.takeover.jmhz_note') }}
    </p>

    <p v-if="loading" class="mt-3 text-sm text-neutral-500">
      {{ t('payroll.runs.takeover.loading') }}
    </p>

    <ul v-else class="mt-4 space-y-2" data-testid="payroll-takeover-list">
      <li
        v-for="row in periods"
        :key="row.period"
        class="rounded-lg border border-neutral-200 p-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-neutral-900">{{ formatPeriod(row.period) }}</span>
            <span
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="row.has_takeover_run
                ? 'bg-success-50 text-success-600'
                : 'bg-neutral-100 text-neutral-600'"
            >
              {{ row.has_takeover_run
                ? t('payroll.runs.takeover.state_built')
                : t('payroll.runs.takeover.state_available') }}
            </span>
            <span class="text-xs text-neutral-500">
              {{ t('payroll.runs.takeover.rows', { count: row.row_count }) }}
            </span>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-if="canWrite && !row.has_takeover_run"
              :class="btnFilled('primary')"
              :disabled="busy !== ''"
              :data-testid="`payroll-takeover-build-${row.period}`"
              class="whitespace-nowrap"
              @click="build(row.period)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.download" />
              </svg>
              {{ t('payroll.runs.takeover.build') }}
            </button>
            <button
              v-if="row.has_takeover_run"
              :class="btnOutlineSm('neutral')"
              :disabled="busy !== ''"
              class="whitespace-nowrap"
              @click="toggle(row.period)"
            >
              {{ openPeriod === row.period
                ? t('payroll.runs.takeover.hide')
                : t('payroll.runs.takeover.show') }}
            </button>
            <button
              v-if="canWrite && row.has_takeover_run"
              :class="btnOutlineSm('danger')"
              :disabled="busy !== ''"
              class="whitespace-nowrap"
              :data-testid="`payroll-takeover-discard-${row.period}`"
              @click="discarding = row.period"
            >
              {{ t('payroll.runs.takeover.discard') }}
            </button>
          </div>
        </div>

        <div v-if="discarding === row.period" class="mt-3 space-y-2">
          <p class="text-sm text-neutral-600">
            {{ t('payroll.runs.takeover.discard_hint') }}
          </p>
          <input
            v-model="discardReason"
            type="text"
            class="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
            :placeholder="t('payroll.runs.takeover.discard_reason')"
          >
          <div class="flex flex-wrap gap-2">
            <button
              :class="btnFilled('danger')"
              :disabled="busy !== '' || discardReason.trim() === ''"
              class="whitespace-nowrap"
              @click="discard()"
            >
              {{ t('payroll.runs.takeover.discard_confirm') }}
            </button>
            <button
              :class="btnOutlineSm('neutral')"
              class="whitespace-nowrap"
              @click="discarding = ''; discardReason = ''"
            >
              {{ t('payroll.runs.takeover.discard_cancel') }}
            </button>
          </div>
        </div>

        <div v-if="openPeriod === row.period && details[row.period]" class="mt-3">
          <p class="text-sm text-neutral-600">
            {{ t('payroll.runs.takeover.evidence_note') }}
          </p>
          <table class="mt-2 w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-neutral-500">
                <th class="py-1 pr-3">{{ t('payroll.runs.takeover.column_kind') }}</th>
                <th class="py-1 px-3">{{ t('payroll.runs.takeover.column_who') }}</th>
                <th class="py-1 px-3 text-right">{{ t('payroll.runs.takeover.column_amount') }}</th>
                <th class="py-1 pl-3">{{ t('payroll.runs.takeover.column_paid_on') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in details[row.period].payment_evidence"
                :key="item.id"
                class="border-t border-neutral-100"
              >
                <td class="py-1 pr-3">{{ t(`payroll.runs.takeover.kind.${item.evidence_kind}`) }}</td>
                <td class="py-1 px-3">
                  {{ (item.employee_name ?? item.external_person_ref)
                    || t('payroll.runs.takeover.whole_company') }}
                </td>
                <td class="py-1 px-3 text-right tabular-nums whitespace-nowrap">
                  {{ money(item.amount_minor, item.currency_code) }}
                </td>
                <td class="py-1 pl-3 whitespace-nowrap">
                  <span v-if="item.paid_on">{{ formatDate(item.paid_on) }}</span>
                  <span v-else class="text-neutral-500">
                    {{ t('payroll.runs.takeover.no_payment_date') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </li>
    </ul>
    </template>
  </section>
</template>
