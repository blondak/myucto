<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseExtractionWarning } from '@/utils/extractionWarning'

const props = defineProps<{
  warning: string | null | undefined
  /** U každé části tlačítko „Vyřešeno" — uživatel odškrtává body jeden po druhém. */
  dismissible?: boolean
  busy?: boolean
}>()
const emit = defineEmits<{ (e: 'dismiss', section: string): void }>()

const { t } = useI18n()
const sections = computed(() => parseExtractionWarning(props.warning))
</script>

<template>
  <div class="space-y-2">
    <div v-for="(section, si) in sections" :key="si" class="flex items-start gap-2">
      <div class="min-w-0 flex-1">
        <p v-for="(p, pi) in section.paragraphs" :key="pi">{{ p }}</p>
        <ul v-if="section.items.length" class="mt-1 space-y-0.5 list-disc pl-5">
          <li v-for="(item, ii) in section.items" :key="ii">
            <span v-if="item.label" class="font-medium">{{ item.label }}:</span>
            {{ item.text }}
          </li>
        </ul>
      </div>
      <button v-if="dismissible" type="button" :disabled="busy"
        class="cursor-pointer shrink-0 inline-flex items-center gap-1 text-xs px-2 py-0.5 border border-warning-500/50 rounded text-warning-700 hover:bg-warning-100 disabled:opacity-50 whitespace-nowrap"
        :title="t('purchase_invoice.extraction.resolve_section_hint')"
        @click="emit('dismiss', section.raw)">
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
        {{ t('purchase_invoice.extraction.resolve_section') }}
      </button>
    </div>
  </div>
</template>
