<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { monthRanges, type TakeoverEstimatedStart } from './takeoverMonths'

/**
 * Vztahy s nástupem jen odhadnutým z nejstaršího převzatého hlášení. Mezera roku
 * přechodu, kterou kontrola počátečních stavů sama nevidí: vztah „nastoupil"
 * v prvním hlášeném měsíci, takže se za dřívější měsíce nic nečeká. Proklik vede
 * na kartu vztahu, kde se nástup opraví nebo potvrdí.
 */
defineProps<{ rows: TakeoverEstimatedStart[] }>()

const { t } = useI18n()
</script>

<template>
  <ul class="space-y-1" data-test="takeover-estimated-start-list">
    <li
      v-for="row in rows"
      :key="row.employment_id"
      class="flex flex-wrap items-center gap-2"
      :data-test="`takeover-estimated-start-${row.employment_id}`"
    >
      <span>
        {{ t('payroll.takeover_check.estimated_start_person', {
          name: row.employee_name,
          start: formatDate(row.start_on),
          months: monthRanges(row.possible_months),
        }) }}
      </span>
      <RouterLink
        :to="{ name: 'payroll-people', query: { person: String(row.employee_id), employment: String(row.employment_id), panel: 'employment_terms' } }"
        :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
        :data-test="`takeover-estimated-start-link-${row.employment_id}`"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.edit" />
        </svg>
        {{ t('payroll.takeover_check.open_employment') }}
      </RouterLink>
    </li>
  </ul>
</template>
