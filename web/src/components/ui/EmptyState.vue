<script setup lang="ts">
import { computed, useSlots } from 'vue'
import { useI18n } from 'vue-i18n'
import { ICONS, btnFilled, btnOutline, type ActionIcon, type ActionVariant } from './buttonStyles'

/**
 * Sdílený prázdný stav seznamů, záložek a panelů.
 *
 * Why: „Žádná data k zobrazení." uprostřed prázdné tabulky nese nulovou
 * informaci — uživatel neví, jestli je modul prázdný, jestli mu něco zamlčel
 * filtr, nebo jestli je to chyba. Komponenta proto vždy odpovídá na tři věci:
 * CO tu chybí (nadpis), PROČ (vysvětlení) a KAM DÁL (akce).
 *
 * Rozlišení `variant` je jádro celého API: prázdný modul a prázdný VÝSLEDEK
 * FILTRU jsou dva úplně jiné stavy. V prvním případě je správná nabídka
 * „založ první záznam", ve druhém je to past — záznamy existují, jen je
 * schoval filtr, a jediná užitečná akce je filtr zrušit.
 */
const props = withDefaults(defineProps<{
  /** `empty` = agenda je opravdu prázdná · `filtered` = filtr/hledání nic nenašlo. */
  variant?: 'empty' | 'filtered'
  /** Ikona z `ICONS`; bez zadání se odvodí od varianty. */
  icon?: ActionIcon
  /** Barevný tint ikony (a rámečku v `boxed`). Držet se accentu modulu. */
  accent?: ActionVariant
  /** Nadpis. Bez zadání obecný text podle varianty. */
  title?: string
  /** Vysvětlující řádek pod nadpisem — typicky „co s tím". */
  message?: string
  /** Popisek hlavní akce. Bez něj se akce nevykreslí. */
  cta?: string
  /** Barva hlavní akce; bez zadání se odvodí od `accent` (viz `ctaClass`). */
  ctaVariant?: ActionVariant
  /** Ikona hlavní akce; default `plus` (založit) / `x` (zrušit filtr). */
  ctaIcon?: ActionIcon
  /** Cíl RouterLinku. Bez něj je akce tlačítko a emituje `action`. */
  to?: string
  /** Vedlejší akce (vždy outline) — např. „Zobrazit nápovědu". */
  secondary?: string
  secondaryIcon?: ActionIcon
  secondaryTo?: string
  /**
   * Počet sloupců tabulky. Je-li zadán, komponenta se vykreslí jako
   * `<tr><td :colspan>` — jinak by ji prohlížeč z `<tbody>` vystrčil ven.
   */
  colspan?: number
  /** Menší odsazení pro panely uvnitř detailu / záložky editoru. */
  dense?: boolean
  /** Vykreslit jako samostatnou kartu (nahrazuje-li celou kartu s tabulkou). */
  boxed?: boolean
}>(), {
  variant: 'empty',
  accent: 'primary',
})

const emit = defineEmits<{ action: []; secondary: [] }>()

const { t } = useI18n()
const slots = useSlots()

/*
 * Tinty se musí psát jako celé literály, jinak je Tailwind při skenování zdrojů
 * nenajde a třídy se do buildu vůbec nedostanou. Hodnoty jsou tokenové
 * (`-500/5`, `-600`), takže se v dark módu překlopí samy.
 */
const TINT: Record<ActionVariant, { outer: string; inner: string; icon: string; border: string }> = {
  primary: { outer: 'bg-primary-500/5', inner: 'bg-primary-500/10', icon: 'text-primary-600', border: 'border-primary-500/25' },
  success: { outer: 'bg-success-500/5', inner: 'bg-success-500/10', icon: 'text-success-600', border: 'border-success-500/25' },
  warning: { outer: 'bg-warning-500/5', inner: 'bg-warning-500/10', icon: 'text-warning-600', border: 'border-warning-500/25' },
  danger:  { outer: 'bg-danger-500/5',  inner: 'bg-danger-500/10',  icon: 'text-danger-600',  border: 'border-danger-500/25' },
  neutral: { outer: 'bg-neutral-500/5', inner: 'bg-neutral-500/10', icon: 'text-neutral-400', border: 'border-neutral-200' },
  accent:  { outer: 'bg-accent-500/5',  inner: 'bg-accent-500/10',  icon: 'text-accent-600',  border: 'border-accent-500/25' },
}

