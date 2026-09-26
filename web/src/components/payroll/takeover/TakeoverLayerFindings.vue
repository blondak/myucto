<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import {
  monthRanges,
  periodMonths,
  type TakeoverLayerDifference,
  type TakeoverLayerOneSided,
} from './takeoverMonths'

/**
 * Rozdíly mezi počátečními stavy (čte je roční zúčtování a vyúčtování daně)
 * a převzatými mzdami (čte je ELDP a převzatý běh) za týž převzatý měsíc.
 *
 * Která strana je správně, rozhoduje účetní — proto u každého nálezu je, KDO,
 * ZA KTERÝ MĚSÍC a KDE se to opraví: karta osoby (počáteční stavy) a ruční
 * zadání převzatých mezd v Importech.
 */
defineProps<{
  differences: TakeoverLayerDifference[]
  openingOnly: TakeoverLayerOneSided[]
  takeoverOnly: TakeoverLayerOneSided[]
}>()

const { t } = useI18n()
</script>

<template>
  <div class="space-y-3 text-sm" data-test="takeover-layer-findings">
    <div v-if="differences.length > 0" class="overflow-x-auto">
      <p class="font-medium text-neutral-800">{{ t('payroll.takeover_check.differences_title') }}</p>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.takeover_check.differences_hint') }}</p>
      <table class="mt-2 min-w-full text-xs">
        <thead class="border-b border-neutral-200 text-left text-neutral-500">
          <tr>
            <th class="px-2 py-1 font-medium">{{ t('payroll.takeover_check.person') }}</th>
            <th class="px-2 py-1 font-medium">{{ t('payroll.takeover_check.period') }}</th>
            <th class="px-2 py-1 font-medium">{{ t('payroll.takeover_check.metric_column') }}</th>
            <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.opening') }}</th>
            <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.takeover') }}</th>
            <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.difference') }}</th>
            <th class="px-2 py-1" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, index) in differences"
            :key="`${row.employee_id}-${row.period}-${row.metric}-${index}`"
            class="border-b border-neutral-100"
            :data-test="`takeover-difference-${row.employee_id}-${row.period}-${row.metric}`"
          >
            <td class="px-2 py-1 text-neutral-800">{{ row.employee_name }}</td>
            <td class="px-2 py-1 text-neutral-700">{{ formatPeriod(row.period) }}</td>
            <td class="px-2 py-1 text-neutral-700">{{ t(`payroll.takeover_check.metric.${row.metric}`) }}</td>
            <td class="px-2 py-1 text-right tabular-nums">{{ formatMoneyMinor(row.opening_minor) }}</td>
            <td class="px-2 py-1 text-right tabular-nums">{{ formatMoneyMinor(row.takeover_minor) }}</td>
            <td class="px-2 py-1 text-right font-semibold tabular-nums text-danger-700">{{ formatMoneyMinor(row.difference_minor) }}</td>
            <td class="px-2 py-1">
              <RouterLink
                :to="{ name: 'payroll-person', params: { id: row.employee_id } }"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.takeover_check.open_person') }}
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="openingOnly.length > 0">
      <p class="font-medium text-neutral-800">{{ t('payroll.takeover_check.opening_only_title') }}</p>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.takeover_check.opening_only_hint') }}</p>
      <ul class="mt-1 space-y-1">
        <li
          v-for="row in openingOnly"
          :key="`opening-${row.employee_id}`"
          class="flex flex-wrap items-center gap-2"
          :data-test="`takeover-opening-only-${row.employee_id}`"
        >
          <span>{{ t('payroll.takeover_check.gap_person', { name: row.employee_name, months: monthRanges(periodMonths(row.periods)) }) }}</span>
          <RouterLink
            :to="{ name: 'payroll-imports', query: { tab: 'takeover', employee: String(row.employee_id) } }"
            :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.takeover_check.open_takeover') }}
          </RouterLink>
        </li>
      </ul>
    </div>

    <div v-if="takeoverOnly.length > 0">
      <p class="font-medium text-neutral-800">{{ t('payroll.takeover_check.takeover_only_title') }}</p>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.takeover_check.takeover_only_hint') }}</p>
      <ul class="mt-1 space-y-1">
        <li
          v-for="row in takeoverOnly"
          :key="`takeover-${row.employee_id}`"
          class="flex flex-wrap items-center gap-2"
          :data-test="`takeover-takeover-only-${row.employee_id}`"
        >
          <span>{{ t('payroll.takeover_check.gap_person', { name: row.employee_name, months: monthRanges(periodMonths(row.periods)) }) }}</span>
          <RouterLink
            :to="{ name: 'payroll-person', params: { id: row.employee_id } }"
            :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.takeover_check.open_person') }}
          </RouterLink>
        </li>
      </ul>
    </div>
  </div>
</template>
