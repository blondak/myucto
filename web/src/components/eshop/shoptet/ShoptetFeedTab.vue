<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ShoptetSteps from './ShoptetSteps.vue'
import { shoptetApi, type ShoptetSettings, type ShoptetSettingsPayload, type ShoptetWarehouse } from '@/api/shoptet'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ settings: ShoptetSettings; warehouses: ShoptetWarehouse[]; canWrite: boolean }>()
const emit = defineEmits<{ (e: 'updated', payload: ShoptetSettingsPayload): void }>()
const { t } = useI18n()
const toast = useToast()

const busy = ref(false)
const freshUrl = ref('')
const form = reactive({
  feed_warehouse_id: props.settings.feed_warehouse_id,
  feed_include_price: props.settings.feed_include_price,
  feed_scope: props.settings.feed_scope,
})
watch(() => props.settings, s => {
  form.feed_warehouse_id = s.feed_warehouse_id
  form.feed_include_price = s.feed_include_price
  form.feed_scope = s.feed_scope
})
const dirty = computed(() => form.feed_warehouse_id !== props.settings.feed_warehouse_id
  || form.feed_include_price !== props.settings.feed_include_price
  || form.feed_scope !== props.settings.feed_scope)

async function rotate() {
  if (props.settings.feed_enabled && !window.confirm(t('shoptet.feed.rotate_confirm'))) return
  busy.value = true
  try {
    const r = await shoptetApi.rotateFeed()
    freshUrl.value = window.location.origin + r.path
    emit('updated', { settings: r.settings, warehouses: r.warehouses })
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.feed.rotate_failed')))
  } finally {
    busy.value = false
  }
}

async function disable() {
  if (!window.confirm(t('shoptet.feed.disable_confirm'))) return
  busy.value = true
  try {
    emit('updated', await shoptetApi.disableFeed())
    freshUrl.value = ''
    toast.success(t('shoptet.feed.disabled_ok'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.save_failed')))
  } finally {
    busy.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(freshUrl.value)
    toast.success(t('shoptet.feed.copied'))
  } catch {
    toast.warning(t('shoptet.feed.copy_manual'))
  }
}

async function save() {
  busy.value = true
  try {
    emit('updated', await shoptetApi.saveSettings({ ...form }))
    toast.success(t('shoptet.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.save_failed')))
  } finally {
    busy.value = false
  }
}

async function download(format: 'xml' | 'csv') {
  try {
    const headers = await shoptetApi.downloadFeed(format)
    const skipped = Number(headers['x-shoptet-skipped'] ?? 0)
    if (skipped > 0) toast.warning(t('shoptet.feed.download_skipped', { n: skipped }))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.feed.download_failed')))
  }
}
</script>

<template>
  <div class="space-y-4">
    <ShoptetSteps message-key="shoptet.feed.steps" />

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">{{ t('shoptet.feed.status_title') }}</h2>
        <span class="px-2 py-0.5 rounded border text-xs"
          :class="settings.feed_enabled ? 'bg-success-50 text-success-600 border-success-500/40' : 'bg-neutral-100 text-neutral-600 border-neutral-300'">
          {{ settings.feed_enabled ? t('shoptet.feed.enabled') : t('shoptet.feed.disabled') }}
        </span>
      </div>
      <ul class="text-sm text-neutral-600 space-y-0.5">
        <li v-if="settings.feed_token_created_at">{{ t('shoptet.feed.created_at', { date: formatDateTime(settings.feed_token_created_at) }) }}</li>
        <li v-if="settings.feed_last_served_at">{{ t('shoptet.feed.served_at', { date: formatDateTime(settings.feed_last_served_at) }) }}</li>
        <li v-if="settings.feed_changed_at">{{ t('shoptet.feed.changed_at', { date: formatDateTime(settings.feed_changed_at) }) }}</li>
      </ul>

      <div v-if="freshUrl" class="rounded-md bg-warning-50 border border-warning-500/40 p-3 space-y-2" data-test="fresh-url">
        <p class="text-sm text-warning-600">{{ t('shoptet.feed.url_once') }}</p>
        <div class="flex flex-wrap gap-2 items-center">
          <input :value="freshUrl" readonly class="flex-1 min-w-0 font-mono text-xs border border-neutral-300 rounded px-2 h-9 bg-surface" @focus="($event.target as HTMLInputElement).select()" />
          <button :class="btnOutline('primary')" @click="copy">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
            {{ t('shoptet.feed.copy') }}
          </button>
        </div>
      </div>

      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <button :class="settings.feed_enabled ? btnOutline('warning') : btnFilled('primary')" :disabled="busy" data-test="rotate" @click="rotate">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="settings.feed_enabled ? ICONS.cycle : ICONS.link" /></svg>
          {{ settings.feed_enabled ? t('shoptet.feed.rotate') : t('shoptet.feed.generate') }}
        </button>
        <button v-if="settings.feed_enabled" :class="btnOutline('danger')" :disabled="busy" @click="disable">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('shoptet.feed.disable') }}
        </button>
      </div>
      <p class="text-xs text-neutral-500">{{ t('shoptet.feed.oversell_hint') }}</p>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <h2 class="text-lg font-semibold">{{ t('shoptet.feed.options_title') }}</h2>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="block font-medium mb-1">{{ t('shoptet.feed.warehouse') }}</span>
          <select v-model="form.feed_warehouse_id" :disabled="!canWrite" class="w-full border border-neutral-300 rounded px-2 h-9 bg-surface">
            <option :value="null">{{ t('shoptet.feed.warehouse_all') }}</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }} ({{ w.code }})</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="block font-medium mb-1">{{ t('shoptet.feed.scope') }}</span>
          <select v-model="form.feed_scope" :disabled="!canWrite" class="w-full border border-neutral-300 rounded px-2 h-9 bg-surface">
            <option value="eshop">{{ t('shoptet.feed.scope_eshop') }}</option>
            <option value="active">{{ t('shoptet.feed.scope_active') }}</option>
          </select>
        </label>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.feed_include_price" type="checkbox" :disabled="!canWrite" class="mt-1" />
        <span>{{ t('shoptet.feed.include_price') }}<span class="block text-xs text-neutral-500">{{ t('shoptet.feed.include_price_hint') }}</span></span>
      </label>
      <p class="text-xs text-neutral-500">{{ t('shoptet.feed.stock_hint') }}</p>
      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <button :class="btnFilled('primary')" :disabled="busy || !dirty" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('shoptet.save') }}
        </button>
      </div>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.feed.download_title') }}</h2>
      <p class="text-sm text-neutral-500">{{ t('shoptet.feed.download_hint') }}</p>
      <div class="flex flex-wrap gap-2">
        <button :class="btnOutline('neutral')" data-test="download-xml" @click="download('xml')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('shoptet.feed.download_xml') }}
        </button>
        <button :class="btnOutline('neutral')" data-test="download-csv" @click="download('csv')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
          {{ t('shoptet.feed.download_csv') }}
        </button>
      </div>
    </section>
  </div>
</template>
