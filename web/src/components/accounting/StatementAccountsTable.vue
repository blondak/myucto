<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { StatementAccountLine, StatementAccountsReport } from '@/api/accounting'

const props = defineProps<{
  report: StatementAccountsReport
  part: 'balance' | 'profit_loss'
  format: (value: number | null | undefined) => string
}>()

const { t } = useI18n()
const showAnalytics = ref(true)

function accountLink(acc: StatementAccountLine) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: acc.account_id },
    query: { from: props.report.period.starts_on, to: props.report.as_of },
  }
}

function netMd(acc: StatementAccountLine): number {
  return Math.max(0, acc.md - acc.d)
}

function netD(acc: StatementAccountLine): number {
  return Math.max(0, acc.d - acc.md)
}

const profit = computed(() => props.part === 'balance' ? props.report.balance.profit : props.report.profit_loss.profit)

const sections = computed(() => props.report.profit_loss.sections
  .filter(s => s.expenses.length || s.revenues.length)
  .map(s => ({ ...s, accounts: [...s.expenses, ...s.revenues] })))

const subtotals = computed(() => {
  const pl = props.report.profit_loss
  return [
    { key: 'operating_profit', value: pl.operating_profit },
    { key: 'financial_profit', value: pl.financial_profit },
    { key: 'profit_before_tax', value: pl.profit_before_tax },
    { key: 'profit_after_tax', value: pl.profit_after_tax },
  ]
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-3 text-xs text-neutral-500">
      <span>{{ t('accounting.statement_accounts.note_before_closing') }}</span>
      <span v-if="report.closed" class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">
        {{ t('accounting.statement_accounts.closed_note') }}
      </span>
      <label class="inline-flex items-center gap-1.5 cursor-pointer ml-auto whitespace-nowrap">
        <input v-model="showAnalytics" type="checkbox" class="rounded border-neutral-300" data-test="accounts-analytics-toggle">
        {{ t('accounting.statement_accounts.show_analytics') }}
      </label>
    </div>

    <div v-if="!report.checks.profit_matches"
      class="bg-danger-50 border border-danger-200 rounded-lg p-3 mb-4 text-sm text-danger-700" data-test="accounts-profit-mismatch">
      {{ t('accounting.statement_accounts.profit_mismatch', {
        balance: format(report.checks.profit_balance),
        pl: format(report.checks.profit_loss),
      }) }}
    </div>
    <div v-if="part === 'profit_loss' && report.checks.unassigned_count > 0"
      class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm text-warning-800" data-test="accounts-unassigned">
      {{ t('accounting.statement_accounts.unassigned_hint', { count: report.checks.unassigned_count }) }}
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.statement_accounts.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statement_accounts.col_name') }}</th>
              <th class="px-3 py-2 text-right font-medium w-36">
                {{ part === 'balance' ? t('accounting.statement_accounts.col_md') : t('accounting.statement_accounts.col_expenses') }}
              </th>
              <th class="px-3 py-2 text-right font-medium w-36">
                {{ part === 'balance' ? t('accounting.statement_accounts.col_d') : t('accounting.statement_accounts.col_revenues') }}
              </th>
            </tr>
          </thead>

          <tbody v-if="part === 'balance'" class="divide-y divide-neutral-100">
            <template v-for="cls in report.balance.classes" :key="cls.class">
              <tr class="bg-neutral-50 font-semibold">
                <td colspan="4" class="px-3 py-1.5">
                  {{ t('accounting.statement_accounts.class_label', { cls: cls.class }) }} · {{ t(`accounting.statement_accounts.class_${cls.class}`) }}
                </td>
              </tr>
              <template v-for="acc in cls.accounts" :key="acc.account_code">
                <tr class="hover:bg-neutral-50">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <RouterLink :to="accountLink(acc)" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                      {{ acc.account_code }}
                    </RouterLink>
                  </td>
                  <td class="px-3 py-1.5">{{ acc.name }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ acc.md ? format(acc.md) : '' }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ acc.d ? format(acc.d) : '' }}</td>
                </tr>
                <template v-if="showAnalytics">
                  <tr v-for="an in acc.analytics ?? []" :key="an.account_id" class="text-xs text-neutral-600 hover:bg-neutral-50">
                    <td class="py-1 pr-3 pl-7 whitespace-nowrap">
                      <RouterLink :to="accountLink(an)" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                        {{ an.account_code }}
                      </RouterLink>
                    </td>
                    <td class="px-3 py-1">{{ an.name }}</td>
                    <td class="px-3 py-1 text-right font-mono">{{ an.md ? format(an.md) : '' }}</td>
                    <td class="px-3 py-1 text-right font-mono">{{ an.d ? format(an.d) : '' }}</td>
                  </tr>
                </template>
              </template>
              <tr class="font-semibold border-t border-neutral-300">
                <td colspan="2" class="px-3 py-1.5">{{ t('accounting.statement_accounts.class_total', { cls: cls.class }) }}</td>
                <td class="px-3 py-1.5 text-right font-mono">{{ format(cls.md) }}</td>
                <td class="px-3 py-1.5 text-right font-mono">{{ format(cls.d) }}</td>
              </tr>
            </template>
            <tr class="font-semibold bg-neutral-50 border-t-2 border-neutral-300">
              <td colspan="2" class="px-3 py-2">{{ t('accounting.statement_accounts.balance_total') }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ format(report.balance.md) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ format(report.balance.d) }}</td>
            </tr>
          </tbody>

          <tbody v-else class="divide-y divide-neutral-100">
            <template v-for="section in sections" :key="section.key">
              <tr class="bg-neutral-50 font-semibold">
                <td colspan="4" class="px-3 py-1.5">{{ t(`accounting.statement_accounts.section_${section.key}`) }}</td>
              </tr>
              <template v-for="acc in section.accounts" :key="`${acc.account_type}-${acc.account_code}`">
                <tr class="hover:bg-neutral-50">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <RouterLink :to="accountLink(acc)" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                      {{ acc.account_code }}
                    </RouterLink>
                  </td>
                  <td class="px-3 py-1.5">{{ acc.name }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ netMd(acc) ? format(netMd(acc)) : '' }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ netD(acc) ? format(netD(acc)) : '' }}</td>
                </tr>
                <template v-if="showAnalytics">
                  <tr v-for="an in acc.analytics ?? []" :key="an.account_id" class="text-xs text-neutral-600 hover:bg-neutral-50">
                    <td class="py-1 pr-3 pl-7 whitespace-nowrap">
                      <RouterLink :to="accountLink(an)" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                        {{ an.account_code }}
                      </RouterLink>
                    </td>
                    <td class="px-3 py-1">{{ an.name }}</td>
                    <td class="px-3 py-1 text-right font-mono">{{ an.md ? format(an.md) : '' }}</td>
                    <td class="px-3 py-1 text-right font-mono">{{ an.d ? format(an.d) : '' }}</td>
                  </tr>
                </template>
              </template>
              <tr class="font-semibold border-t border-neutral-300">
                <td colspan="2" class="px-3 py-1.5">
                  {{ t('accounting.statement_accounts.section_result', { section: t(`accounting.statement_accounts.section_${section.key}`) }) }}:
                  <span class="font-mono ml-1">{{ format(section.result) }}</span>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">{{ format(section.expense_total) }}</td>
                <td class="px-3 py-1.5 text-right font-mono">{{ format(section.revenue_total) }}</td>
              </tr>
            </template>
            <tr v-for="s in subtotals" :key="s.key" class="font-semibold bg-primary-50/40">
              <td colspan="3" class="px-3 py-1.5">{{ t(`accounting.statement_accounts.${s.key}`) }}</td>
              <td class="px-3 py-1.5 text-right font-mono">{{ format(s.value) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr data-test="accounts-profit-row"
              :class="profit >= 0 ? 'bg-success-50 text-success-800' : 'bg-danger-50 text-danger-700'"
              class="text-base font-bold border-t-2 border-neutral-300">
              <td colspan="3" class="px-3 py-3">
                {{ t('accounting.statement_accounts.profit_row') }}
                ({{ profit >= 0 ? t('accounting.statement_accounts.profit') : t('accounting.statement_accounts.loss') }})
              </td>
              <td class="px-3 py-3 text-right font-mono whitespace-nowrap">{{ format(profit) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>
