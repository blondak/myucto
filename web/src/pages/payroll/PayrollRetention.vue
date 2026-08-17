<script setup lang="ts">
/**
 * Retenční lhůty mzdové agendy — čtecí pohled na `PayrollRetentionCatalog`.
 *
 * Obrazovka odpovídá na čtyři otázky, které katalog dosud uměl jen v kódu:
 * jak dlouho se která skupina mzdových dat drží, ODKDY lhůta běží, KDE to
 * stojí psané (konkrétní ustanovení, ne jen číslo zákona) a KDY se to naposledy
 * ověřilo proti znění předpisu.
 *
 * Nejdůležitější sdělení stránky není číslo, ale PŮVOD lhůty. Zdravotní
 * pojištění drží deset let, které v žádné sbírce nestojí (v zák. č. 592/1992 Sb.
 * uschovávací lhůta prostě není) — je to rozhodnutí aplikace, ne právo. Spis
 * k exekučním srážkám lhůtu nemá vůbec a je to doložené NEGATIVNĚ, ne
 * nedohledané. Obojí je rozdíl mezi „takhle to káže zákon" a „takhle jsme se
 * rozhodli", takže se ukazuje jako sloupec a jako dlaždice nad tabulkou,
 * ne jako poznámka schovaná v rozbaleném detailu.
 *
 * Nic se odsud nemaže a ani nenastavuje. Uplynulá lhůta je konec povinnosti
 * uchovávat, ne příkaz ke skartaci; výmaz je samostatný návrh ke schválení
 * (oprávnění `payroll.erasure`) a stránka o něm jen referuje.
 *
 * Filtrování i řazení běží na klientovi nad celým katalogem (deset kategorií),
 * takže se nestránkuje vůbec — půlka na klientovi a půlka na serveru by
 * schovala řádky, které filtr našel.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollRetentionApi,
  type PayrollRetentionCategory,
  type PayrollRetentionPolicy,
  type PayrollRetentionAssessment,
  type PayrollRetentionBlock,
  type RetentionOrigin,
} from '@/api/payrollRetention'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t, locale } = useI18n()
const auth = useAuthStore()

const categories = ref<PayrollRetentionCategory[]>([])
const policies = ref<PayrollRetentionPolicy[]>([])
const loading = ref(true)
/** Katalog se nenačetl — o obsahu nevíme NIC, takže se nesmí ukázat prázdno. */
const failed = ref(false)
const error = ref('')

const assessment = ref<PayrollRetentionAssessment | null>(null)
const assessmentLoading = ref(true)
const assessmentFailed = ref(false)
const assessmentError = ref('')
const asOf = ref(new Date().toISOString().slice(0, 10))

const filters = reactive({ q: '', origin: '' as '' | RetentionOrigin })

const ORIGINS: RetentionOrigin[] = ['statute', 'house_policy', 'none']

const ORIGIN_BADGE: Record<RetentionOrigin, string> = {
  statute: 'bg-success-100 text-success-700',
  house_policy: 'bg-warning-100 text-warning-700',
  none: 'bg-neutral-200 text-neutral-600',
}
const ORIGIN_TILE: Record<RetentionOrigin, string> = {
  statute: 'border-success-500/40 bg-success-50',
  house_policy: 'border-warning-500/40 bg-warning-50',
  none: 'border-neutral-300 bg-neutral-50',
}
const STATUS_BADGE: Record<string, string> = {
  statute_verified: 'bg-success-100 text-success-700',
  statute_silent: 'bg-primary-100 text-primary-700',
  external_unverified: 'bg-warning-100 text-warning-700',
  undetermined: 'bg-neutral-200 text-neutral-600',
}

