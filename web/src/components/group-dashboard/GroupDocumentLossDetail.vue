<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { groupDashboardApi, type GroupCompany, type GroupDashboard } from '@/api/groupDashboard'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/composables/useFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const route = useRoute()
const supplier = useSupplierStore()
const auth = useAuthStore()
const { t, locale } = useI18n()
const report = ref<GroupDashboard | null>(null)
const loading = ref(true)
const failed = ref(false)
let controller: AbortController | null = null
let requestId = 0
const company = computed<GroupCompany | undefined>(() => report.value?.companies.find(row => row.id === supplier.currentSupplierId))
const value = computed(() => company.value?.financial?.find(row => row.currency === route.query.currency))
const date = (value: string) => new Intl.DateTimeFormat(locale.value).format(new Date(`${value}T12:00:00`))
const money = (value: number | null) => value === null ? '-' : formatMoney(value, String(route.query.currency))
const source = (path: string) => ({ path, query: { year: 'all', currency: String(route.query.currency), from: report.value!.period.from, to: report.value!.period.to } })
async function load() {
  const id = ++requestId
  controller?.abort()
  report.value = null
  failed.value = false
  if (auth.permissionsLoading || !auth.canRead('dashboard.portfolio')) { loading.value = false; return }
  controller = new AbortController()
  loading.value = true
  try {
    const data = await groupDashboardApi.get({ section: 'overview', from: String(route.query.from), to: String(route.query.to) }, controller.signal)
    if (id === requestId) report.value = data
  } catch {
    if (id === requestId && !controller.signal.aborted) failed.value = true
  } finally {
    if (id === requestId) loading.value = false
  }
}
watch([() => route.query, () => auth.user, () => auth.permissions, () => auth.permissionsLoading, () => auth.domainContext, () => supplier.currentSupplierId], () => load(), { immediate: true, deep: true, flush: 'sync' })
onBeforeUnmount(() => { requestId++; controller?.abort() })
</script>

<template>
  <section id="group-document-result" class="scroll-mt-20 mb-5 rounded-lg border border-danger-200 bg-surface p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-neutral-900">{{ t('group_stats.risk_kinds.document_loss') }}</h2>
    <p v-if="report" class="mt-1 text-sm text-neutral-500">{{ company?.name }} · {{ t('group_stats.documents_period', { from: date(report.period.from), to: date(report.period.to) }) }}</p>
    <p v-if="loading" class="mt-4 text-sm text-neutral-500" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="failed" class="mt-4 text-sm text-danger-600">{{ t('errors.generic') }}</p>
    <p v-else-if="!value" class="mt-4 text-sm text-neutral-500">{{ t('group_stats.no_total_hint') }}</p>
    <template v-else>
      <div class="my-4 grid gap-3 sm:grid-cols-3"><div v-for="metric in (['revenue', 'costs', 'profit'] as const)" :key="metric"><p class="text-sm text-neutral-500">{{ t(`group_stats.${metric}`) }}</p><p class="mt-1 text-xl font-semibold tabular-nums" :class="metric === 'profit' ? 'text-danger-600' : 'text-neutral-900'">{{ money(value[metric]) }}</p></div></div>
      <div class="flex flex-wrap gap-2"><RouterLink v-for="path in ['/invoices', '/purchase-invoices']" :key="path" :to="source(path)" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.doc" /></svg>{{ t(path === '/invoices' ? 'nav.invoices' : 'nav.purchase_invoices') }}</RouterLink></div>
    </template>
  </section>
</template>
