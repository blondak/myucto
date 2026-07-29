<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { manualPostingQueueApi, type ManualQueueItem, type ManualQueueItemType } from '@/api/manualPostingQueue'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import PaginationBar from '@/components/ui/PaginationBar.vue'

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
    <div v-else-if="items.length === 0" class="text-center text-neutral-500 py-12 text-sm">{{ t('manualQueue.empty') }}</div>
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
