<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import ShoptetSteps from './ShoptetSteps.vue'
import { shoptetApi, type ShoptetBatch, type ShoptetPreviewRow, type ShoptetResultRow, type ShoptetReviewItem, type ShoptetSettings } from '@/api/shoptet'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime, formatNumber } from '@/composables/useFormat'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ settings: ShoptetSettings; canWrite: boolean }>()
const emit = defineEmits<{ (e: 'open-settings'): void; (e: 'changed'): void }>()
const { t } = useI18n()
const toast = useToast()

const file = ref<File | null>(null)
const busy = ref(false)
const error = ref('')
const preview = ref<ShoptetBatch | null>(null)
const result = ref<ShoptetBatch | null>(null)
const batches = ref<ShoptetBatch[]>([])
const review = ref<ShoptetReviewItem[]>([])

const previewRows = computed(() => (preview.value?.report ?? []) as ShoptetPreviewRow[])
const resultRows = computed(() => (result.value?.report ?? []) as ShoptetResultRow[])
const urlReason = computed(() => props.settings.order_url_set ? '' : t('shoptet.orders.url_missing'))

const PREVIEW_KEYS = ['create', 'update', 'unchanged', 'conflict', 'failed', 'review', 'unmatched_lines'] as const
const RESULT_KEYS = ['created', 'updated', 'unchanged', 'conflicts', 'failed', 'review'] as const

function badge(action: string): string {
  if (action === 'create' || action === 'created') return 'bg-success-50 text-success-600 border-success-500/40'
  if (action === 'update' || action === 'updated') return 'bg-primary-50 text-primary-700 border-primary-500/40'
  if (action === 'unchanged') return 'bg-neutral-100 text-neutral-600 border-neutral-300'
  if (action === 'conflict') return 'bg-warning-50 text-warning-600 border-warning-500/40'
  return 'bg-danger-50 text-danger-500 border-danger-500/40'
}

function pick(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
}
function drop(e: DragEvent) {
  e.preventDefault()
  file.value = e.dataTransfer?.files?.[0] ?? null
}

async function run(action: () => Promise<ShoptetBatch>) {
  busy.value = true
  error.value = ''
  result.value = null
  try {
    preview.value = await action()
  } catch (e) {
    error.value = apiErrorMessage(e, t('shoptet.orders.preview_failed'))
  } finally {
    busy.value = false
  }
}
const previewFile = () => file.value && run(() => shoptetApi.previewUpload(file.value as File))
const previewUrl = () => run(() => shoptetApi.previewUrl())

async function apply() {
  if (!preview.value) return
  busy.value = true
  error.value = ''
  try {
    result.value = await shoptetApi.apply(preview.value.id)
    preview.value = null
    file.value = null
    toast.success(t('shoptet.orders.applied'))
    emit('changed')
    await reload()
  } catch (e) {
    error.value = apiErrorMessage(e, t('shoptet.orders.apply_failed'))
  } finally {
    busy.value = false
  }
}

async function discard() {
  if (!preview.value) return
  const id = preview.value.id
  preview.value = null
  try {
    await shoptetApi.discard(id)
  } catch {
    // Zahozený náhled se po dni uklidí sám; chyba tady nic neblokuje.
  }
}

async function erase(batch: ShoptetBatch) {
  if (!window.confirm(t('shoptet.orders.erase_confirm'))) return
  try {
    const r = await shoptetApi.erase(batch.id)
    if (r.skipped.length > 0) {
      toast.warning(t('shoptet.orders.erased_partial', { deleted: r.deleted, skipped: r.skipped.length }))
    } else {
      toast.success(t('shoptet.orders.erased', { deleted: r.deleted }))
    }
    await reload()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.orders.erase_failed')))
  }
}

async function confirmReview(item: ShoptetReviewItem) {
  try {
    await shoptetApi.markReviewed(item.order_id)
    toast.success(t('shoptet.orders.review_confirmed'))
    review.value = review.value.filter(r => r.order_id !== item.order_id)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.orders.review_failed')))
  }
}

async function reload() {
  try {
    const [b, r] = await Promise.all([shoptetApi.batches('orders'), shoptetApi.reviewQueue()])
    batches.value = b
    review.value = r
  } catch (e) {
    error.value = apiErrorMessage(e, t('shoptet.load_failed'))
  }
}

