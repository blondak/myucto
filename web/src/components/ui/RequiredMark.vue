<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <span
    class="ml-0.5 text-danger-600"
    :title="t('common.required')"
    data-required-mark
    aria-hidden="true"
  >*</span>
</template>
