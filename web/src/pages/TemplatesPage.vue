<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import TemplateTools from '@/components/settings/TemplateTools.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('nav.section_templates') }}</h1>
    </div>

    <TemplateTools />
  </div>
</template>
