<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { monthRanges, type TakeoverGap } from './takeoverMonths'

/**
 * U koho převzaté úhrny chybí — jméno, měsíce a proklik na kartu osoby, kde se
 * počáteční stavy doplňují. Samotné „chybí u 3 zaměstnanců" by účetní poslalo
 * hledat po celé firmě.
 */
defineProps<{ gaps: TakeoverGap[] }>()

const { t } = useI18n()
</script>

<template>
  <ul class="space-y-1" data-test="takeover-gap-list">
    <li
      v-for="gap in gaps"
      :key="gap.employee_id"
      class="flex flex-wrap items-center gap-2"
      :data-test="`takeover-gap-${gap.employee_id}`"
    >
      <span>
        {{ t('payroll.takeover_check.gap_person', { name: gap.employee_name, months: monthRanges(gap.missing_months) }) }}
      </span>
      <RouterLink
        :to="{ name: 'payroll-person', params: { id: gap.employee_id } }"
        :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
        :data-test="`takeover-gap-link-${gap.employee_id}`"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.edit" />
        </svg>
        {{ t('payroll.takeover_check.open_person') }}
      </RouterLink>
    </li>
  </ul>
</template>
