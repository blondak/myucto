<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollInput,
  type PayrollInputGroup,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import type { PayrollPeriodRange } from '@/pages/payroll/payrollPeriodScope'

/**
 * Historie mzdových vstupů jednoho pracovního vztahu — jeden řádek na měsíc.
 *
 * Why: měsíční mřížka odpovídá na „co tomuhle člověku zadám za srpen".
 * Neodpovídá na „co bral loni" ani na „kdy naposledy dostal odměnu", a přesně
 * na to se účetní ptá, když si otevře kartu konkrétního zaměstnance. Přepínat
 * období po měsících je k té odpovědi cesta jen formálně.
 *
 * Seskupení počítá SERVER (`group_by=period`), takže součet měsíce sedí na
 * celý měsíc, ne na načtenou stránku. Rozbalení měsíce dotáhne jeho vstupy
 * zvlášť: většina lidí si otevře jeden dva měsíce, takže tahat rozpad ke všem
 * dopředu by byla práce navíc pro server i pro oči.
 */
const props = defineProps<{
  employmentId: number
  range: PayrollPeriodRange
}>()

const emit = defineEmits<{ open: [period: string] }>()

const { t } = useI18n()
const toast = useToast()

const PAGE_SIZE = 12
/** Strop rozpadu měsíce; víc vstupů v jednom měsíci na jednoho člověka nebývá. */
const DETAIL_LIMIT = 200

const groups = ref<PayrollInputGroup[]>([])
const total = ref(0)
const offset = ref(0)
const loading = ref(false)
const loadFailed = ref(false)
/** Rozbalené měsíce; `null` = načítá se, pole = načteno. */
const details = ref<Record<string, PayrollInput[] | null>>({})
let generation = 0

const page = computed(() => Math.floor(offset.value / PAGE_SIZE) + 1)
const empty = computed(() => !loading.value && !loadFailed.value && groups.value.length === 0)

/** `group_key` chodí jako `YYYYMM` (int) — na období se z něj musí zpátky. */
function groupPeriod(group: PayrollInputGroup): string {
  return group.label
}

async function load(): Promise<void> {
  const mine = ++generation
  loading.value = true
  loadFailed.value = false
  details.value = {}
  try {
    const result = await payrollApi.inputs(
      props.range.from,
      { limit: PAGE_SIZE, offset: offset.value },
      props.employmentId,
      {},
      'period',
      props.range.to,
    )
    if (mine !== generation) return
    groups.value = result.groups ?? []
    total.value = result.group_total ?? 0
  } catch (error) {
    if (mine !== generation) return
    // Prázdná tabulka po chybě by tvrdila „tenhle člověk nic nemá“, což je
    // něco úplně jiného než „nevíme“.
    loadFailed.value = true
    groups.value = []
    total.value = 0
    toast.error(apiErrorMessage(error, t('payroll.agendas.history.load_failed')))
  } finally {
    if (mine === generation) loading.value = false
  }
}

async function toggleDetail(group: PayrollInputGroup): Promise<void> {
  const period = groupPeriod(group)
  if (period in details.value) {
    const next = { ...details.value }
    delete next[period]
    details.value = next
    return
  }
  details.value = { ...details.value, [period]: null }
  try {
    const result = await payrollApi.inputs(
      period,
      { limit: DETAIL_LIMIT, offset: 0 },
      props.employmentId,
    )
    if (period in details.value) {
      details.value = { ...details.value, [period]: result.items }
    }
  } catch (error) {
    const next = { ...details.value }
    delete next[period]
    details.value = next
    toast.error(apiErrorMessage(error, t('payroll.agendas.history.load_failed')))
  }
}

function statusClass(status: PayrollInput['status']): string {
  if (status === 'approved' || status === 'locked') return 'bg-success-50 text-success-600'
  return status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'
}

function goToPage(value: number): void {
  offset.value = Math.max(0, (value - 1) * PAGE_SIZE)
  void load()
}

