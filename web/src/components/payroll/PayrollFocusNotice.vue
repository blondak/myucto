<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * „Seznam je zúžený na jednoho člověka."
 *
 * Why: odkaz z karty zaměstnance předává zúžení query stringem. Bez viditelné
 * lišty vypadá agenda tak, že o tom člověku ví jediný záznam — a zpátky na celý
 * přehled by se uživatel dostal jen ručním smazáním adresy. Proto je zúžení
 * vidět a jde ho jedním kliknutím zrušit.
 */
defineProps<{
  /** Koho se zúžení týká — jméno, ne id. */
  name: string
  /**
   * Zúžení proběhlo až v prohlížeči nad prvními N záznamy období, protože
   * server filtr podle vztahu nezná. Když je záznamů víc, než se stihlo načíst,
   * MUSÍ to lišta říct: prázdný výsledek by jinak tvrdil „ten člověk tu nic
   * nemá", i když jeho záznamy jen leží za koncem načtené dávky.
   */
  truncated?: boolean
  /**
   * Server zúžení uplatnil a nezbylo nic. Tichý prázdný seznam je horší než
   * chyba — vypadá jako „ten člověk tu nic nemá", i když je zúžení jen slepé
   * (cizí nebo zaniklý vztah). Lišta to proto řekne větou a nabídne zrušení.
   */
  missing?: boolean
}>()

defineEmits<{ clear: [] }>()

const { t } = useI18n()
</script>

<template>
  <div
    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-payroll-500/30 bg-payroll-50 px-3 py-2 text-sm text-neutral-700"
    data-test="payroll-focus-notice"
  >
    <span class="min-w-0">
      {{ missing
        ? t('payroll.agendas.focus.missing', { name })
        : t('payroll.agendas.focus.title', { name }) }}
      <span v-if="truncated" class="block text-warning-800" data-test="payroll-focus-truncated">
        {{ t('payroll.agendas.focus.truncated') }}
      </span>
    </span>
    <button
      type="button"
      :class="btnOutlineSm('neutral')"
      data-test="payroll-focus-clear"
      @click="$emit('clear')"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
      {{ t('payroll.agendas.focus.clear') }}
    </button>
  </div>
</template>
