<script setup lang="ts">
/*
 * Rok přechodu, sdílený záložkami Převzaté mzdy a Kontrola.
 *
 * Stojí ve vlastní komponentě, aby obě záložky nepřepínaly každá svůj rok:
 * účetní se na týž měsíc dívá ze dvou stran a dvě různá období, o kterých neví,
 * jsou horší než žádný filtr.
 */
import { useI18n } from 'vue-i18n'
import { useMigrationWorkspace } from './migrationWorkspace'

const { t } = useI18n()
const workspace = useMigrationWorkspace()
</script>

<template>
  <label class="block">
    <span class="mb-1 block text-xs font-medium text-neutral-600">
      {{ t('payroll.migration_reconciliation.year') }}
    </span>
    <input
      v-model.number="workspace.year.value"
      type="number"
      min="2000"
      max="2200"
      class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
      data-test="migration-year"
    >
  </label>
</template>