const isFiltered = computed(() => props.variant === 'filtered')

// U filtru je barevný tint zavádějící — nic se nestalo, jen se zúžil výběr.
const tint = computed(() => TINT[isFiltered.value && props.accent === 'primary' ? 'neutral' : props.accent])

const resolvedIcon = computed<ActionIcon>(() => props.icon ?? (isFiltered.value ? 'search' : 'inbox'))
const resolvedTitle = computed(() => props.title ?? t(isFiltered.value ? 'common.empty_state.filtered_title' : 'common.empty_state.title'))
const resolvedMessage = computed(() => props.message ?? (isFiltered.value ? t('common.empty_state.filtered_message') : undefined))
const resolvedCtaIcon = computed<ActionIcon>(() => props.ctaIcon ?? (isFiltered.value ? 'x' : 'plus'))

/*
 * Ve filtrovaném stavu je „zrušit filtr" jen návrat o krok zpět, ne hlavní krok
 * stránky — plné tlačítko by z něj dělalo doporučenou cestu, kterou není.
 *
 * `accent` je barva ikony, ne tlačítka: neutrální tint sedí u tichých sestav,
 * ale plné neutrální (tmavě šedé) tlačítko by z jediné zakládací akce udělalo
 * utilitu. Proto se neutrální accent u akce překlápí na primary.
 */
const ctaClass = computed(() => {
  if (isFiltered.value) return btnOutline(props.ctaVariant ?? 'neutral')
  return btnFilled(props.ctaVariant ?? (props.accent === 'neutral' ? 'primary' : props.accent))
})

const hasActions = computed(() => !!props.cta || !!props.secondary || !!slots.actions)

const wrapperClass = computed(() => [
  'rise-in text-center',
  props.dense ? 'px-4 py-6' : 'px-6 py-12',
  props.boxed ? `mx-auto max-w-2xl rounded-xl border bg-surface-raised shadow-sm ${tint.value.border}` : '',
])
</script>

<template>
  <!--
    `<component :is>` místo dvou kopií šablony: uvnitř `<tbody>` musí být
    kořenem `<tr><td>`, mimo tabulku stačí `<div>`. Obsah zůstává jeden.
  -->
  <component :is="colspan ? 'tr' : 'div'" :class="colspan ? '' : wrapperClass">
    <component :is="colspan ? 'td' : 'div'" :colspan="colspan" :class="colspan ? wrapperClass : ''">
      <div class="mx-auto flex max-w-md flex-col items-center gap-3">
        <span class="relative grid shrink-0 place-content-center" :class="dense ? 'h-11 w-11' : 'h-14 w-14'">
          <span class="absolute inset-0 rounded-full" :class="tint.outer" aria-hidden="true"></span>
          <span class="absolute inset-[18%] rounded-full" :class="tint.inner" aria-hidden="true"></span>
          <svg class="relative" :class="[dense ? 'h-5 w-5' : 'h-6 w-6', tint.icon]" fill="none" viewBox="0 0 24 24"
            stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[resolvedIcon]" />
          </svg>
        </span>

        <div class="min-w-0">
          <p class="font-medium text-neutral-800" :class="dense ? 'text-sm' : 'text-base'">{{ resolvedTitle }}</p>
          <p v-if="resolvedMessage" class="mt-1.5 text-sm text-neutral-500">{{ resolvedMessage }}</p>
        </div>

        <div v-if="hasActions" class="mt-1 flex flex-wrap items-center justify-center gap-2">
          <RouterLink v-if="cta && to" :to="to" :class="ctaClass">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[resolvedCtaIcon]" />
            </svg>
            {{ cta }}
          </RouterLink>
          <button v-else-if="cta" type="button" :class="ctaClass" @click="emit('action')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[resolvedCtaIcon]" />
            </svg>
            {{ cta }}
          </button>

          <RouterLink v-if="secondary && secondaryTo" :to="secondaryTo" :class="btnOutline('neutral')">
            <svg v-if="secondaryIcon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[secondaryIcon]" />
            </svg>
            {{ secondary }}
          </RouterLink>
          <button v-else-if="secondary" type="button" :class="btnOutline('neutral')" @click="emit('secondary')">
            <svg v-if="secondaryIcon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[secondaryIcon]" />
            </svg>
            {{ secondary }}
          </button>

          <slot name="actions" />
        </div>
      </div>
    </component>
  </component>
</template>
