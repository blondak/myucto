<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { formatMoney } from '@/composables/useFormat'
import type { JournalLine } from '@/api/accounting'
import { calendarYearRange } from '@/utils/accountingPeriod'
import { canPair, pairLines } from '@/utils/journalPairs'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'

/**
 * Rozpad účetního zápisu na účty (MD/DAL) jako samostatná karta.
 *
 * Vytažené ze stránky deníku, protože týž rozpad ukazuje i panel „Souvisí"
 * u protějšku — a účetní musí obojí poznat jako stejnou věc. Dvě kopie
 * markupu by se navíc dřív nebo později rozešly.
 *
 * Karta na světlém povrchu je vědomá: jako holá tabulka na šedém podkladu
 * rozbaleného řádku splývala s okolím, ačkoli je to to hlavní, kvůli čemu se
 * řádek rozbaluje.
 *
 * Řádek tabulky = SOUVZTAŽNOST, ne noha zápisu: jedna částka a účty MD a DAL
 * vedle sebe, jak to má POHODA i Money ERP. Rozpad po nohách psal tutéž částku
 * dvakrát a párování účtů nechával na oku čtenáře. Kde souvztažnost z dat
 * jednoznačně nevyplývá ({@see canPair} — obě strany mají víc nohou, zápis
 * nesedí), se vrací původní rozpad po stranách; vymýšlet dvojice by bylo horší
 * než je neukázat.
 */
const props = withDefaults(defineProps<{
  lines: JournalLine[]
  /** Menší varianta do vnořeného panelu (Souvisí), kde karta nesmí přebít hostitele. */
  dense?: boolean
  dateFrom?: string
  dateTo?: string
  contextDate?: string | null
}>(), { dense: false })

const { t } = useI18n()

const paired = computed(() => canPair(props.lines))
const pairs = computed(() => (paired.value ? pairLines(props.lines) : []))

/**
 * Sloučení buněk u nohy, která se dělí mezi víc protistran (311 proti 602+343).
 * Vypsat ji u každé dvojice znovu je šum: opakuje se ta samá věc a oko pak hledá
 * rozdíl tam, kde žádný není. Sloučená buňka navíc ukazuje strukturu zápisu —
 * jedna pohledávka rozpuštěná do tržby a daně.
 *
 * Pro každý řádek vrací, jestli se buňka té strany kreslí, a přes kolik řádků.
 */
function spansOf(side: 'debit' | 'credit') {
  return computed(() => {
    const rows = pairs.value
    return rows.map((p, i) => {
      const account = p[side]?.account_id ?? null
      if (i > 0 && (rows[i - 1][side]?.account_id ?? null) === account) {
        return { render: false, span: 1 }
      }
      let span = 1
      while (i + span < rows.length && (rows[i + span][side]?.account_id ?? null) === account) { span += 1 }
      return { render: true, span }
    })
  })
}
const debitSpans = spansOf('debit')
const creditSpans = spansOf('credit')

/** Linka nad buňkou — první řádek ji nemá, hlavička si vede vlastní. */
function rowBorder(i: number): string {
  return i > 0 ? 'border-t border-neutral-100' : ''
}

/** Součet strany MD — u vyrovnaného zápisu je shodný se stranou DAL. */
const total = computed(() =>
  props.lines.filter(l => l.side === 'debit').reduce((s, l) => s + Number(l.amount || 0), 0))

/**
 * U jediné souvztažnosti (resp. dvouřádkového zápisu po stranách) je součet jen
 * opis: částka je vidět celá a je jen jedna. Smysl dává až tam, kde se zápis
 * skládá z víc dvojic (doklad s DPH, rozpad na střediska) a součet potvrzuje,
 * že zápis sedí.
 */
const showsTotal = computed(() => (paired.value ? pairs.value.length > 1 : props.lines.length > 2))

/**
 * Účty, které v zápisu stojí na obou stranách (typicky 343.900 u převodu DPH nebo
 * 261 u převodu mezi vlastními účty). Hrubý součet u nich sčítá i to, co se proti
 * sobě ruší — 484 255,45 + 155 630,72 = 639 886,17, přestože skutečný dopad je
 * rozdíl 328 624,73. Právě ten rozdíl je u převodu DPH ta jediná zajímavá veličina
 * (závazek k úhradě), takže se dopočítá vedle.
 */
const netByAccount = computed(() => {
  const net = new Map<string, number>()
  for (const l of props.lines) {
    const code = String(l.account_code ?? '')
    const amount = Number(l.amount || 0) * (l.side === 'debit' ? 1 : -1)
    net.set(code, (net.get(code) ?? 0) + amount)
  }
  return net
})

