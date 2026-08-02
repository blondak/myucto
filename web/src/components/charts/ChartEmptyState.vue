<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/**
 * Sdílený prázdný stav grafu — nahrazuje canvas, když komponenta zjistí, že
 * nemá co kreslit (prázdné pole / samé nuly). U nové nebo malé firmy je to
 * nejčastěji viděný stav grafu na dashboardu, takže je záměrně tichý: žádná
 * animace ani rámeček, je to absence dat, ne chyba.
 */
defineProps<{
  /** Volitelný druhý, menší řádek (např. „Zaeviduj první fakturu"). */
  hint?: string
}>()

const { t } = useI18n()
</script>

<template>
  <div class="flex h-full w-full flex-col items-center justify-center gap-1.5 text-center">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
      stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 shrink-0 text-neutral-400" aria-hidden="true">
      <path d="M4 19V10" />
      <path d="M10 19V5" />
      <path d="M16 19v-7" />
      <path d="M4 19h16" />
    </svg>
    <span class="text-sm text-neutral-400">{{ t('common.no_data') }}</span>
    <span v-if="hint" class="text-xs text-neutral-400/80">{{ hint }}</span>
  </div>
</template>