watch(
  () => [props.employmentId, props.range.from, props.range.to],
  () => {
    offset.value = 0
    void load()
  },
  { immediate: true },
)
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface"
    data-test="payroll-input-history"
  >
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3">
      <div>
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.agendas.history.title') }}</h2>
        <p class="text-xs text-neutral-500">{{ t('payroll.agendas.history.subtitle') }}</p>
      </div>
      <span v-if="total > 0" class="text-xs text-neutral-500 tabular-nums">
        {{ t('payroll.agendas.history.month_count', { n: total }) }}
      </span>
    </header>

    <p v-if="loading" class="px-4 py-6 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p
      v-else-if="loadFailed"
      class="px-4 py-6 text-sm text-danger-700"
      role="alert"
      data-test="payroll-input-history-error"
    >
      {{ t('payroll.agendas.history.load_failed') }}
    </p>
    <EmptyState
      v-else-if="empty"
      class="m-4"
      :title="t('payroll.agendas.history.empty_title')"
      :message="t('payroll.agendas.history.empty_description')"
    />

    <template v-else>
      <div data-layout="desktop" class="hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-4 py-3">{{ t('payroll.agendas.history.period') }}</th>
              <th class="px-4 py-3 text-right">{{ t('payroll.agendas.history.count') }}</th>
              <th class="px-4 py-3 text-right">{{ t('payroll.agendas.history.draft_count') }}</th>
              <th class="px-4 py-3 text-right">{{ t('payroll.agendas.history.amount') }}</th>
              <th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="group in groups" :key="group.key">
              <tr>
                <td class="px-4 py-3">
                  <button
                    type="button"
                    class="cursor-pointer inline-flex items-center gap-2 font-medium text-neutral-900 hover:text-payroll-700"
                    :data-test="`payroll-input-history-toggle-${groupPeriod(group)}`"
                    @click="toggleDetail(group)"
                  >
                    <span
                      class="inline-block text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': groupPeriod(group) in details }"
                    >▸</span>
                    {{ formatPeriod(groupPeriod(group)) }}
                  </button>
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ group.count }}</td>
                <td class="px-4 py-3 text-right tabular-nums">
                  <span v-if="group.draft_count > 0" class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">
                    {{ group.draft_count }}
                  </span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums">
                  {{ formatMoneyMinor(group.amount_minor) }}
                </td>
                <td class="px-4 py-3 text-right">
                  <button
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :data-test="`payroll-input-history-open-${groupPeriod(group)}`"
                    @click="emit('open', groupPeriod(group))"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.calendar" /></svg>
                    {{ t('payroll.agendas.history.open_month') }}
                  </button>
                </td>
              </tr>
              <tr v-if="groupPeriod(group) in details" class="bg-neutral-50/60">
                <td colspan="5" class="px-4 py-3">
                  <p v-if="details[groupPeriod(group)] === null" class="text-xs text-neutral-500">
                    {{ t('common.loading') }}
                  </p>
                  <ul v-else class="space-y-1">
                    <li
                      v-for="input in details[groupPeriod(group)] ?? []"
                      :key="input.id"
                      class="flex flex-wrap items-center justify-between gap-2 text-xs"
                    >
                      <span class="min-w-0">
                        <span class="font-medium text-neutral-800">{{ input.component_name }}</span>
                        <span class="ml-2 font-mono text-neutral-400">{{ input.component_code }}</span>
                        <span class="ml-2 text-neutral-500">{{ t(`payroll.components.source.${input.source_kind}`) }}</span>
                      </span>
                      <span class="flex items-center gap-3">
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="statusClass(input.status)">
                          {{ t(`payroll.components.input_status.${input.status}`) }}
                        </span>
                        <span class="font-medium tabular-nums text-neutral-900">{{ formatMoneyMinor(input.amount_minor) }}</span>
                      </span>
                    </li>
                  </ul>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div data-layout="mobile" class="space-y-3 p-4 md:hidden">
        <article v-for="group in groups" :key="group.key" class="rounded-lg border border-neutral-200 p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold text-neutral-900">{{ formatPeriod(groupPeriod(group)) }}</span>
            <span class="font-semibold tabular-nums">{{ formatMoneyMinor(group.amount_minor) }}</span>
          </div>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.agendas.history.count') }}: {{ group.count }}
            <template v-if="group.draft_count > 0">
              · {{ t('payroll.agendas.history.draft_count') }}: {{ group.draft_count }}
            </template>
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="btnOutlineSm('neutral')" @click="toggleDetail(group)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
              {{ t('payroll.agendas.history.detail') }}
            </button>
            <button type="button" :class="btnOutlineSm('neutral')" @click="emit('open', groupPeriod(group))">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.calendar" /></svg>
              {{ t('payroll.agendas.history.open_month') }}
            </button>
          </div>
          <ul v-if="groupPeriod(group) in details" class="mt-3 space-y-1 border-t border-neutral-100 pt-3">
            <li v-if="details[groupPeriod(group)] === null" class="text-xs text-neutral-500">{{ t('common.loading') }}</li>
            <li
              v-for="input in details[groupPeriod(group)] ?? []"
              :key="input.id"
              class="flex flex-wrap items-center justify-between gap-2 text-xs"
            >
              <span>{{ input.component_name }}</span>
              <span class="font-medium tabular-nums">{{ formatMoneyMinor(input.amount_minor) }}</span>
            </li>
          </ul>
        </article>
      </div>

      <PaginationBar :page="page" :per-page="PAGE_SIZE" :total="total" embedded @update:page="goToPage" />
    </template>
  </section>
</template>
