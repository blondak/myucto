<script setup lang="ts">
import { computed } from 'vue'
import { parseExtractionWarning } from '@/utils/extractionWarning'

const props = defineProps<{ warning: string | null | undefined }>()

const sections = computed(() => parseExtractionWarning(props.warning))
</script>

<template>
  <div class="space-y-2">
    <div v-for="(section, si) in sections" :key="si">
      <p v-for="(p, pi) in section.paragraphs" :key="pi">{{ p }}</p>
      <ul v-if="section.items.length" class="mt-1 space-y-0.5 list-disc pl-5">
        <li v-for="(item, ii) in section.items" :key="ii">
          <span v-if="item.label" class="font-medium">{{ item.label }}:</span>
          {{ item.text }}
        </li>
      </ul>
    </div>
  </div>
</template>
