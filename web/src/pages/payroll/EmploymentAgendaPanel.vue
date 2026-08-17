<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollAgendaKey, type PayrollAgendaSummaryItem } from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { payrollAgendas } from './payrollAgendaLinks'

/**
 * Navazující agendy pracovního vztahu — rozcestník a souhrn v jednom.
 *
 * Why: karta zaměstnance uměla vztah založit, upravit a ukončit, ale o tom, co
 * se k němu dál pořizuje (docházka, nepřítomnosti, cesty, srážky, exekuce,
 * dokumenty), mlčela. Uživatel proto odešel do menu, otevřel agendu a v ní
 * člověka hledal znovu — a jestli v agendě vůbec něco je, se dozvěděl až tam.
 *
 * Načítá se AŽ tady, tedy jen pro rozbalený vztah otevřené osoby. Seznam lidí
 * tenhle dotaz nepouští vůbec; jinak by přehled o padesáti zaměstnancích udělal
 * padesát požadavků na data, na která se nikdo nedívá.
 */
const props = defineProps<{
  employmentId: number
  employeeId: number
}>()

const { t } = useI18n()
const auth = useAuthStore()
const loading = ref(true)
/*
 * Selhalo načtení? Pak o agendách nevíme NIC — a to je něco jiného než „nic
 * v nich není". Bez tohohle příznaku by karta tvrdila prázdno, které lže.
 */
const failed = ref(false)
const items = ref<PayrollAgendaSummaryItem[]>([])

/**
 * Tlačítka vede katalog, ne souhrn: do agendy se dá jít i tehdy, když je
 * prázdná — právě proto tam ten člověk jde, aby do ní něco zadal. Odfiltruje se
 * jen to, na co uživatel nemá právo; jinak by tlačítko svítilo a routa ho
 * zahodila na homepage.
 */
const visibleAgendas = computed(() =>
  payrollAgendas.filter(agenda => auth.canRead(agenda.permission)))

const summaryByKey = computed(() => {
  const map = new Map<PayrollAgendaKey, PayrollAgendaSummaryItem>()
  for (const item of items.value) map.set(item.key, item)
  return map
})

const actions = computed<ActionItem[]>(() => visibleAgendas.value.map(agenda => ({
  key: `agenda-${agenda.key}`,
  label: t(`payroll.agendas.items.${agenda.key}`),
  icon: agenda.icon,
  tier: 'secondary',
  variant: agenda.variant,
  to: agenda.to(props.employmentId, props.employeeId),
} satisfies ActionItem)))

/**
 * Vypisují se jen agendy, ve kterých něco je.
 *
 * Rozhodnutí: prázdné agendy se do seznamu NEDÁVAJÍ, ale jmenují se v jedné
 * nenápadné větě pod ním. Deset řádků „zatím nic" je šum, ve kterém zanikne ten
 * jediný řádek, na kterém záleží; naopak úplné zmizení agendy by nešlo odlišit
 * od „na tohle nemáš právo" nebo „tohle jsme se nezeptali". Věta obojí řeší:
 * co v ní je, opravdu prázdné je.
 */
const filled = computed(() =>
  visibleAgendas.value
    .map(agenda => ({ agenda, summary: summaryByKey.value.get(agenda.key) }))
    .filter(row => (row.summary?.count ?? 0) > 0))

const emptyLabels = computed(() =>
  visibleAgendas.value
    .filter(agenda => summaryByKey.value.has(agenda.key)
      && (summaryByKey.value.get(agenda.key)?.count ?? 0) === 0)
    .map(agenda => t(`payroll.agendas.items.${agenda.key}`)))

function detailOf(summary: PayrollAgendaSummaryItem): string {
  const parts = [t('payroll.agendas.count', { count: summary.count }, summary.count)]
  if (summary.last_on !== null) {
    parts.push(t('payroll.agendas.last_on', { date: formatDate(summary.last_on) }))
  }
  if (summary.amount_minor !== null) {
    parts.push(formatMoneyMinor(summary.amount_minor))
  }
  return parts.join(' · ')
}

async function load() {
  loading.value = true
  failed.value = false
  try {
    const summary = await payrollApi.employmentAgendaSummary(props.employmentId)
    items.value = summary.agendas
  } catch {
    failed.value = true
    items.value = []
  } finally {
    loading.value = false
  }
}

watch(() => props.employmentId, load)
onMounted(load)
</script>

<template>
  <section class="mt-4 rounded-lg border border-neutral-200 bg-surface p-3" data-test="employment-agendas">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.agendas.title') }}</h4>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.agendas.subtitle') }}</p>
      </div>
      <ActionBar v-if="actions.length > 0" :actions="actions" />
    </div>

    <div v-if="loading" class="mt-3 space-y-2">
      <div v-for="index in 3" :key="index" class="h-8 animate-pulse rounded-md bg-neutral-100" />
    </div>

    <p
      v-else-if="failed"
      class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
      data-test="employment-agendas-failed"
    >
      {{ t('payroll.agendas.load_failed') }}
    </p>

    <template v-else>
      <ul v-if="filled.length > 0" class="mt-3 space-y-1.5" data-test="employment-agenda-summary">
        <li
          v-for="row in filled"
          :key="row.agenda.key"
          class="flex flex-wrap items-baseline justify-between gap-2 rounded-md bg-neutral-50 px-3 py-2 text-xs"
          :data-test="`employment-agenda-${row.agenda.key}`"
        >
          <RouterLink
            :to="row.agenda.to(props.employmentId, props.employeeId)"
            class="font-medium text-neutral-800 hover:text-payroll-700 hover:underline"
          >
            {{ t(`payroll.agendas.items.${row.agenda.key}`) }}
          </RouterLink>
          <span class="text-neutral-600">{{ detailOf(row.summary!) }}</span>
        </li>
      </ul>

      <p v-if="emptyLabels.length > 0" class="mt-3 text-xs text-neutral-500" data-test="employment-agendas-empty">
        {{ t('payroll.agendas.nothing_yet', { agendas: emptyLabels.join(', ') }) }}
      </p>
    </template>
  </section>
</template>
