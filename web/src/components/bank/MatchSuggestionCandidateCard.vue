<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MatchSuggestionCandidate } from '@/api/bank'
import { formatMoney } from '@/composables/useFormat'
import { candidateSignals, candidateFlags } from '@/utils/matchSignals'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{
  candidate: MatchSuggestionCandidate
  reviewing: boolean
  canAccept: boolean
  /** true = kompaktní (mobil/modal): tlačítko na celou šířku pod obsahem. false = desktop expand řádek. */
  dense?: boolean
}>(), { dense: false })

const emit = defineEmits<{ accept: [] }>()
const { t } = useI18n()

function signalLabel(signal: string): string {
  return t(`bank.match_signal.${signal}`, { n: props.candidate.invoice_ids?.length ?? 1 })
}

function flagLabel(flag: string): string {
  const rawAmount = flag === 'fee_gap' ? props.candidate.fee_amount
    : flag === 'overpayment' ? props.candidate.overpayment_amount
      : null
  return t(`bank.match_flag.${flag}`, {
    amount: rawAmount === null ? '—' : formatMoney(rawAmount, props.candidate.display.currency),
  })
}
</script>

<template>
  <div class="rounded-md border border-neutral-200 p-3" :class="{ 'bg-surface': dense }">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="font-mono text-sm text-neutral-800">{{ candidate.display.ref || '—' }}</div>
        <div v-if="candidate.display.party" class="text-xs text-neutral-500 truncate">{{ candidate.display.party }}</div>
        <div class="mt-1 font-mono text-sm">{{ formatMoney(candidate.display.amount, candidate.display.currency) }}</div>
      </div>
      <div v-if="!dense" class="flex flex-col items-end gap-2">
        <ConfidenceLabel :confidence="candidate.score" />
        <button v-if="canAccept" type="button" :disabled="reviewing" :class="btnOutline('success')" @click="emit('accept')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('bank.match_v2.accept') }}
        </button>
      </div>
      <ConfidenceLabel v-else :confidence="candidate.score" />
    </div>
    <ul v-if="candidateSignals(candidate).length" class="mt-2 space-y-1 text-xs text-neutral-600">
      <li v-for="signal in candidateSignals(candidate)" :key="signal" class="flex gap-1.5">
        <span class="text-success-600">✓</span><span>{{ signalLabel(signal) }}</span>
      </li>
    </ul>
    <ul v-if="candidateFlags(candidate).length" class="mt-2 space-y-1 text-xs text-warning-600">
      <li v-for="flag in candidateFlags(candidate)" :key="flag" class="flex gap-1.5">
        <span>⚠</span><span>{{ flagLabel(flag) }}</span>
      </li>
    </ul>
    <button v-if="dense && canAccept" type="button" :disabled="reviewing"
      class="mt-3 w-full justify-center" :class="btnOutline('success')" @click="emit('accept')">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
      {{ t('bank.match_v2.accept') }}
    </button>
  </div>
</template>
