<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DimensionCashFlowGroup, DimensionCashFlowReport } from '@/api/dimensions'
import { formatMoney } from '@/composables/useFormat'

/**
 * Peněžní tok nepřímou metodou: výsledek + nepeněžní operace + změna pracovního
 * kapitálu = provozní tok, dále investiční a finanční tok. Skupiny se rozbalí na
 * syntetické účty. Pod výkazem kontrola proti skutečnému pohybu na peněžních
 * účtech ve stejném výběru řádků; rozdíl u dimenze jsou peníze bez její hodnoty.
 */
const props = defineProps<{
  report: DimensionCashFlowReport
}>()

const { t } = useI18n()
const expanded = ref<Set<string>>(new Set())
const hasAmount = (value: number) => Math.round(Math.abs(value) * 100) !== 0

type GroupKey = 'non_cash' | 'working_capital' | 'investing' | 'financing'

interface Line {
  key: string
  label: string
  amount: number
  strong: boolean
  group?: DimensionCashFlowGroup
}

const lines = computed<Line[]>(() => {
  const r = props.report
  const group = (key: GroupKey, label: string, strong: boolean): Line => ({ key, label, amount: r[key].total, strong, group: r[key] })
  return [
    { key: 'profit', label: t('dimensions.cf_profit'), amount: r.profit, strong: false },
    group('non_cash', t('dimensions.cf_non_cash'), false),
    group('working_capital', t('dimensions.cf_working_capital'), false),
    { key: 'operating', label: t('dimensions.cf_operating'), amount: r.operating, strong: true },
    group('investing', t('dimensions.cf_investing'), true),
    group('financing', t('dimensions.cf_financing'), true),
    { key: 'net', label: t('dimensions.cf_net'), amount: r.net_cash_flow, strong: true },
  ].filter(line => hasAmount(line.amount) || (line.group?.accounts.some(account => hasAmount(account.amount)) ?? false))
})

function toggle(key: string) {
  const next = new Set(expanded.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  expanded.value = next
}

function money(v: number) {
  return formatMoney(v, 'CZK')
}
</script>

<template>
  <div class="space-y-3">
    <div v-if="lines.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6 text-sm text-neutral-500" data-test="cash-flow-empty">{{ t('dimensions.cf_no_activity') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm" data-test="cash-flow-table">
        <tbody class="divide-y divide-neutral-100">
          <template v-for="line in lines" :key="line.key">
            <tr :class="[line.strong ? 'font-semibold bg-neutral-50/60' : '', line.group?.accounts.some(account => hasAmount(account.amount)) ? 'cursor-pointer hover:bg-neutral-50' : '']"
                :data-test="`cf-${line.key}`"
                @click="line.group?.accounts.some(account => hasAmount(account.amount)) ? toggle(line.key) : undefined">
              <td class="px-4 py-2">
                <span v-if="line.group?.accounts.some(account => hasAmount(account.amount))" class="inline-block mr-1 text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': expanded.has(line.key) }">▸</span>
                {{ line.label }}
              </td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :class="line.amount < 0 ? 'text-danger-600' : line.amount > 0 ? 'text-success-700' : ''">{{ money(line.amount) }}</td>
            </tr>
            <template v-if="line.group && expanded.has(line.key)">
              <tr v-for="acc in line.group.accounts.filter(account => hasAmount(account.amount))" :key="`${line.key}-${acc.account_code}`" class="text-neutral-600" data-test="cf-account">
                <td class="px-4 py-1.5 pl-10">
                  <span class="font-mono text-xs text-neutral-500 mr-1">{{ acc.account_code }}</span>{{ acc.name }}
                </td>
                <td class="px-4 py-1.5 text-right tabular-nums whitespace-nowrap">{{ money(acc.amount) }}</td>
              </tr>
            </template>
          </template>
        </tbody>
      </table>
    </div>

    <div v-if="lines.length || hasAmount(report.cash_movement) || hasAmount(report.untagged_cash) || !report.reconciles" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
      <div class="text-neutral-500">
        {{ t('dimensions.cf_cash_movement') }}:
        <span class="font-mono font-semibold text-neutral-700">{{ money(report.cash_movement) }}</span>
      </div>
      <div class="text-neutral-500">
        {{ t('dimensions.cf_untagged') }}:
        <span class="font-mono font-semibold" :class="report.reconciles ? 'text-neutral-700' : 'text-warning-700'" data-test="cf-untagged">{{ money(report.untagged_cash) }}</span>
      </div>
      <span v-if="report.reconciles" class="text-xs px-2 py-1 rounded bg-success-50 text-success-700 font-medium">{{ t('dimensions.cf_reconciles') }}</span>
    </div>
    <p v-if="!report.reconciles" class="rounded-md bg-warning-50 border border-warning-500/30 px-3 py-2 text-xs text-warning-600" data-test="cf-untagged-hint">
      {{ report.dimension ? t('dimensions.cf_untagged_hint') : t('dimensions.cf_unbalanced_hint') }}
    </p>
  </div>
</template>
