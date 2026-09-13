<script setup lang="ts">
/**
 * Stav jednoho pole rychlého měsíčního vstupu jako ikona u pole.
 *
 * Věta „Hodnotu spravuje jiný vstup" nebo „Rozpracovaný vstup" stála pod
 * každým polem a po importu docházky ji měl skoro každý řádek — tabulka pak
 * byla z poloviny tentýž text. Ikona drží řádek nízký, plné vysvětlení nese
 * `title` a pro odečítače `sr-only`. Na mobilu, kde `title` nejde vyvolat,
 * se k ikoně přidá krátký štítek.
 */
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { ICONS } from '@/components/ui/buttonStyles'

export type PayrollQuickFieldStateKind = 'draft' | 'approved' | 'locked' | 'managed'

const props = defineProps<{
  state: PayrollQuickFieldStateKind
  message: string
  label?: string | null
  to?: RouteLocationRaw | null
  linkHint?: string | null
}>()

const ICON: Record<PayrollQuickFieldStateKind, string> = {
  draft: ICONS.edit,
  approved: ICONS.check,
  locked: ICONS.lock,
  managed: ICONS.link,
}

const tone = computed(() => {
  if (props.state === 'draft') return 'text-payroll-700'
  if (props.state === 'managed') return 'text-warning-700'
  return 'text-neutral-500'
})

const explanation = computed(() => props.linkHint
  ? `${props.message} ${props.linkHint}`
  : props.message)
</script>

<template>
  <RouterLink
    v-if="to"
    :to="to"
    :title="explanation"
    :class="['inline-flex shrink-0 items-center gap-1 rounded text-xs font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-warning-500/40', tone]"
  >
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICON[state]" /></svg>
    <span v-if="label" aria-hidden="true">{{ label }}</span>
    <span class="sr-only">{{ explanation }}</span>
  </RouterLink>
  <span
    v-else
    :title="explanation"
    :class="['inline-flex shrink-0 items-center gap-1 text-xs font-medium', tone]"
  >
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICON[state]" /></svg>
    <span v-if="label" aria-hidden="true">{{ label }}</span>
    <span class="sr-only">{{ explanation }}</span>
  </span>
</template>
