<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollJmhzProtocolRemediation } from '@/api/payroll'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { jmhzProtocolRemediationTarget } from './jmhzBlockerRemediation'

/**
 * Doložený postup nápravy u chyby z protokolu ČSSZ: co chyba znamená, kroky
 * a kam v aplikaci jít. Text i kroky jsou v překladech podle kódu nápravy,
 * server posílá jen kód, druh nápravy a atributy, které musí zůstat prázdné.
 */
const props = defineProps<{
  remediation: PayrollJmhzProtocolRemediation
  testId: string
}>()

const { t, tm, rt } = useI18n()

const explanation = computed(() => t(`payroll.jmhz_gate.codes.${props.remediation.code}`))
// `tm()` vrací pole zpráv, `rt()` každou zformátuje; `t()` by pole převedl na text.
const steps = computed(() => {
  const raw = tm(`payroll.jmhz_protocol_help.steps.${props.remediation.code}`) as unknown
  return Array.isArray(raw) ? raw.map(item => rt(item as Parameters<typeof rt>[0])) : []
})
const target = computed(() => jmhzProtocolRemediationTarget(props.remediation))
</script>

<template>
  <div
    class="mt-2 rounded-md border border-neutral-200 bg-white p-3 text-sm text-neutral-800"
    :data-test="testId"
  >
    <p>{{ explanation }}</p>
    <p class="mt-2 text-xs font-semibold text-neutral-900">
      {{ t('payroll.jmhz_protocol_help.title') }}
    </p>
    <ol class="mt-1 list-decimal space-y-1 pl-5 text-xs text-neutral-700">
      <li v-for="(step, index) in steps" :key="index">{{ step }}</li>
    </ol>
    <div
      v-if="remediation.empty_attribute_ids.length"
      class="mt-2 flex flex-wrap items-center gap-1"
      :data-test="`${testId}-empty-attributes`"
    >
      <span class="text-xs text-neutral-600">{{ t('payroll.jmhz_protocol_help.empty_attributes') }}</span>
      <span
        v-for="attributeId in remediation.empty_attribute_ids"
        :key="attributeId"
        class="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-700"
      >
        {{ attributeId }}
      </span>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <RouterLink
        v-if="target"
        :to="target"
        :class="[btnOutline('warning'), 'whitespace-nowrap']"
        :data-test="`${testId}-link`"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.edit" />
        </svg>
        {{ t(`payroll.jmhz_gate.remediation.${remediation.kind}`) }}
      </RouterLink>
      <span class="text-xs text-neutral-500">
        {{ t(`payroll.jmhz_protocol_help.source.${remediation.source}`) }}
      </span>
    </div>
  </div>
</template>
