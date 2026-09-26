<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import TakeoverGapList from './TakeoverGapList.vue'
import TakeoverLayerFindings from './TakeoverLayerFindings.vue'
import { monthRanges, type TakeoverCheck } from './takeoverMonths'

/**
 * Kontrola převzaté části roku: komu chybí převzaté úhrny a kde si počáteční
 * stavy s převzatými mzdami odporují. Obě vrstvy čtou jiné výstupy (roční
 * zúčtování a vyúčtování daně vs. ELDP), takže rozchod znamená, že si
 * výstupy za týž měsíc odporují.
 */
const props = defineProps<{ check: TakeoverCheck }>()

const { t } = useI18n()

const hasFindings = computed(() => props.check.missing_openings.length > 0
  || props.check.differences.length > 0
  || props.check.opening_only.length > 0
  || props.check.takeover_only.length > 0)
</script>

<template>
  <section
    v-if="check.takeover_months.length > 0"
    class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    data-test="takeover-check"
  >
    <h3 class="font-semibold text-neutral-900">{{ t('payroll.takeover_check.title') }}</h3>
    <p class="mt-1 max-w-3xl text-sm text-neutral-500">
      {{ t('payroll.takeover_check.hint', { months: monthRanges(check.takeover_months) }) }}
    </p>

    <p v-if="!hasFindings" class="mt-3 text-sm text-success-700" data-test="takeover-check-ok">
      {{ t('payroll.takeover_check.all_ok') }}
    </p>

    <div
      v-if="check.missing_openings.length > 0"
      class="mt-3 rounded-lg border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-800"
      data-test="takeover-check-gaps"
    >
      <p class="font-medium">{{ t('payroll.takeover_check.gaps_title') }}</p>
      <p class="mt-0.5 text-xs">{{ t('payroll.takeover_check.gaps_hint') }}</p>
      <TakeoverGapList class="mt-2" :gaps="check.missing_openings" />
    </div>

    <TakeoverLayerFindings
      v-if="check.differences.length > 0 || check.opening_only.length > 0 || check.takeover_only.length > 0"
      class="mt-3"
      :differences="check.differences"
      :opening-only="check.opening_only"
      :takeover-only="check.takeover_only"
    />
  </section>
</template>
