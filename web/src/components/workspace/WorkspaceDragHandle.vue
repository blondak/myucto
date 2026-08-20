<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <span
    class="workspace-row-drag-handle"
    role="img"
    :aria-label="t('workspace.drag_row')"
    :title="t('workspace.drag_row')"
  >
    <svg viewBox="0 0 12 18" fill="currentColor" aria-hidden="true">
      <circle cx="3.5" cy="3" r="1.25" />
      <circle cx="8.5" cy="3" r="1.25" />
      <circle cx="3.5" cy="9" r="1.25" />
      <circle cx="8.5" cy="9" r="1.25" />
      <circle cx="3.5" cy="15" r="1.25" />
      <circle cx="8.5" cy="15" r="1.25" />
    </svg>
  </span>
</template>
