<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ExportIssued from '@/components/exchange/ExportIssued.vue'
import ImportIssued from '@/components/exchange/ImportIssued.vue'
import ExportPurchase from '@/components/exchange/ExportPurchase.vue'
import ImportPurchase from '@/components/exchange/ImportPurchase.vue'

// Export/Import (reorg UX 2026-07): dřív 4 taby jedné sjednocené stránky
// /utilities?section=exchange&tab=…, teď 4 samostatné routy zavěšené jako
// „Export"/„Import" pod Prodej (vydané) a Nákup (přijaté) v nav (AppLayout.vue).
// Vydané × přijaté drží `scope`, export × import drží `mode` — obojí přichází
// staticky z route props (router/index.ts), stránka jen vybere správnou komponentu.
const props = defineProps<{ scope: 'issued' | 'purchase'; mode: 'export' | 'import' }>()

const { t } = useI18n()

const title = computed(() => {
  if (props.scope === 'issued') return props.mode === 'export' ? t('nav.exports') : t('nav.imports_issued')
  return props.mode === 'export' ? t('nav.purchase_export') : t('nav.imports_purchase')
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ title }}</h1>
    </div>

    <ExportIssued   v-if="scope === 'issued'   && mode === 'export'" />
    <ImportIssued   v-else-if="scope === 'issued'   && mode === 'import'" />
    <ExportPurchase v-else-if="scope === 'purchase' && mode === 'export'" />
    <ImportPurchase v-else />
  </div>
</template>
