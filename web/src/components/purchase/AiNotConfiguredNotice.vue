<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
</script>

<template>
  <div data-test="ai-not-configured"
       class="rounded-md bg-warning-50 border border-warning-500/40 px-4 py-3 text-sm text-warning-700 flex flex-col gap-2">
    <strong>{{ t('aiGateway.not_configured_title') }}</strong>
    <p class="text-xs leading-relaxed">{{ t('aiGateway.not_configured_body') }}</p>
    <slot />
    <div v-if="auth.canWrite('settings.company.write')" class="flex flex-wrap gap-2">
      <RouterLink to="/admin/integrations?tab=ai" :class="[btnFilled('primary'), 'whitespace-nowrap']">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
        {{ t('aiGateway.not_configured_cta') }}
      </RouterLink>
    </div>
    <p v-else class="text-xs">{{ t('aiGateway.not_configured_ask_admin') }}</p>
  </div>
</template>
