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
  <!--
    Vše na jednom řádku. Nadpis, odznak a věta pod sebou zabíraly čtyři řádky
    a přitom říkaly totéž: věta zdroj rozhodnutí pojmenovává znovu, hned pod
    odznakem, který ho už nese. Vysoký prázdný rám navíc vypadal jako klikatelná
    karta, i když klikatelný není.
  -->
  <section class="rounded-lg border border-neutral-200 bg-surface px-3 py-2 text-sm">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
      <h3 class="font-medium text-neutral-800">{{ t('automation.why_title') }}</h3>
      <WhyChip :provenance="provenance" />
      <ConfidenceLabel v-if="provenance.confidence != null" :confidence="provenance.confidence" />
      <span class="text-neutral-600">
        {{ provenance.mode === 'auto'
          ? t('automation.decided_auto', { source: t(`automation.source.${provenance.source}`) })
          : t('automation.decided_by', { user: provenance.decided_by || t('automation.unknown_user'), date: formatDate(provenance.decided_at) }) }}
      </span>
    </div>
    <ul v-if="corrections?.length" class="mt-2 space-y-1 text-xs text-neutral-500">
      <li v-for="correction in corrections" :key="`${correction.date}-${correction.from}-${correction.to}`">
        {{ t('automation.correction_history', { date: correction.date, from: correction.from, to: correction.to }) }}
      </li>
    </ul>
  </section>
</template>
