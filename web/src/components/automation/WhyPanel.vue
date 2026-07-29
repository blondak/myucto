<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/composables/useFormat'
import type { AutomationCorrection, AutomationProvenance } from '@/api/automation'
import ConfidenceLabel from './ConfidenceLabel.vue'
import WhyChip from './WhyChip.vue'

defineProps<{ provenance: AutomationProvenance; corrections?: AutomationCorrection[] }>()
const { t } = useI18n()
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm">
    <h3 class="font-medium text-neutral-800">{{ t('automation.why_title') }}</h3>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <WhyChip :provenance="provenance" />
      <ConfidenceLabel v-if="provenance.confidence != null" :confidence="provenance.confidence" />
    </div>
    <p class="mt-2 text-neutral-600">
      {{ provenance.mode === 'auto'
        ? t('automation.decided_auto', { source: t(`automation.source.${provenance.source}`) })
        : t('automation.decided_by', { user: provenance.decided_by || t('automation.unknown_user'), date: formatDate(provenance.decided_at) }) }}
    </p>
    <ul v-if="corrections?.length" class="mt-2 space-y-1 text-xs text-neutral-500">
      <li v-for="correction in corrections" :key="`${correction.date}-${correction.from}-${correction.to}`">
        {{ t('automation.correction_history', { date: correction.date, from: correction.from, to: correction.to }) }}
      </li>
    </ul>
  </section>
</template>