const COLUMNS: ColumnDef[] = [
  { key: 'label', labelKey: 'payroll.retention.col.category', required: true, sortable: true },
  { key: 'years', labelKey: 'payroll.retention.col.years', required: true, sortable: true },
  { key: 'basis', labelKey: 'payroll.retention.col.basis' },
  { key: 'origin', labelKey: 'payroll.retention.col.origin', sortable: true },
  { key: 'source', labelKey: 'payroll.retention.col.source' },
  { key: 'status', labelKey: 'payroll.retention.col.status' },
  { key: 'verified', labelKey: 'payroll.retention.col.verified_on' },
  { key: 'erasure', labelKey: 'payroll.retention.col.erasure' },
  { key: 'tables', labelKey: 'payroll.retention.col.tables', defaultHidden: true },
  { key: 'accounting', labelKey: 'payroll.retention.col.accounting', defaultHidden: true },
]
const tbl = useTablePrefs('payroll-retention', COLUMNS)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await payrollRetentionApi.overview()
    categories.value = data.categories
    policies.value = data.policies
    failed.value = false
  } catch (e) {
    // Kolekce se ZÁMĚRNĚ nevynuluje — poslední načtený katalog je pořád lepší
    // informace než prázdná tabulka, která by tvrdila, že žádné lhůty nejsou.
    error.value = apiErrorMessage(e)
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadAssessment() {
  assessmentLoading.value = true
  assessmentError.value = ''
  try {
    assessment.value = await payrollRetentionApi.assessment(asOf.value)
    assessmentFailed.value = false
  } catch (e) {
    assessmentError.value = apiErrorMessage(e)
    assessmentFailed.value = true
  } finally {
    assessmentLoading.value = false
  }
}

function reloadAll() {
  void load()
  void loadAssessment()
}

const policyByCategory = computed<Record<string, PayrollRetentionPolicy>>(() => {
  const out: Record<string, PayrollRetentionPolicy> = {}
  for (const p of policies.value) out[p.category] = p
  return out
})

const originCounts = computed<Record<RetentionOrigin, number>>(() => {
  const out: Record<RetentionOrigin, number> = { statute: 0, house_policy: 0, none: 0 }
  for (const c of categories.value) out[c.origin]++
  return out
})

const verifiedOn = computed<string | null>(() => {
  for (const c of categories.value) if (c.verified_on) return c.verified_on
  return null
})

const filtered = computed<PayrollRetentionCategory[]>(() => {
  const q = filters.q.trim().toLocaleLowerCase(locale.value === 'en' ? 'en' : 'cs')
  return categories.value.filter(c => {
    if (filters.origin && c.origin !== filters.origin) return false
    if (!q) return true
    return [c.label, c.act, c.section ?? '', c.source, c.note, ...c.employee_tables, ...c.employment_tables]
      .join(' ')
      .toLocaleLowerCase(locale.value === 'en' ? 'en' : 'cs')
      .includes(q)
  })
})

const sorted = computed<PayrollRetentionCategory[]>(() => {
  const s = tbl.sort.value
  if (!s) return filtered.value
  const dir = s.dir === 'desc' ? -1 : 1
  return [...filtered.value].sort((a, b) => {
    if (s.key === 'years') {
      // Neurčená lhůta není nula — řadí se vždy na konec, ať se třídí jakkoliv.
      const av = a.effective_years ?? Number.POSITIVE_INFINITY
      const bv = b.effective_years ?? Number.POSITIVE_INFINITY
      return (av - bv) * dir
    }
    const av = s.key === 'origin' ? a.origin : a.label
    const bv = s.key === 'origin' ? b.origin : b.label
    return av.localeCompare(bv, locale.value === 'en' ? 'en' : 'cs') * dir
  })
})

function resetFilters() {
  filters.q = ''
  filters.origin = ''
}

function toggleOrigin(origin: RetentionOrigin) {
  filters.origin = filters.origin === origin ? '' : origin
}

const expanded = ref<string | null>(null)
function toggleRow(category: string) {
  expanded.value = expanded.value === category ? null : category
}

/**
 * Lhůta po započtení odchylky firmy — číslo, podle kterého se opravdu počítá.
 *
 * Tvar se skládá ručně ze tří klíčů, ne přes vestavěné množné číslo vue-i18n:
 * to má napevno anglický dvoutvar, takže by ze stejnopisů ELDP udělalo „3 let".
 */
function yearsLabel(c: PayrollRetentionCategory): string {
  const years = c.effective_years
  if (years === null) return t('payroll.retention.years_undetermined')
  const form = years === 1 ? 'one' : years >= 2 && years <= 4 ? 'few' : 'many'
  return t(`payroll.retention.years_count_${form}`, { years })
}

/** Odchylka firmy — prodloužení zákonné lhůty, nebo lhůta dodaná tam, kde zákon mlčí. */
function deviation(c: PayrollRetentionCategory): string | null {
  const p = policyByCategory.value[c.category]
  if (!p) return null
  return p.override_years !== null
    ? t('payroll.retention.deviation_override', { years: p.override_years })
    : t('payroll.retention.deviation_extra', { years: p.extra_years })
}

function tablesOf(c: PayrollRetentionCategory): string[] {
  return [...c.employee_tables, ...c.employment_tables]
}

const BLOCKS: PayrollRetentionBlock[] = [
  'within_retention',
  'legal_hold',
  'undetermined_retention',
  'no_retention_basis',
  'already_anonymized',
]

const blockCounts = computed<Record<string, number>>(() => {
  const out: Record<string, number> = {}
  for (const b of BLOCKS) out[b] = 0
  for (const i of assessment.value?.items ?? []) {
    if (i.blocked_by) out[i.blocked_by] = (out[i.blocked_by] ?? 0) + 1
  }
  return out
})

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'primary',
    variant: 'primary',
    disabled: loading.value,
    loading: loading.value,
    run: reloadAll,
  },
  {
    key: 'people',
    label: t('nav.payroll_people'),
    icon: 'user',
    tier: 'secondary',
    variant: 'neutral',
    show: auth.canRead('payroll'),
    to: '/payroll/people',
  },
  {
    key: 'accounting',
    label: t('payroll.retention.action_accounting_retention'),
    icon: 'archive',
    tier: 'overflow',
    variant: 'neutral',
    show: auth.canRead('accounting'),
    title: t('payroll.retention.action_accounting_retention_hint'),
    to: '/accounting/retention',
  },
])

