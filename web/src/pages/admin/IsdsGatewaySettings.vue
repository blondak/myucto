<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import IsdsGatewayRegistrations from '@/components/settings/IsdsGatewayRegistrations.vue'

const { t } = useI18n()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('databox.gateway.settingsTitle') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('databox.gateway.settingsSubtitle') }}</p>
    </header>

    <IsdsGatewayRegistrations />
  </div>
</template>
