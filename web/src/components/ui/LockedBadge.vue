<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import type { DocumentLock } from '@/api/locks'

/**
 * Badge zámku dokladu (F6). Řídí se VÝHRADNĚ polem `locked` z BE —
 * FE nikdy neodvozuje zámek ze status/booked_at.
 */
const props = defineProps<{
  lock: DocumentLock
  variant?: 'client' | 'staff'
}>()

const { t } = useI18n()
const auth = useAuthStore()

const effectiveVariant = computed<'client' | 'staff'>(
  () => props.variant ?? (auth.isClientRole ? 'client' : 'staff'),
)

const inClosedPeriod = computed(() => props.lock.reasons.includes('period_closed'))

const clientHint = computed(() =>
  inClosedPeriod.value ? t('lock.client_hint_period') : t('lock.client_hint'),
)

const staffReasons = computed(() =>
  props.lock.reasons.map(r => t(`lock.reason.${r}`)).join(', '),
)

const staffTitle = computed(() =>
  inClosedPeriod.value
    ? `${staffReasons.value} — ${t('lock.staff_period_warning')}`
    : staffReasons.value,
)

const badgeClass = computed(() =>
  effectiveVariant.value === 'staff' && inClosedPeriod.value
    ? 'bg-warning-50 text-warning-600 border border-warning-500/40'
    : 'bg-neutral-100 text-neutral-600 border border-neutral-200',
)
</script>

<template>
  <span
    v-if="lock.is_locked"
    class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded font-normal"
    :class="badgeClass"
    :title="effectiveVariant === 'client' ? clientHint : staffTitle"
    :aria-label="t('lock.badge') + ': ' + (effectiveVariant === 'client' ? clientHint : staffReasons)"
  >
    <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
    </svg>
    <span>{{ t('lock.badge') }}</span>
    <span v-if="effectiveVariant === 'staff'" class="hidden sm:inline text-[10px] text-current/70">
      ({{ staffReasons }})
    </span>
  </span>
</template>
