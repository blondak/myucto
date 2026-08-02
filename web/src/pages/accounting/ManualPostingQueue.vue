<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { manualPostingQueueApi, type ManualQueueItem, type ManualQueueItemType } from '@/api/manualPostingQueue'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { RouterLink } from 'vue-router'
import { btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()

const items = ref<ManualQueueItem[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const loading = ref(false)
const countsByType = ref<Record<string, number>>({})
const countsByReason = ref<Record<string, number>>({})

const typeFilter = ref<ManualQueueItemType | ''>('')
const reasonFilter = ref('')

const TYPES: ManualQueueItemType[] = ['bank_no_suggestion', 'purchase_invoice', 'sales_invoice', 'document_request']

const reasonOptions = computed(() => Object.keys(countsByReason.value).sort())

let requestSeq = 0
async function load() {
  loading.value = true
  const seq = ++requestSeq
  try {
    const result = await manualPostingQueueApi.list({
      type: typeFilter.value || undefined,
      reason: reasonFilter.value || undefined,
      page: page.value,
      per_page: perPage.value,
    })
    if (seq !== requestSeq) return
    items.value = result.items
    total.value = result.total
    countsByType.value = result.counts.by_type
    countsByReason.value = result.counts.by_reason
  } catch (e: any) {
    if (seq !== requestSeq) return
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}
onMounted(load)
watch([typeFilter, reasonFilter], () => { page.value = 1; void load() })
watch(page, load)

const totalCount = computed(() => Object.values(countsByType.value).reduce((s, n) => s + n, 0))

function typeLabel(itemType: string): string {
  return t(`manualQueue.type.${itemType}`)
}

function reasonLabel(item: ManualQueueItem): string {
  if (item.type === 'document_request') return item.reason_detail || t('manualQueue.type.document_request')
  for (const prefix of ['automation.reason.', 'activation.skip.', 'manualQueue.reason.']) {
    const key = prefix + item.reason
    const translated = t(key)
    if (translated !== key) return translated
  }
  return item.reason
}

function actionLabel(item: ManualQueueItem): string {
  const key = `manualQueue.action.${item.suggested_action}`
  const translated = t(key)
  return translated === key ? item.suggested_action : translated
}

function typeBadgeClass(itemType: string): string {
  switch (itemType) {
    case 'bank_no_suggestion': return 'bg-warning-50 text-warning-700'
    case 'document_request': return 'bg-danger-50 text-danger-700'
    default: return 'bg-neutral-100 text-neutral-600'
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('manualQueue.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('manualQueue.subtitle') }}</p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-3">
      <select v-model="typeFilter" class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
        <option value="">{{ t('manualQueue.filter_all_types') }} ({{ totalCount }})</option>
        <option v-for="itemType in TYPES" :key="itemType" :value="itemType">
          {{ typeLabel(itemType) }} ({{ countsByType[itemType] ?? 0 }})
        </option>
      </select>
      <select v-model="reasonFilter" class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
        <option value="">{{ t('manualQueue.filter_all_reasons') }}</option>
        <option v-for="code in reasonOptions" :key="code" :value="code">
          {{ reasonLabel({ type: '', reason: code, reason_detail: null } as any) }} ({{ countsByReason[code] }})
        </option>
      </select>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <!--
      Prázdná fronta neznamená „nemáš co účtovat" — znamená „automatika si se vším
      poradila". Čekající NÁVRHY kontace sem schválně nepatří (viz docblock
      ManualPostingQueueService), jenže uživatel, který sem přišel s vědomím, že mu
      něco visí, viděl jen prázdnou stránku a neměl kam jít dál. Proto rovnou
      odkazujeme na obrazovky, kde návrhy čekají na schválení.
    -->
    <div v-else-if="items.length === 0" class="rise-in mx-auto max-w-2xl rounded-xl border border-success-500/25 bg-surface-raised p-8 shadow-md">
      <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-start sm:text-left">
        <span class="relative grid h-14 w-14 shrink-0 place-content-center">
          <span class="absolute inset-0 rounded-full bg-success-500/10" aria-hidden="true"></span>
          <svg class="relative h-7 w-7 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
          </svg>
        </span>
        <div class="min-w-0">
          <p class="text-base font-medium text-neutral-800">{{ t('manualQueue.empty') }}</p>
          <p class="mt-1.5 text-sm text-neutral-500">{{ t('manualQueue.empty_hint') }}</p>
          <div class="mt-4 flex flex-wrap justify-center gap-2 sm:justify-start">
            <RouterLink to="/bank?tab=posting" :class="btnOutline('primary')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z" />
              </svg>
              {{ t('manualQueue.empty_cta_bank') }}
            </RouterLink>
            <RouterLink to="/automation" :class="btnOutline('neutral')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              {{ t('manualQueue.empty_cta_automation') }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('manualQueue.col_type') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('manualQueue.col_date') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('manualQueue.col_counterparty') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('manualQueue.col_amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('manualQueue.col_reason') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('manualQueue.col_action') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in items" :key="item.id" class="hover:bg-neutral-50">
            <td class="px-3 py-2">
              <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="typeBadgeClass(item.type)">
                {{ typeLabel(item.type) }}
              </span>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(item.date) }}</td>
            <td class="px-3 py-2">
              <div>{{ item.counterparty || '—' }}</div>
              <div v-if="item.description" class="text-xs text-neutral-400">{{ item.description }}</div>
            </td>
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
              {{ item.amount !== null ? formatMoney(item.amount, item.currency || 'CZK') : '—' }}
            </td>
            <td class="px-3 py-2 max-w-xs">
              <span class="text-xs text-neutral-600">{{ reasonLabel(item) }}</span>
              <div v-if="item.deadline" class="text-xs text-danger-500 mt-0.5">
                {{ t('manualQueue.deadline', { date: formatDate(item.deadline) }) }}
              </div>
            </td>
            <td class="px-3 py-2 text-right">
              <RouterLink v-if="item.link.route !== 'document-requests'"
                :to="{ name: item.link.route, params: item.link.params, query: item.link.query }"
                class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                {{ actionLabel(item) }} →
              </RouterLink>
              <RouterLink v-else to="/document-requests"
                class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                {{ actionLabel(item) }} →
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :page="page" :per-page="perPage" :total="total" @update:page="page = $event" />
  </div>
</template>
