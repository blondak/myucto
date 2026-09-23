<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { otherItemsApi, type OtherItem, type OtherItemSide } from '@/api/otherItems'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const items = ref<OtherItem[]>([])
const sources = ref<OtherItem[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const side = ref<'' | OtherItemSide>(route.query.side === 'receivable' || route.query.side === 'payable' ? route.query.side : '')
const status = ref<'open' | 'all'>('open')
const source = ref('')

const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const visibleItems = computed(() => source.value === '' || source.value === 'manual' ? items.value : [])
const visibleSources = computed(() => source.value === '' ? sources.value : sources.value.filter(item =>
  source.value === 'tax'
    ? ['tax_advance', 'vat_forecast', 'dppo_forecast'].includes(item.source_kind)
    : ['payroll', 'payroll_forecast'].includes(item.source_kind) && source.value === 'payroll',
))
const actions = computed<ActionItem[]>(() => [
  { key: 'new', label: t('other_items.new'), icon: 'plus', tier: 'primary', variant: 'primary',
    show: auth.canWrite('other_items'), to: { name: 'other-item-new', query: side.value ? { side: side.value } : {} } },
])

function internalSourceUrl(item: OtherItem): string | null {
  const url = item.source_url
  return url && url.startsWith('/') && !url.startsWith('//') ? url : null
}

function itemRoute(item: OtherItem) {
  if (item.source_kind === 'manual') return { name: 'other-item-detail', params: { id: item.source_id } }
  return internalSourceUrl(item)
}

function label(group: 'kind' | 'source' | 'status', value: string): string {
  const key = `other_items.${group}.${value}`
  return t(key) === key ? value.replaceAll('_', ' ') : t(key)
}

function badgeClass(item: OtherItem): string {
  if (item.status === 'paid' || item.status === 'confirmed' || item.status === 'posted') return 'bg-success-50 text-success-700'
  if (item.status === 'reversed' || item.status === 'cancelled') return 'bg-neutral-100 text-neutral-500'
  if (item.status === 'draft') return 'bg-warning-50 text-warning-700'
  return 'bg-primary-50 text-primary-700'
}

function isEstimate(item: OtherItem): boolean {
  return item.certainty === 'estimate' || item.status === 'forecast'
}

async function load() {
  loading.value = true
  try {
    const result = await otherItemsApi.list({
      ...(side.value ? { side: side.value } : {}), status: status.value,
      page: page.value,
    })
    items.value = result.items
    sources.value = result.sources || []
    total.value = result.total
    perPage.value = result.per_page || 50
  } catch (error: any) {
    items.value = []
    sources.value = []
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

watch([side, status], () => { page.value = 1; void load() })
watch(page, () => void load())
onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('other_items.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('other_items.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.side_label') }}
        <select v-model="side" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option value="receivable">{{ t('other_items.side.receivable') }}</option>
          <option value="payable">{{ t('other_items.side.payable') }}</option>
        </select>
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.status_label') }}
        <select v-model="status" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="open">{{ t('other_items.open') }}</option>
          <option value="all">{{ t('common.all') }}</option>
        </select>
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.source_label') }}
        <select v-model="source" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option value="manual">{{ t('other_items.source.manual') }}</option>
          <option value="payroll">{{ t('other_items.source.payroll') }}</option>
          <option value="tax">{{ t('other_items.source.tax') }}</option>
        </select>
      </label>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="visibleItems.length === 0 && visibleSources.length === 0" dense accent="primary" icon="doc" :title="t('other_items.empty')" />
    <template v-else>
      <h2 v-if="visibleSources.length && source !== 'payroll' && source !== 'tax'" class="mb-2 text-sm font-semibold">{{ t('other_items.manual_section') }}</h2>
      <EmptyState v-if="visibleItems.length === 0 && source === ''" dense accent="neutral" icon="doc" :title="t('other_items.no_manual')" />
      <div v-if="visibleItems.length" class="hidden overflow-x-auto rounded-lg border border-neutral-200 bg-surface lg:block">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.item') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.partner') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.due_on') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.source_label') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('other_items.amount') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('other_items.remaining') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.status_label') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in visibleItems" :key="item.id" class="hover:bg-neutral-50">
              <td class="px-3 py-3">
                <RouterLink v-if="itemRoute(item)" :to="itemRoute(item)!" class="font-medium text-primary-700 hover:underline">{{ item.title }}</RouterLink>
                <span v-else class="font-medium">{{ item.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">{{ t(`other_items.side.${item.side}`) }} · {{ label('kind', item.kind) }}</div>
              </td>
              <td class="px-3 py-3">{{ item.partner_name || t('other_items.no_partner') }}</td>
              <td class="whitespace-nowrap px-3 py-3">{{ item.due_on ? formatDate(item.due_on) : '–' }}</td>
              <td class="px-3 py-3">{{ label('source', item.source_kind) }}<span v-if="item.document_count" class="ml-1 text-neutral-500">· {{ item.document_count }} {{ t('other_items.documents') }}</span></td>
              <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(item.amount, item.currency) }}</td>
              <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</td>
              <td class="px-3 py-3"><span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(item)">{{ label('status', item.status) }}</span><span v-if="isEstimate(item)" class="ml-1 text-xs text-neutral-500">{{ t('other_items.forecast') }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="visibleItems.length" class="space-y-2 lg:hidden">
        <div v-for="item in visibleItems" :key="item.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <RouterLink v-if="itemRoute(item)" :to="itemRoute(item)!" class="font-semibold text-primary-700">{{ item.title }}</RouterLink>
              <span v-else class="font-semibold">{{ item.title }}</span>
              <div class="text-xs text-neutral-500">{{ t(`other_items.side.${item.side}`) }} · {{ label('source', item.source_kind) }}</div>
              <span v-if="isEstimate(item)" class="mt-1 inline-block rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ t('other_items.forecast') }}</span>
            </div>
            <span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(item)">{{ label('status', item.status) }}</span>
          </div>
          <div class="mt-3 flex flex-wrap justify-between gap-2 text-sm"><span>{{ item.partner_name || t('other_items.no_partner') }}</span><span>{{ item.due_on ? formatDate(item.due_on) : '–' }}</span></div>
          <div class="mt-2 flex flex-wrap justify-between gap-2 border-t border-neutral-100 pt-2 text-sm"><span>{{ t('other_items.remaining') }}</span><strong class="tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</strong></div>
        </div>
      </div>
      <div v-if="visibleItems.length && pages > 1" class="mt-4 flex flex-wrap items-center justify-end gap-3 text-sm">
        <button type="button" class="rounded border border-neutral-300 px-3 py-1.5 disabled:opacity-50" :disabled="page <= 1" @click="page--">{{ t('other_items.previous') }}</button>
        <span>{{ page }} / {{ pages }}</span>
        <button type="button" class="rounded border border-neutral-300 px-3 py-1.5 disabled:opacity-50" :disabled="page >= pages" @click="page++">{{ t('other_items.next') }}</button>
      </div>
      <section v-if="visibleSources.length" class="mt-6">
        <h2 class="mb-1 text-lg font-semibold">{{ t('other_items.sources_section') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('other_items.sources_hint') }}</p>
        <div class="grid gap-2 md:grid-cols-2">
          <div v-for="item in visibleSources" :key="item.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <RouterLink v-if="internalSourceUrl(item)" :to="internalSourceUrl(item)!" class="font-medium text-primary-700 hover:underline">{{ item.title }}</RouterLink>
                <span v-else class="font-medium">{{ item.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">{{ label('source', item.source_kind) }} · {{ item.due_on ? formatDate(item.due_on) : '–' }}</div>
              </div>
              <div class="text-right"><strong class="block text-sm tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</strong><span v-if="isEstimate(item)" class="mt-1 inline-block rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ t('other_items.forecast') }}</span></div>
            </div>
            <div class="mt-2 flex flex-wrap gap-2 text-xs text-neutral-500"><span v-if="item.partner_name">{{ item.partner_name }}</span><span v-if="!isEstimate(item)">{{ label('status', item.status) }}</span></div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
