<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { BankTransactionSortKey } from '@/api/bank'

/**
 * Výběr řazení pohybů pro mobilní karty — ty nemají hlavičku tabulky, na kterou
 * by se dalo kliknout. Hodnota je `sloupec:směr`, prázdná = výchozí pořadí.
 */
defineProps<{ keys: readonly BankTransactionSortKey[] }>()
const model = defineModel<string>({ required: true })
const { t } = useI18n()
</script>

<template>
  <select v-model="model" :title="t('bank.sort.label')" :aria-label="t('bank.sort.label')"
    class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
    <option value="">{{ t('bank.sort.default') }}</option>
    <template v-for="k in keys" :key="k">
      <option :value="`${k}:asc`">{{ t(`bank.sort.keys.${k}`) }} ↑</option>
      <option :value="`${k}:desc`">{{ t(`bank.sort.keys.${k}`) }} ↓</option>
    </template>
  </select>
</template>
