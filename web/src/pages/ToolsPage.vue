<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import PostingRules from '@/pages/accounting/PostingRules.vue'
import FxRateSettings from '@/pages/accounting/FxRateSettings.vue'
import RepoRates from '@/pages/accounting/RepoRates.vue'
import AccountingArchive from '@/pages/accounting/AccountingArchive.vue'
import CostCenters from '@/pages/accounting/CostCenters.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const isAdmin = computed(() => auth.isSuperadmin)
const isDoubleEntry = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')

// Nástroje (reorg menu, audit 2026-07) — jedna položka menu, uvnitř záložky pro
// méně používané/setup funkce vyjmuté z Účetnictví a Daní (vzor BankPage.vue).
// Vlastní top-level tab drží ?section=.
// Export/Import (reorg UX 2026-07) se odsud vyčlenil na samostatné routy
// /invoices/export|import a /purchase-invoices/export|import, zavěšené jako
// nav položky pod Prodej a Nákup (viz AppLayout.vue) — už tady není záložka.
// Účetní období (Uzávěrka) se vytáhla do vlastní top-level položky menu
// /accounting/periods — už tady není záložka.
type Tab = 'cost-centers' | 'posting-rules' | 'fx-rates' | 'repo-rates' | 'archive'
const DOUBLE_ENTRY_TABS: Tab[] = ['posting-rules', 'cost-centers', 'fx-rates', 'repo-rates']
const visibleTabs = computed<Tab[]>(() => [
  ...(isDoubleEntry.value ? DOUBLE_ENTRY_TABS : []),
  ...((isDoubleEntry.value && isAdmin.value) ? ['archive'] as Tab[] : []),
])

function tabFromQuery(q: unknown): Tab | null {
  const v = String(q ?? '')
  return (visibleTabs.value as string[]).includes(v) ? (v as Tab) : (visibleTabs.value[0] ?? null)
}
const section = ref<Tab | null>(null)
watch([() => route.query.section, () => supplierStore.currentSupplier?.accounting_mode, isAdmin], ([q, accountingMode]) => {
  if (String(q ?? '') === 'submissions') {
    void router.replace({ name: 'reports-submissions' })
    return
  }
  if (String(q ?? '') === 'monthly-export' || (accountingMode && accountingMode !== 'double_entry')) {
    void router.replace({ name: 'reports-monthly-export' })
    return
  }
  section.value = tabFromQuery(q)
}, { immediate: true })

function switchTab(v: Tab) {
  if (section.value === v) return
  router.replace({ query: { ...route.query, section: v === 'posting-rules' ? undefined : v } })
}

function tabLabel(v: Tab): string {
  return v === 'cost-centers' ? t('nav.accounting_cost_centers')
    : v === 'posting-rules' ? t('nav.accounting_rules')
    : v === 'fx-rates' ? t('nav.accounting_fx_rates')
    : v === 'repo-rates' ? t('nav.accounting_repo_rates')
    : t('nav.accounting_archive')
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('nav.accounting_settings') }}</h1>
    </div>

    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in visibleTabs" :key="tt"
        @click="switchTab(tt)"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="section === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tabLabel(tt) }}
      </button>
    </div>

    <CostCenters          v-if="section === 'cost-centers'" embedded />
    <PostingRules         v-else-if="section === 'posting-rules'"   embedded />
    <FxRateSettings       v-else-if="section === 'fx-rates'"        embedded />
    <RepoRates            v-else-if="section === 'repo-rates'"      embedded />
    <AccountingArchive    v-else-if="section === 'archive'"         embedded />
  </div>
</template>
