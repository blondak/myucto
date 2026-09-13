<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'
import { btnOutline } from '@/components/ui/buttonStyles'
import type { PayrollQuickColumnGroup } from './payrollQuickColumns'

/*
 * Nabídka sloupců rychlého měsíčního vstupu: zákonné příplatky a mzdové
 * složky firmy, seskupené podle druhu.
 *
 * Komponenta jen zobrazuje a hlásí změny. Co je zaškrtnuté, kde se to ukládá
 * a co znamenají předvolby, rozhoduje stránka — komponenta tu pravdu nesmí
 * držet sama, jinak by se po obnovení preference rozešla s tabulkou.
 */
defineProps<{
  groups: PayrollQuickColumnGroup[]
  selected: string[]
}>()

const emit = defineEmits<{
  change: [key: string, checked: boolean]
  preset: [name: 'compact' | 'full']
  close: []
}>()

const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
// Tlačítka, která nabídku otevírají, se za „klik mimo" nepočítají — jinak by
// ji zavřela a hned zase otevřela.
onClickOutside(root, () => emit('close'), { ignore: ['[data-quick-columns-anchor]'] })
</script>

<template>
  <div
    ref="root"
    data-testid="quick-columns-menu"
    role="dialog"
    :aria-label="t('payroll.quick_inputs.columns_menu.title')"
    class="absolute left-0 top-full z-40 mt-1 w-[28rem] max-w-[calc(100vw-2rem)] rounded-lg border border-neutral-200 bg-surface shadow-lg"
    @keydown.esc.stop="emit('close')"
  >
    <div class="border-b border-neutral-100 px-3 py-2">
      <p class="text-sm font-semibold text-neutral-900">{{ t('payroll.quick_inputs.columns_menu.title') }}</p>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.quick_inputs.columns_menu.hint') }}</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          type="button"
          data-testid="quick-columns-preset-compact"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          @click="emit('preset', 'compact')"
        >
          {{ t('payroll.quick_inputs.columns_menu.preset_compact') }}
        </button>
        <button
          type="button"
          data-testid="quick-columns-preset-full"
          :class="[btnOutline('primary'), 'whitespace-nowrap']"
          :title="t('payroll.quick_inputs.columns_menu.preset_full_hint')"
          @click="emit('preset', 'full')"
        >
          {{ t('payroll.quick_inputs.columns_menu.preset_full') }}
        </button>
      </div>
    </div>
    <!-- fieldset má v prohlížeči min-width: min-content; bez min-w-0 by dlouhý
         název složky roztáhl seznam a vznikl vodorovný posuvník. -->
    <div class="max-h-96 overflow-y-auto overflow-x-hidden py-1">
      <fieldset v-for="group in groups" :key="group.key" class="min-w-0 px-1 py-1">
        <legend class="px-2 pb-0.5 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
          {{ group.label }}
        </legend>
        <label
          v-for="option in group.options"
          :key="option.key"
          :data-testid="`quick-columns-option-${option.key}`"
          class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50"
          :title="option.summaryOnly ? t('payroll.quick_inputs.columns_menu.summary_only_hint') : undefined"
        >
          <input
            type="checkbox"
            class="mt-0.5 rounded border-neutral-300 text-payroll-600"
            :checked="selected.includes(option.key)"
            @change="emit('change', option.key, ($event.target as HTMLInputElement).checked)"
          >
          <span class="min-w-0 flex-1">
            <span class="block truncate">{{ option.label }}</span>
            <span v-if="option.code" class="block truncate text-[11px] text-neutral-400">{{ option.code }}</span>
          </span>
          <span class="flex shrink-0 flex-col items-end gap-0.5">
            <span
              v-if="option.rowsWithValue > 0"
              class="whitespace-nowrap rounded-full bg-payroll-50 px-1.5 py-0.5 text-[11px] font-medium text-payroll-700"
            >
              {{ t('payroll.quick_inputs.columns_menu.rows_with_value', { count: option.rowsWithValue }) }}
            </span>
            <span
              v-if="option.summaryOnly"
              class="whitespace-nowrap rounded-full bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-500"
            >
              {{ t('payroll.quick_inputs.columns_menu.summary_only') }}
            </span>
          </span>
        </label>
      </fieldset>
      <p
        v-if="groups.length <= 1"
        class="px-3 py-2 text-xs text-neutral-500"
        data-testid="quick-columns-empty"
      >
        {{ t('payroll.quick_inputs.columns_menu.empty') }}
      </p>
    </div>
    <div class="flex justify-end border-t border-neutral-100 px-3 py-2">
      <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="emit('close')">
        {{ t('payroll.quick_inputs.columns_menu.close') }}
      </button>
    </div>
  </div>
</template>