onMounted(reloadAll)
</script>

<template>
  <div class="max-w-6xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('payroll.retention.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('payroll.retention.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('payroll.retention.explainer_title') }}</p>
      <p>{{ t('payroll.retention.explainer_body') }}</p>
      <p v-if="verifiedOn" class="mt-1.5 text-xs text-neutral-600">
        {{ t('payroll.retention.verified_stamp', { date: fmtDate(verifiedOn) }) }}
      </p>
    </div>

    <!-- Původ lhůty jako první věc na stránce: rozdíl mezi zákonem a rozhodnutím
         aplikace nesmí být schovaný v detailu řádku. Dlaždice zároveň filtrují. -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
      <button
        v-for="o in ORIGINS"
        :key="o"
        type="button"
        :aria-pressed="filters.origin === o"
        :data-test="`origin-tile-${o}`"
        class="cursor-pointer text-left border rounded-lg p-3 transition-colors hover:brightness-[0.98]"
        :class="[ORIGIN_TILE[o], filters.origin === o ? 'ring-2 ring-primary-400' : '']"
        @click="toggleOrigin(o)"
      >
        <div class="text-2xl font-semibold leading-tight">{{ originCounts[o] }}</div>
        <div class="text-sm font-medium">{{ t(`payroll.retention.origin.${o}`) }}</div>
        <div class="text-xs text-neutral-600 mt-0.5">{{ t(`payroll.retention.origin_hint.${o}`) }}</div>
      </button>
    </div>

    <!-- Filtr + tabulkové vybavení. Stránkování tu není vůbec — katalog má deset
         kategorií a filtr i řazení běží na klientovi nad celou sadou. -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1" for="retention-q">
            {{ t('payroll.retention.filter_q') }}
          </label>
          <input
            id="retention-q"
            v-model="filters.q"
            type="text"
            :placeholder="t('payroll.retention.filter_q_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" for="retention-origin">
            {{ t('payroll.retention.col.origin') }}
          </label>
          <select
            id="retention-origin"
            v-model="filters.origin"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          >
            <option value="">{{ t('common.all') }}</option>
            <option v-for="o in ORIGINS" :key="o" :value="o">{{ t(`payroll.retention.origin.${o}`) }}</option>
          </select>
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button
          type="button"
          class="cursor-pointer whitespace-nowrap text-xs text-neutral-500 hover:text-neutral-700"
          @click="resetFilters"
        >{{ t('payroll.retention.reset_filters') }}</button>
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- Selhání se NIKDY nekreslí jako prázdný katalog: „žádné lhůty" a „lhůty
         se nenačetly" jsou dva úplně jiné stavy a záměna prvního za druhý by
         tvrdila, že se nic nedrží. -->
    <EmptyState
      v-else-if="failed && categories.length === 0"
      boxed
      variant="failed"
      accent="danger"
      :title="t('payroll.retention.load_failed')"
      :message="error"
      :cta="t('common.refresh')"
      @action="load"
    />

    <EmptyState
      v-else-if="sorted.length === 0"
      boxed
      variant="filtered"
      accent="neutral"
      icon="funnel"
      :title="t('payroll.retention.no_match')"
      :message="t('payroll.retention.no_match_hint')"
      :cta="t('payroll.retention.reset_filters')"
      @action="resetFilters"
    />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="failed" class="px-3 py-2 bg-danger-50 border-b border-danger-500/40 text-xs text-danger-600">
        {{ t('payroll.retention.stale_warning', { error }) }}
      </div>

      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50">
            <tr>
              <SortableTh
                v-if="tbl.isVisible('label')"
                :label="t('payroll.retention.col.category')"
                sort-key="label" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <SortableTh
                v-if="tbl.isVisible('years')"
                :label="t('payroll.retention.col.years')"
                sort-key="years" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <th v-if="tbl.isVisible('basis')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.basis') }}</th>
              <SortableTh
                v-if="tbl.isVisible('origin')"
                :label="t('payroll.retention.col.origin')"
                sort-key="origin" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <th v-if="tbl.isVisible('source')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.source') }}</th>
              <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.status') }}</th>
              <th v-if="tbl.isVisible('verified')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.verified_on') }}</th>
              <th v-if="tbl.isVisible('erasure')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.erasure') }}</th>
              <th v-if="tbl.isVisible('tables')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.tables') }}</th>
              <th v-if="tbl.isVisible('accounting')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.accounting') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="c in sorted" :key="c.category">
              <tr class="cursor-pointer hover:bg-neutral-50" :data-test="`retention-row-${c.category}`" @click="toggleRow(c.category)">
                <td v-if="tbl.isVisible('label')" class="px-3 py-2">
                  <span class="font-medium">{{ c.label }}</span>
                  <span v-if="c.closing_agenda"
                        class="ml-1.5 inline-block text-[10px] font-bold px-1.5 py-px rounded bg-neutral-200 text-neutral-600">
                    {{ t('payroll.retention.closing_agenda') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('years')" class="px-3 py-2 whitespace-nowrap" :data-test="`retention-years-${c.category}`">
                  <span :class="c.effective_years === null ? 'text-neutral-400 italic' : 'font-mono font-semibold'">
                    {{ yearsLabel(c) }}
                  </span>
                  <div v-if="deviation(c)" class="text-[11px] text-warning-700">{{ deviation(c) }}</div>
                </td>
                <td v-if="tbl.isVisible('basis')" class="px-3 py-2 text-xs text-neutral-600">
                  {{ t(`payroll.retention.basis.${c.basis}`) }}
                </td>
                <td v-if="tbl.isVisible('origin')" class="px-3 py-2" :data-test="`retention-origin-${c.category}`">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                        :class="ORIGIN_BADGE[c.origin]">
                    {{ t(`payroll.retention.origin.${c.origin}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('source')" class="px-3 py-2 text-xs" :data-test="`retention-source-${c.category}`">
                  <span :class="c.statutory ? '' : 'text-warning-700'">{{ c.source }}</span>
                </td>
                <td v-if="tbl.isVisible('status')" class="px-3 py-2">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                        :class="STATUS_BADGE[c.source_status] ?? 'bg-neutral-200 text-neutral-600'">
                    {{ t(`payroll.retention.source_status.${c.source_status}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('verified')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                  {{ fmtDate(c.verified_on) }}
                </td>
                <td v-if="tbl.isVisible('erasure')" class="px-3 py-2 text-xs" :data-test="`retention-erasure-${c.category}`">
                  <span :class="c.determined ? 'text-neutral-600' : 'text-neutral-400'">
                    {{ c.determined ? t('payroll.retention.erasure_proposed') : t('payroll.retention.erasure_never') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('tables')" class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">
                  {{ t('payroll.retention.tables_count', { count: tablesOf(c).length }) }}
                </td>
                <td v-if="tbl.isVisible('accounting')" class="px-3 py-2 text-xs">
                  {{ c.accounting_relevant ? t('common.yes') : t('common.no') }}
                </td>
              </tr>
              <tr v-if="expanded === c.category">
                <td :colspan="10" :data-test="`retention-detail-${c.category}`" class="px-4 py-3 bg-neutral-50/60 text-xs text-neutral-700 space-y-2">
                  <div>
                    <span class="font-semibold">{{ t('payroll.retention.detail_act') }}:</span> {{ c.act }}
                  </div>
                  <div v-if="c.section">
                    <span class="font-semibold">{{ t('payroll.retention.detail_section') }}:</span> {{ c.section }}
                  </div>
                  <div v-if="c.amendment">
                    <span class="font-semibold">{{ t('payroll.retention.detail_amendment') }}:</span> {{ c.amendment }}
                  </div>
                  <div v-if="c.alternative_basis">
                    <span class="font-semibold">{{ t('payroll.retention.detail_alternative_basis') }}:</span>
                    {{ t(`payroll.retention.basis.${c.alternative_basis}`) }}
                    <span class="text-neutral-500">— {{ t('payroll.retention.detail_alternative_basis_hint') }}</span>
                  </div>
                  <div v-if="policyByCategory[c.category]">
                    <span class="font-semibold">{{ t('payroll.retention.detail_policy') }}:</span>
                    {{ policyByCategory[c.category].reason }}
                  </div>
                  <div>
                    <span class="font-semibold">{{ t('payroll.retention.detail_tables') }}:</span>
                    <span v-if="tablesOf(c).length === 0" class="text-neutral-500"> {{ t('payroll.retention.detail_no_tables') }}</span>
                    <span v-else class="font-mono"> {{ tablesOf(c).join(', ') }}</span>
                  </div>
                  <p class="text-neutral-600 whitespace-pre-line">{{ c.note }}</p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobil: karty. Původ a lhůta jsou hlavní sdělení, proto stojí nahoře
           a ne za vodorovným rolováním. -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="c in sorted" :key="c.category" class="p-3" @click="toggleRow(c.category)">
          <div class="flex items-start justify-between gap-2 flex-wrap">
            <div class="font-medium text-sm">{{ c.label }}</div>
            <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                  :class="ORIGIN_BADGE[c.origin]">
              {{ t(`payroll.retention.origin.${c.origin}`) }}
            </span>
          </div>
          <div class="mt-1 text-sm" :class="c.effective_years === null ? 'text-neutral-400 italic' : 'font-mono font-semibold'">
            {{ yearsLabel(c) }}
          </div>
          <div class="text-xs text-neutral-600">{{ t(`payroll.retention.basis.${c.basis}`) }}</div>
          <div class="text-xs mt-1" :class="c.statutory ? 'text-neutral-600' : 'text-warning-700'">{{ c.source }}</div>
          <div v-if="expanded === c.category" class="mt-2 text-xs text-neutral-600 space-y-1">
            <div v-if="c.amendment">{{ c.amendment }}</div>
            <div class="font-mono break-all">{{ tablesOf(c).join(', ') || t('payroll.retention.detail_no_tables') }}</div>
            <p class="whitespace-pre-line">{{ c.note }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Návaznost na výmaz: podle lhůt se nevratně maže, takže obrazovka musí
         ukázat i to, co z nich k dnešnímu dni plyne — a hlavně PROČ se osoba
         nenavrhla. Návrh, který někoho mlčky vynechá, se nedá zkontrolovat. -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
        <h2 class="text-sm font-semibold">{{ t('payroll.retention.erasure_title') }}</h2>
        <label class="flex items-center gap-1.5 text-xs text-neutral-500">
          {{ t('payroll.retention.as_of') }}
          <input
            v-model="asOf"
            type="date"
            class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
            @change="loadAssessment"
          />
        </label>
      </div>

      <div v-if="assessmentLoading" class="p-6 text-center text-neutral-400 text-sm">{{ t('common.loading') }}</div>

      <EmptyState
        v-else-if="assessmentFailed"
        dense
        variant="failed"
        accent="danger"
        :title="t('payroll.retention.assessment_failed')"
        :message="assessmentError"
        :cta="t('common.refresh')"
        @action="loadAssessment"
      />

      <EmptyState
        v-else-if="!assessment || assessment.items.length === 0"
        dense
        accent="neutral"
        icon="user"
        :title="t('payroll.retention.assessment_empty')"
        :message="t('payroll.retention.assessment_empty_hint')"
      />

      <div v-else class="p-4 space-y-3">
        <div class="flex items-baseline gap-2 flex-wrap">
          <span class="text-2xl font-semibold" :class="assessment.proposable > 0 ? 'text-warning-700' : 'text-neutral-500'">
            {{ assessment.proposable }}
          </span>
          <span class="text-sm text-neutral-600">
            {{ t('payroll.retention.proposable_of', { total: assessment.items.length }) }}
          </span>
        </div>
        <p class="text-xs text-neutral-500">{{ t('payroll.retention.erasure_hint') }}</p>

        <ul class="text-xs divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="b in BLOCKS" :key="b" :data-test="`retention-block-${b}`" class="flex items-start justify-between gap-3 px-3 py-1.5">
            <span>
              <span class="font-medium">{{ t(`payroll.retention.block.${b}`) }}</span>
              <span class="block text-neutral-500">{{ t(`payroll.retention.block_hint.${b}`) }}</span>
            </span>
            <span class="font-mono shrink-0" :data-test="`retention-block-count-${b}`" :class="blockCounts[b] ? '' : 'text-neutral-400'">{{ blockCounts[b] }}</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
