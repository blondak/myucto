<script setup lang="ts">
/**
 * Varování kontroly 290 před zmrazením měsíčního hlášení.
 *
 * Hlášení se podává po splatnosti pojistného a uplatňuje slevu na pojistném
 * zaměstnavatele. ČSSZ ji porovná se slevou v posledním hlášení s akceptovanou
 * pojistnou částí a vyšší slevu po lhůtě neuzná. Kontrola je propustná, takže
 * zmrazení nezakazuje, ale bez výslovného potvrzení účetní neproběhne.
 */
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

defineProps<{
  message: string
  busy?: boolean
  testId?: string
}>()

const emit = defineEmits<{ confirm: []; cancel: [] }>()
const { t } = useI18n()
</script>

<template>
  <div
    class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-900"
    :data-test="testId ?? 'late-discount-confirm'"
    role="alert"
  >
    <p class="font-semibold">{{ t('payroll.transport_delivery.late_discount_title') }}</p>
    <p class="mt-1">{{ message }}</p>
    <p class="mt-1 text-xs text-warning-800">{{ t('payroll.transport_delivery.late_discount_where') }}</p>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button
        type="button"
        :class="btnFilled('warning')"
        :disabled="busy"
        :data-test="`${testId ?? 'late-discount-confirm'}-yes`"
        @click="emit('confirm')"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
        {{ busy ? t('common.loading') : t('payroll.transport_delivery.late_discount_confirm') }}
      </button>
      <RouterLink
        :to="{ name: 'payroll-submissions-tab', params: { tab: 'transport' } }"
        :class="btnOutline('primary')"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
        {{ t('payroll.transport_delivery.late_discount_protocols') }}
      </RouterLink>
      <RouterLink :to="{ name: 'payroll-runs' }" :class="btnOutline('neutral')">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
        {{ t('payroll.transport_delivery.late_discount_runs') }}
      </RouterLink>
      <button type="button" :class="btnOutline('neutral')" @click="emit('cancel')">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
        {{ t('common.cancel') }}
      </button>
    </div>
  </div>
</template>
