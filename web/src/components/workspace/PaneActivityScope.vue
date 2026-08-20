<script setup lang="ts">
import { provide } from 'vue'
import { paneIdKey } from '@/workspace/paneActivity'
import type { PaneId } from '@/stores/workspace'

const props = defineProps<{ paneId: PaneId }>()
provide(paneIdKey, props.paneId)
</script>

<template>
  <slot />
</template>
