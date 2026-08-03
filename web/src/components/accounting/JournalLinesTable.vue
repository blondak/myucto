<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import type { JournalLine } from '@/api/accounting'

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
 */
const props = withDefaults(defineProps<{
  lines: JournalLine[]
  /** Menší varianta do vnořeného panelu (Souvisí), kde karta nesmí přebít hostitele. */
  dense?: boolean
}>(), { dense: false })

const { t } = useI18n()

/** Součet strany MD — u vyrovnaného zápisu je shodný se stranou DAL. */
const total = computed(() =>
  props.lines.filter(l => l.side === 'debit').reduce((s, l) => s + Number(l.amount || 0), 0))

/**
 * U dvouřádkového zápisu je součet jen opis: obě strany jsou vidět a jsou si
 * rovné. Smysl dává až tam, kde se strana skládá z víc řádků (doklad s DPH,
 * rozpad na střediska) a součet potvrzuje, že zápis sedí.
 */
const showsTotal = computed(() => props.lines.length > 2)

const cell = computed(() => (props.dense ? 'px-2 py-1' : 'px-3 py-2'))
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <table class="w-full" :class="dense ? 'text-xs' : 'text-sm'">
      <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
        <tr>
          <th class="text-left font-medium" :class="cell">{{ t('accounting.journal.account') }}</th>
          <th class="text-left font-medium" :class="cell">{{ t('accounting.journal.cost_center') }}</th>
          <th class="text-right font-medium w-36" :class="cell">{{ t('accounting.journal.side.debit') }}</th>
          <th class="text-right font-medium w-36" :class="cell">{{ t('accounting.journal.side.credit') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <tr v-for="l in lines" :key="l.id">
          <td :class="cell">
            <span class="font-mono font-medium text-neutral-900">{{ l.account_code }}</span>
            <span class="text-neutral-600 ml-1.5">{{ l.account_name }}</span>
          </td>
          <td class="text-neutral-500 text-xs" :class="cell">{{ l.cost_center || '—' }}</td>
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
          <td :class="cell" colspan="2">{{ t('accounting.journal.total') }}</td>
          <td class="text-right font-mono text-neutral-900" :class="cell" colspan="2">{{ formatMoney(total) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</template>