function money(value: number | string | null | undefined): string {
  return value === null || value === undefined ? '—' : formatNumber(Number(value), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

onMounted(reload)
</script>

<template>
  <div class="space-y-4">
    <ShoptetSteps message-key="shoptet.orders.steps" />

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 class="text-lg font-semibold">{{ t('shoptet.orders.import_title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('shoptet.orders.file_hint') }}</p>
        </div>
        <p class="text-xs text-neutral-500">
          {{ settings.auto_fetch ? t('shoptet.orders.auto_on', { n: settings.fetch_interval_minutes }) : t('shoptet.orders.auto_off') }}
          <template v-if="settings.last_fetch_at"> · {{ t('shoptet.orders.last_fetch', { date: formatDateTime(settings.last_fetch_at) }) }}</template>
        </p>
      </div>

      <label v-if="canWrite" @dragover.prevent @drop="drop"
        class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-6 text-center cursor-pointer transition">
        <input type="file" accept=".xml,.csv,text/xml,application/xml,text/csv" class="hidden" data-test="orders-file" @change="pick" />
        <div class="text-sm font-medium text-neutral-700">{{ file ? file.name : t('shoptet.orders.drop') }}</div>
      </label>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <button :class="btnFilled('primary')" :disabled="busy || !file" data-test="preview-file" @click="previewFile">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
          {{ busy ? t('shoptet.orders.previewing') : t('shoptet.orders.preview_file') }}
        </button>
        <button :class="btnOutline('primary')" :disabled="busy || !settings.order_url_set"
          :title="disabledTitle(!settings.order_url_set, urlReason)" data-test="preview-url" @click="previewUrl">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('shoptet.orders.preview_url') }}
        </button>
        <button v-if="!settings.order_url_set" :class="btnOutline('neutral')" @click="emit('open-settings')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('shoptet.orders.set_url') }}
        </button>
      </div>
    </section>

    <section v-if="preview" class="bg-surface border border-primary-200 rounded-lg shadow-sm p-5 space-y-3" data-test="preview">
      <div>
        <h2 class="text-lg font-semibold">{{ t('shoptet.orders.preview_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('shoptet.orders.preview_hint') }}</p>
      </div>
      <div class="flex flex-wrap gap-2 text-xs">
        <span v-for="key in PREVIEW_KEYS" :key="key" class="px-2 py-1 rounded border bg-neutral-50 border-neutral-200">
          {{ t(`shoptet.orders.summary.${key}`) }}: <strong>{{ preview.summary[key] ?? 0 }}</strong>
        </span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-neutral-500 border-b">
            <tr>
              <th class="py-2 pr-3">{{ t('shoptet.orders.col.code') }}</th>
              <th class="py-2 pr-3">{{ t('shoptet.orders.col.customer') }}</th>
              <th class="py-2 pr-3">{{ t('shoptet.orders.col.action') }}</th>
              <th class="py-2 pr-3 text-right">{{ t('shoptet.orders.col.total') }}</th>
              <th class="py-2">{{ t('shoptet.orders.col.notes') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in previewRows" :key="row.code" class="border-b last:border-0 align-top">
              <td class="py-2 pr-3 font-mono whitespace-nowrap">{{ row.code }}</td>
              <td class="py-2 pr-3">
                {{ row.customer ?? '—' }}
                <span v-if="row.customer_match" class="block text-xs text-neutral-500">{{ t(`shoptet.orders.customer_${row.customer_match}`) }}</span>
              </td>
              <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded border text-xs whitespace-nowrap" :class="badge(row.action)">{{ t(`shoptet.orders.action.${row.action}`) }}</span></td>
              <td class="py-2 pr-3 text-right whitespace-nowrap">
                {{ money(row.total_with_vat) }} {{ row.currency ?? '' }}
                <span v-if="row.shoptet_total_with_vat !== undefined && row.shoptet_total_with_vat !== null" class="block text-xs text-neutral-500">
                  {{ t('shoptet.orders.col.shoptet_total') }} {{ money(row.shoptet_total_with_vat) }}
                </span>
              </td>
              <td class="py-2 text-xs space-y-1">
                <p v-for="(r, i) in row.review_reasons ?? []" :key="'r' + i" class="text-warning-600">{{ r }}</p>
                <p v-for="(m, i) in row.messages ?? []" :key="'m' + i" class="text-neutral-600">{{ m }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex flex-wrap gap-2">
        <button :class="btnFilled('success')" :disabled="busy" data-test="apply" @click="apply">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ busy ? t('shoptet.orders.applying') : t('shoptet.orders.apply') }}
        </button>
        <button :class="btnOutline('neutral')" :disabled="busy" @click="discard">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('shoptet.orders.discard') }}
        </button>
      </div>
    </section>

    <section v-if="result" class="bg-surface border border-success-500/40 rounded-lg shadow-sm p-5 space-y-3" data-test="result">
      <h2 class="text-lg font-semibold">{{ t('shoptet.orders.result_title') }}</h2>
      <div class="flex flex-wrap gap-2 text-xs">
        <span v-for="key in RESULT_KEYS" :key="key" class="px-2 py-1 rounded border bg-neutral-50 border-neutral-200">
          {{ t(`shoptet.orders.result.${key}`) }}: <strong>{{ result.summary[key] ?? 0 }}</strong>
        </span>
      </div>
      <p v-if="result.summary.cursor_held" class="text-sm text-warning-600" data-test="cursor-held">{{ t('shoptet.orders.cursor_held') }}</p>
      <ul class="text-sm divide-y">
        <li v-for="row in resultRows" :key="row.code" class="py-2 flex flex-wrap gap-x-3 gap-y-1 items-start">
          <span class="font-mono">{{ row.code }}</span>
          <span class="px-2 py-0.5 rounded border text-xs" :class="badge(row.status)">{{ t(`shoptet.orders.status.${row.status}`) }}</span>
          <RouterLink v-if="row.order_id" :to="`/stock/sales-orders/${row.order_id}`" class="text-primary-700 hover:underline text-xs">{{ t('shoptet.orders.open_order') }}</RouterLink>
          <span v-for="(m, i) in [...(row.review_reasons ?? []), ...(row.messages ?? [])]" :key="i" class="basis-full text-xs text-neutral-600">{{ m }}</span>
        </li>
      </ul>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <div>
        <h2 class="text-lg font-semibold">{{ t('shoptet.orders.review_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('shoptet.orders.review_hint') }}</p>
      </div>
      <p v-if="review.length === 0" class="text-sm text-neutral-500">{{ t('shoptet.orders.review_empty') }}</p>
      <ul v-else class="divide-y">
        <li v-for="item in review" :key="item.order_id" class="py-3 flex flex-wrap gap-3 items-start justify-between" data-test="review-item">
          <div class="text-sm min-w-0">
            <RouterLink :to="`/stock/sales-orders/${item.order_id}`" class="font-mono text-primary-700 hover:underline">{{ item.code }}</RouterLink>
            <span class="ml-2">{{ item.client_name }}</span>
            <span class="ml-2 text-neutral-500">{{ money(item.total_with_vat) }} {{ item.currency_code }}</span>
            <p v-for="(r, i) in item.review_reasons" :key="i" class="text-xs text-warning-600 mt-1">{{ r }}</p>
            <p v-if="item.pending_change" class="text-xs text-neutral-600 mt-1">{{ t('shoptet.orders.pending_change') }}</p>
          </div>
          <button v-if="canWrite && item.review_reasons.length > 0" :class="btnOutlineSm('success')" @click="confirmReview(item)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ t('shoptet.orders.review_done') }}
          </button>
        </li>
      </ul>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.batches_title') }}</h2>
      <p v-if="batches.length === 0" class="text-sm text-neutral-500">{{ t('shoptet.batches_empty') }}</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="b in batches" :key="b.id" class="py-2 flex flex-wrap gap-x-4 gap-y-1 items-center justify-between">
          <div class="min-w-0">
            <span class="font-medium">{{ formatDateTime(b.applied_at ?? b.created_at) }}</span>
            <span class="ml-2 text-neutral-500">{{ t(`shoptet.source.${b.source}`) }}<template v-if="b.file_name"> · {{ b.file_name }}</template></span>
            <span class="ml-2 text-xs px-2 py-0.5 rounded border bg-neutral-50 border-neutral-200">{{ t(`shoptet.batch_status.${b.status}`) }}</span>
            <span class="block text-xs text-neutral-500">
              {{ t('shoptet.orders.result.created') }} {{ b.summary.created ?? b.summary.create ?? 0 }},
              {{ t('shoptet.orders.result.updated') }} {{ b.summary.updated ?? b.summary.update ?? 0 }},
              {{ t('shoptet.orders.result.failed') }} {{ b.summary.failed ?? 0 }}
            </span>
            <span v-if="b.summary.cursor_held" class="block text-xs text-warning-600">{{ t('shoptet.orders.cursor_held') }}</span>
          </div>
          <button v-if="canWrite && b.status === 'applied' && Number(b.summary.created ?? 0) > 0" :class="btnOutlineSm('danger')" @click="erase(b)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('shoptet.orders.erase') }}
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
