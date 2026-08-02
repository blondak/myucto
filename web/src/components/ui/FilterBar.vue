<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

/** Aktivní filtr vykreslený jako odstranitelný chip pod lištou. */
export interface FilterChip {
  /** Identifikátor vrácený v `clear`, ať volající ví, co vynulovat. */
  key: string
  /** Název filtru („Stav"). Volitelný — u samostatných přepínačů se nehodí. */
  label?: string
  /** Zvolená hodnota („Po splatnosti"). */
  value: string
}

/**
 * Sbalitelná lišta filtrů.
 * - `primary` slot: vždy viditelné prvky (typicky vyhledávání).
 * - default slot: ostatní filtry — na desktopu (md+) inline v jednom flex-wrap řádku,
 *   na mobilu schované za tlačítko „Filtry (N)".
 * - `actions` slot: akční tlačítka zarovnaná vpravo (ml-auto), vždy viditelná.
 * `activeCount` = počet aktivních filtrů zobrazený jako odznáček na tlačítku.
 *
 * `collapsible` schová filtry za tlačítko i na desktopu. Why: deset trvale
 * rozbalených selectů zabíralo dva řádky nad každým seznamem, i když uživatel
 * nefiltroval vůbec. Co je zapnuté, ukazují `chips` — čitelněji než řádek
 * ovládacích prvků, ve kterém se musí hledat, který zrovna není na výchozí hodnotě.
 */
defineProps<{
  activeCount?: number
  collapsible?: boolean
  chips?: FilterChip[]
}>()

const emit = defineEmits<{
  (e: 'clear', key: string): void
  (e: 'clear-all'): void
}>()

const { t } = useI18n()
const open = ref(false)
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
    <div class="flex flex-wrap items-center gap-2">
      <slot name="primary" />

      <button
        v-if="$slots.default"
        type="button"
        @click="open = !open"
        class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm inline-flex items-center gap-1.5 text-neutral-700 hover:bg-neutral-50 transition-colors"
        :class="[collapsible ? '' : 'md:hidden', open ? 'bg-neutral-50 border-neutral-400' : '']"
        :aria-expanded="open"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4" />
        </svg>
        {{ t('common.filters') }}
        <span
          v-if="activeCount"
          class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-primary-600 text-white text-xs font-medium tabular-nums"
        >{{ activeCount }}</span>
        <svg
          class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <!-- display:contents → děti se na md+ chovají jako přímé flex-položky řádku;
           na mobilu (a v `collapsible` režimu i na desktopu) se celá skupina skryje,
           dokud uživatel nerozbalí -->
      <div :class="open ? 'contents' : (collapsible ? 'hidden' : 'hidden md:contents')">
        <slot />
      </div>

      <div v-if="$slots.actions" class="ml-auto flex flex-wrap items-center gap-2">
        <slot name="actions" />
      </div>
    </div>

    <!-- Aktivní filtry. Zůstávají vidět i se sbalenou lištou — jinak by uživatel
         nevěděl, proč seznam nic nevrací. -->
    <div v-if="chips && chips.length > 0" class="flex flex-wrap items-center gap-1.5 mt-2.5 pt-2.5 border-t border-neutral-100">
      <button
        v-for="chip in chips"
        :key="chip.key"
        type="button"
        class="cursor-pointer group inline-flex items-center gap-1.5 h-7 pl-2.5 pr-1.5 rounded-full bg-primary-50 text-primary-700 text-xs font-medium hover:bg-primary-100 transition-colors"
        :title="t('common.filter_remove', { name: chip.label ?? chip.value })"
        @click="emit('clear', chip.key)"
      >
        <span v-if="chip.label" class="opacity-60">{{ chip.label }}:</span>
        <span>{{ chip.value }}</span>
        <svg class="w-3.5 h-3.5 opacity-50 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
      <button
        v-if="chips.length > 1"
        type="button"
        class="cursor-pointer ml-1 text-xs text-neutral-500 hover:text-danger-600 underline underline-offset-2 transition-colors"
        @click="emit('clear-all')"
      >{{ t('common.filter_clear_all') }}</button>
    </div>
  </div>
</template>
