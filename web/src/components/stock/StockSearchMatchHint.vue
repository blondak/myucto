<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { StockSearchMatch } from '@/api/stock'
import { stockSearchMatchLabel } from '@/utils/stockSearchMatch'
import { ICONS } from '@/components/ui/buttonStyles'

// Pod názvem karty ukáže, kterým kusem nebo parametrem karta vyhověla hledání
// (VIN, výrobní číslo, šarže). Shodu v kódu nebo názvu backend neposílá.
const props = defineProps<{ match: StockSearchMatch | null | undefined }>()
const { t } = useI18n()

const label = computed(() => (props.match ? stockSearchMatchLabel(props.match, t) : ''))
</script>

<template>
  <div v-if="match" class="mt-0.5 flex min-w-0 items-center gap-1 text-xs text-neutral-500" :title="`${label}: ${match.value}`">
    <svg class="h-3.5 w-3.5 shrink-0 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" />
    </svg>
    <span class="shrink-0">{{ label }}:</span>
    <span class="truncate font-mono text-neutral-700">{{ match.value }}</span>
  </div>
</template>