/** Účty na obou stranách zápisu — jen ty, u kterých se něco reálně ruší. */
const twoSidedAccounts = computed(() => {
  const sides = new Map<string, Set<string>>()
  for (const l of props.lines) {
    const code = String(l.account_code ?? '')
    if (!sides.has(code)) { sides.set(code, new Set()) }
    sides.get(code)!.add(l.side)
  }
  return [...sides.entries()]
    .filter(([, s]) => s.size > 1)
    .map(([code]) => ({ code, net: netByAccount.value.get(code) ?? 0 }))
    .filter(a => Math.abs(a.net) > 0.005)
})

/** Středisko má sloupec jen tam, kde je vůbec vyplněné — jinak je to sloupec pomlček. */
const showsCostCenter = computed(() => props.lines.some(l => !!l.cost_center))

const cell = computed(() => (props.dense ? 'px-2 py-1' : 'px-3 py-2'))

function movementLink(line: JournalLine) {
  const fallback = calendarYearRange(props.contextDate)
  const from = props.dateFrom || fallback.from
  const to = props.dateTo || fallback.to
  return {
    name: 'accounting-account-statement',
    params: { accountId: line.account_id },
    query: {
      ...(from ? { from } : {}),
      ...(to ? { to } : {}),
    },
  }
}
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <!-- ── Desktop, souvztažnosti: MD účet | DAL účet | částka ──
         Na mobilu se tři sloupce s názvy účtů do šířky nevejdou, tam je z každé
         dvojice karta. -->
    <table v-if="paired" class="w-full hidden md:table" :class="dense ? 'text-xs' : 'text-sm'">
      <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
        <tr>
          <th class="text-left font-medium" :class="cell">{{ t('accounting.journal.side.debit') }}</th>
          <th class="text-left font-medium" :class="cell">{{ t('accounting.journal.side.credit') }}</th>
          <th v-if="showsCostCenter" class="text-left font-medium" :class="cell">{{ t('accounting.journal.cost_center') }}</th>
          <th class="text-right font-medium w-36" :class="cell">{{ t('accounting.journal.col_amount') }}</th>
        </tr>
      </thead>
      <!-- Dělicí linka sedí na buňkách, ne na řádku: přes sloučenou buňku by
           řádkový oddělovač vedl čáru a sloučení by bylo k ničemu. -->
      <tbody>
        <tr v-for="(p, i) in pairs" :key="p.key">
          <!-- Dělená noha se vypisuje jednou a buňka sahá přes všechny své
               protistrany — svisle na střed, ať je vidět, že patří ke všem. -->
          <td v-if="debitSpans[i].render" :rowspan="debitSpans[i].span" class="align-middle" :class="[cell, rowBorder(i)]">
            <RouterLink v-if="p.debit" :to="movementLink(p.debit)"
              class="inline-flex flex-wrap items-baseline gap-x-1.5 text-primary-600 hover:text-primary-700 hover:underline"
              :title="t('accounting.accounts.detail.statement')">
              <span class="font-mono font-medium">{{ p.debit.account_code }}</span>
              <span class="text-neutral-600">{{ p.debit.account_name }}</span>
            </RouterLink>
            <DimensionChips v-if="p.debit" class="ml-1.5" :dimensions="p.debit.dimensions" :splits="p.debit.dimension_splits" />
          </td>
          <td v-if="creditSpans[i].render" :rowspan="creditSpans[i].span" class="align-middle" :class="[cell, rowBorder(i)]">
            <RouterLink v-if="p.credit" :to="movementLink(p.credit)"
              class="inline-flex flex-wrap items-baseline gap-x-1.5 text-primary-600 hover:text-primary-700 hover:underline"
              :title="t('accounting.accounts.detail.statement')">
              <span class="font-mono font-medium">{{ p.credit.account_code }}</span>
              <span class="text-neutral-600">{{ p.credit.account_name }}</span>
            </RouterLink>
            <DimensionChips v-if="p.credit" class="ml-1.5" :dimensions="p.credit.dimensions" :splits="p.credit.dimension_splits" />
          </td>
          <td v-if="showsCostCenter" class="text-neutral-500 text-xs" :class="[cell, rowBorder(i)]">{{ p.costCenter || '—' }}</td>
          <td class="text-right font-mono font-medium text-neutral-900 whitespace-nowrap" :class="[cell, rowBorder(i)]">
            {{ formatMoney(p.amount) }}
            <div v-if="p.amountForeign != null && p.currencyCode" class="text-xs font-normal text-neutral-400">
              {{ formatMoney(p.amountForeign, p.currencyCode) }}
            </div>
          </td>
        </tr>
      </tbody>
      <tfoot v-if="showsTotal" class="bg-neutral-50 border-t-2 border-neutral-300">
        <tr class="font-semibold">
          <td :class="cell" :colspan="showsCostCenter ? 3 : 2">{{ t('accounting.journal.total') }}</td>
          <td class="text-right font-mono text-neutral-900" :class="cell">{{ formatMoney(total) }}</td>
        </tr>
        <tr v-for="a in twoSidedAccounts" :key="a.code" class="text-xs font-normal text-neutral-500">
          <td :class="cell" :colspan="showsCostCenter ? 3 : 2">{{ t('accounting.journal.net_on_account', { account: a.code }) }}</td>
          <td class="text-right font-mono" :class="cell">{{ formatMoney(Math.abs(a.net)) }}</td>
        </tr>
      </tfoot>
    </table>

    <!-- ── Desktop, rozpad po stranách ──
         Záložní pohled pro zápisy, u kterých souvztažnost z dat nevyplývá
         (obě strany víc nohou) nebo zápis nesedí. Tam je vidět syrový stav. -->
    <table v-else class="w-full hidden md:table" :class="dense ? 'text-xs' : 'text-sm'">
      <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
        <tr>
          <th class="text-left font-medium" :class="cell">{{ t('accounting.journal.account') }}</th>
          <th v-if="showsCostCenter" class="text-left font-medium" :class="cell">{{ t('accounting.journal.cost_center') }}</th>
          <th class="text-right font-medium w-36" :class="cell">{{ t('accounting.journal.side.debit') }}</th>
          <th class="text-right font-medium w-36" :class="cell">{{ t('accounting.journal.side.credit') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <tr v-for="l in lines" :key="l.id">
          <td :class="cell">
            <RouterLink :to="movementLink(l)"
              class="inline-flex flex-wrap items-baseline gap-x-1.5 text-primary-600 hover:text-primary-700 hover:underline"
              :title="t('accounting.accounts.detail.statement')">
              <span class="font-mono font-medium">{{ l.account_code }}</span>
              <span class="text-neutral-600">{{ l.account_name }}</span>
            </RouterLink>
            <DimensionChips class="ml-1.5" :dimensions="l.dimensions" :splits="l.dimension_splits" />
          </td>
          <td v-if="showsCostCenter" class="text-neutral-500 text-xs" :class="cell">{{ l.cost_center || '—' }}</td>
          <td class="text-right font-mono font-medium text-neutral-900" :class="cell">
            <template v-if="l.side === 'debit'">
              {{ formatMoney(l.amount) }}
              <div v-if="l.amount_foreign != null && l.currency_code" class="text-xs font-normal text-neutral-400">
                {{ formatMoney(l.amount_foreign, l.currency_code) }}
              </div>
            </template>
          </td>
          <td class="text-right font-mono font-medium text-neutral-900" :class="cell">
            <template v-if="l.side === 'credit'">
              {{ formatMoney(l.amount) }}
              <div v-if="l.amount_foreign != null && l.currency_code" class="text-xs font-normal text-neutral-400">
                {{ formatMoney(l.amount_foreign, l.currency_code) }}
              </div>
            </template>
          </td>
        </tr>
      </tbody>
      <tfoot v-if="showsTotal" class="bg-neutral-50 border-t-2 border-neutral-300">
        <tr class="font-semibold">
          <td :class="cell" :colspan="showsCostCenter ? 2 : 1">{{ t('accounting.journal.total') }}</td>
          <td class="text-right font-mono text-neutral-900" :class="cell" colspan="2">{{ formatMoney(total) }}</td>
        </tr>
        <tr v-for="a in twoSidedAccounts" :key="a.code" class="text-xs font-normal text-neutral-500">
          <td :class="cell" :colspan="showsCostCenter ? 2 : 1">{{ t('accounting.journal.net_on_account', { account: a.code }) }}</td>
          <td class="text-right font-mono" :class="cell" colspan="2">{{ formatMoney(Math.abs(a.net)) }}</td>
        </tr>
      </tfoot>
    </table>

    <!-- ── Mobil: souvztažnost = karta, částka jednou nahoře ── -->
    <div v-if="paired" class="md:hidden divide-y divide-neutral-100">
      <div v-for="p in pairs" :key="`m-${p.key}`" class="px-3 py-2 space-y-1.5">
        <div class="flex items-baseline justify-between gap-2">
          <span class="text-[10px] uppercase tracking-wide font-medium text-neutral-400">{{ t('accounting.journal.col_amount') }}</span>
          <div class="text-right">
            <div class="font-mono text-sm font-semibold text-neutral-900">{{ formatMoney(p.amount) }}</div>
            <div v-if="p.amountForeign != null && p.currencyCode" class="text-xs text-neutral-400 font-mono">
              {{ formatMoney(p.amountForeign, p.currencyCode) }}
            </div>
          </div>
        </div>
        <div v-for="leg in [{ side: 'debit', line: p.debit }, { side: 'credit', line: p.credit }]" :key="leg.side">
          <RouterLink v-if="leg.line" :to="movementLink(leg.line)"
            class="flex items-baseline gap-2 text-primary-600 hover:text-primary-700"
            :title="t('accounting.accounts.detail.statement')">
            <span class="text-[10px] uppercase tracking-wide font-medium text-neutral-400 w-7 shrink-0">
              {{ leg.side === 'debit' ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit') }}
            </span>
            <span class="min-w-0">
              <span class="font-mono font-medium text-sm">{{ leg.line.account_code }}</span>
              <span class="block text-xs text-neutral-600">{{ leg.line.account_name }}</span>
            </span>
          </RouterLink>
          <DimensionChips v-if="leg.line" class="mt-0.5 ml-9 flex" :dimensions="leg.line.dimensions" :splits="leg.line.dimension_splits" />
        </div>
        <div v-if="p.costCenter" class="text-xs text-neutral-500">
          {{ t('accounting.journal.cost_center') }}: {{ p.costCenter }}
        </div>
      </div>
      <div v-if="showsTotal" class="px-3 py-2 bg-neutral-50 space-y-1">
        <div class="flex justify-between text-sm font-semibold">
          <span>{{ t('accounting.journal.total') }}</span>
          <span class="font-mono">{{ formatMoney(total) }}</span>
        </div>
        <div v-for="a in twoSidedAccounts" :key="`m-net-${a.code}`" class="flex justify-between text-xs text-neutral-500">
          <span>{{ t('accounting.journal.net_on_account', { account: a.code }) }}</span>
          <span class="font-mono">{{ formatMoney(Math.abs(a.net)) }}</span>
        </div>
      </div>
    </div>

    <!-- ── Mobil, rozpad po stranách: řádek = karta, strana je štítek u částky ── -->
    <div v-else class="md:hidden divide-y divide-neutral-100">
      <div v-for="l in lines" :key="`m-${l.id}`" class="px-3 py-2 space-y-1">
        <div class="flex items-baseline justify-between gap-2">
          <RouterLink :to="movementLink(l)"
            class="min-w-0 text-primary-600 hover:text-primary-700 hover:underline"
            :title="t('accounting.accounts.detail.statement')">
            <span class="font-mono font-medium text-sm">{{ l.account_code }}</span>
            <span class="block text-xs text-neutral-600">{{ l.account_name }}</span>
          </RouterLink>
          <div class="text-right shrink-0">
            <div class="text-[10px] uppercase tracking-wide font-medium"
              :class="l.side === 'debit' ? 'text-neutral-500' : 'text-neutral-400'">
              {{ l.side === 'debit' ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit') }}
            </div>
            <div class="font-mono text-sm font-medium text-neutral-900">{{ formatMoney(l.amount) }}</div>
            <div v-if="l.amount_foreign != null && l.currency_code" class="text-xs text-neutral-400 font-mono">
              {{ formatMoney(l.amount_foreign, l.currency_code) }}
            </div>
          </div>
        </div>
        <div v-if="l.cost_center" class="text-xs text-neutral-500">
          {{ t('accounting.journal.cost_center') }}: {{ l.cost_center }}
        </div>
        <DimensionChips class="flex" :dimensions="l.dimensions" :splits="l.dimension_splits" />
      </div>
      <div v-if="showsTotal" class="px-3 py-2 bg-neutral-50 space-y-1">
        <div class="flex justify-between text-sm font-semibold">
          <span>{{ t('accounting.journal.total') }}</span>
          <span class="font-mono">{{ formatMoney(total) }}</span>
        </div>
        <div v-for="a in twoSidedAccounts" :key="`m-net-${a.code}`" class="flex justify-between text-xs text-neutral-500">
          <span>{{ t('accounting.journal.net_on_account', { account: a.code }) }}</span>
          <span class="font-mono">{{ formatMoney(Math.abs(a.net)) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
