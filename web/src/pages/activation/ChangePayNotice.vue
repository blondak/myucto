<script setup lang="ts">
/**
 * Odmítnutá změna licence, ke které server přiložil odkaz na zaplacení.
 *
 * `cardRequired`: předplatné placené fakturou nemá uloženou kartu. Nejde
 * o chybu — změna se zaplatí jednorázově kartou, předplatné se dál platí
 * fakturou a konec období zůstává. Proto informativní, ne chybový tón.
 * Jinak uložená karta neprošla a doplatek se zaplatí jinou.
 */
import { useI18n } from 'vue-i18n'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

defineProps<{ href: string; cardRequired: boolean; message: string }>()

const { t } = useI18n()
</script>

<template>
  <div
    class="mt-3 rounded-md border p-3 text-sm"
    :class="cardRequired ? 'border-primary-300 bg-primary-50 text-primary-800' : 'border-danger-500/40 bg-danger-50 text-danger-600'"
    :data-change-card-payment="cardRequired ? 'required' : 'declined'"
  >
    <p>{{ message }}</p>
    <div class="mt-3 flex flex-wrap gap-2">
      <a :href="href" target="_blank" rel="noopener" :class="[btnFilled(cardRequired ? 'primary' : 'success'), 'whitespace-nowrap']" data-change-card-payment-cta>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
        {{ cardRequired ? t('license.card_payment_cta') : t('license.pay_other_card_cta') }}
      </a>
    </div>
    <p v-if="cardRequired" class="mt-2 text-xs text-neutral-600">{{ t('license.card_payment_hint') }}</p>
  </div>
</template>
