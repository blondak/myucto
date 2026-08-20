<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/**
 * Hvězdička u popisku povinného pole.
 *
 * V projektu se hvězdička psala ručně (`text-danger-500` v Setup.vue,
 * CodeNameFields.vue, InvoiceEditor.vue), jinde jako holé `*` bez barvy
 * a jinde vůbec — na kartě zaměstnance tak nešlo poznat, co je povinné a co ne.
 * Tady je ta konvence jako jedna komponenta, aby značka vypadala všude stejně
 * a měla čitelný popisek pro odečítač obrazovky.
 *
 * Pravidlo: značku dostane JEN pole, bez kterého neprojde zápis, zákonné podání
 * nebo mzdový výpočet. Volitelné pole zůstává bez značky — „hvězdička u všeho"
 * nese stejně málo informace jako „hvězdička u ničeho". Co je povinné se ověřuje
 * proti serverovému validátoru, ne odhadem.
 *
 * Odsazení nese komponenta sama (`ml-0.5`), takže se v šabloně píše nalepená
 * hned za popisek a nezáleží na tom, jestli tam někdo nechal mezeru.
 */
const { t } = useI18n()
</script>

<template>
  <span
    class="ml-0.5 text-danger-600"
    :title="t('common.required')"
    data-required-mark
    data-test="required-mark"
  >
    <span aria-hidden="true">*</span>
    <span class="sr-only">{{ t('common.required') }}</span>
  </span>
</template>
