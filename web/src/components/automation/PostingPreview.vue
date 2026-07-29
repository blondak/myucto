<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  debit: string
  credit: string
  amount: number
  currency: string
  date: string
  description: string
  accounts?: Array<{ account_code: string; name: string }>
}>()
const { t } = useI18n()
const names = computed(() => Object.fromEntries((props.accounts ?? []).map(a => [a.account_code, a.name])))
const money = computed(() => new Intl.NumberFormat(undefined, { style: 'currency', currency: props.currency }).format(Math.abs(props.amount)))
</script>

<template>
  <div class="grid gap-2 sm:grid-cols-[1fr_auto_1fr] items-center rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
    <div><span class="text-xs text-neutral-500">{{ t('bank.posting.debit') }}</span><div class="font-mono font-semibold" :title="names[debit]">{{ debit || '—' }}</div></div>
    <div class="text-neutral-400">→</div>
    <div><span class="text-xs text-neutral-500">{{ t('bank.posting.credit') }}</span><div class="font-mono font-semibold" :title="names[credit]">{{ credit || '—' }}</div></div>
    <div class="sm:col-span-3 flex flex-wrap justify-between gap-2 border-t border-neutral-200 pt-2 text-xs text-neutral-600">
      <span>{{ date }} · {{ description }}</span><strong>{{ money }}</strong>
    </div>
  </div>
</template>
