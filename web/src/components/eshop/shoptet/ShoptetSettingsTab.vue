<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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
const newUrl = ref('')
const form = reactive({
  documents_issuer: props.settings.documents_issuer,
  auto_fetch: props.settings.auto_fetch,
  fetch_interval_minutes: props.settings.fetch_interval_minutes,
  default_warehouse_id: props.settings.default_warehouse_id,
  confirm_orders: props.settings.confirm_orders,
})
function syncForm(s: ShoptetSettings) {
  form.documents_issuer = s.documents_issuer
  form.auto_fetch = s.auto_fetch
  form.fetch_interval_minutes = s.fetch_interval_minutes
  form.default_warehouse_id = s.default_warehouse_id
  form.confirm_orders = s.confirm_orders
}
watch(() => props.settings, syncForm)

const dirty = computed(() => newUrl.value.trim() !== ''
  || form.documents_issuer !== props.settings.documents_issuer
  || form.auto_fetch !== props.settings.auto_fetch
  || form.fetch_interval_minutes !== props.settings.fetch_interval_minutes
  || form.default_warehouse_id !== props.settings.default_warehouse_id
  || form.confirm_orders !== props.settings.confirm_orders)

function intervalLabel(minutes: number): string {
  return minutes < 60 ? t('shoptet.settings.minutes', { n: minutes }) : t('shoptet.settings.hours', { n: minutes / 60 })
}

async function save() {
  busy.value = true
  try {
    let payload: ShoptetSettingsPayload | null = null
    if (newUrl.value.trim() !== '') {
      payload = await shoptetApi.setOrderUrl(newUrl.value.trim())
      newUrl.value = ''
    }
    payload = await shoptetApi.saveSettings({ ...form })
    emit('updated', payload)
    toast.success(t('shoptet.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.save_failed')))
  } finally {
    busy.value = false
  }
}

async function removeUrl() {
  if (!window.confirm(t('shoptet.settings.url_remove_confirm'))) return
  busy.value = true
  try {
    const payload = await shoptetApi.clearOrderUrl()
    emit('updated', payload)
    toast.success(t('shoptet.settings.url_removed'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('shoptet.save_failed')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4 pb-20">
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.settings.issuer_title') }}</h2>
      <label v-for="issuer in (['myucto', 'shoptet'] as const)" :key="issuer"
        class="flex items-start gap-3 border rounded-md p-3 cursor-pointer"
        :class="form.documents_issuer === issuer ? 'border-primary-500/60 bg-primary-50/40' : 'border-neutral-200'">
        <input v-model="form.documents_issuer" type="radio" name="issuer" :value="issuer" :disabled="!canWrite" class="mt-1" :data-test="`issuer-${issuer}`" />
        <span class="text-sm">
          <span class="font-medium">{{ t(`shoptet.settings.issuer_${issuer}`) }}</span>
          <span class="block text-neutral-600">{{ t(`shoptet.settings.issuer_${issuer}_hint`) }}</span>
        </span>
      </label>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.settings.url_title') }}</h2>
      <p class="text-sm" data-test="url-hint">
        <template v-if="settings.order_url_set">{{ t('shoptet.settings.url_current') }} <span class="font-mono break-all">{{ settings.order_url_hint }}</span></template>
        <template v-else>{{ t('shoptet.settings.url_none') }}</template>
      </p>
      <label v-if="canWrite" class="block text-sm">
        <span class="block font-medium mb-1">{{ settings.order_url_set ? t('shoptet.settings.url_replace') : t('shoptet.settings.url_label') }}</span>
        <input v-model="newUrl" type="url" autocomplete="off" spellcheck="false" :placeholder="t('shoptet.settings.url_placeholder')"
          class="w-full border border-neutral-300 rounded px-2 h-9 font-mono text-xs bg-surface" data-test="url-input" />
      </label>
      <p class="text-xs text-neutral-500">{{ t('shoptet.settings.url_hint') }}</p>
      <div v-if="canWrite && settings.order_url_set" class="flex flex-wrap gap-2">
        <button :class="btnOutline('danger')" :disabled="busy" @click="removeUrl">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('shoptet.settings.url_remove') }}
        </button>
      </div>

      <label class="flex items-start gap-2 text-sm pt-2">
        <input v-model="form.auto_fetch" type="checkbox" :disabled="!canWrite || (!settings.order_url_set && newUrl.trim() === '')" class="mt-1" />
        <span>{{ t('shoptet.settings.auto_fetch') }}<span class="block text-xs text-neutral-500">{{ t('shoptet.settings.auto_hint') }}</span></span>
      </label>
      <label class="block text-sm max-w-xs">
        <span class="block font-medium mb-1">{{ t('shoptet.settings.interval') }}</span>
        <select v-model.number="form.fetch_interval_minutes" :disabled="!canWrite" class="w-full border border-neutral-300 rounded px-2 h-9 bg-surface">
          <option v-for="m in settings.intervals" :key="m" :value="m">{{ intervalLabel(m) }}</option>
        </select>
      </label>
      <p v-if="settings.last_fetch_at" class="text-xs" :class="settings.last_fetch_status === 'error' ? 'text-danger-500' : 'text-neutral-500'">
        {{ t('shoptet.settings.last_fetch', { date: formatDateTime(settings.last_fetch_at) }) }}
        <template v-if="settings.last_fetch_message"> — {{ settings.last_fetch_message }}</template>
      </p>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="text-lg font-semibold">{{ t('shoptet.settings.orders_title') }}</h2>
      <label class="block text-sm max-w-md">
        <span class="block font-medium mb-1">{{ t('shoptet.settings.default_warehouse') }}</span>
        <select v-model="form.default_warehouse_id" :disabled="!canWrite" class="w-full border border-neutral-300 rounded px-2 h-9 bg-surface">
          <option :value="null">{{ t('shoptet.settings.warehouse_auto') }}</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }} ({{ w.code }})</option>
        </select>
      </label>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.confirm_orders" type="checkbox" :disabled="!canWrite" class="mt-1" />
        <span>{{ t('shoptet.settings.confirm_orders') }}<span class="block text-xs text-neutral-500">{{ t('shoptet.settings.confirm_orders_hint') }}</span></span>
      </label>
      <p class="text-xs text-neutral-500">{{ t('shoptet.settings.matching_hint') }}</p>
    </section>

    <div v-if="canWrite" class="sticky bottom-0 z-10 bg-surface/95 backdrop-blur border-t border-neutral-200 py-3 flex flex-wrap gap-2 justify-end">
      <button :class="btnFilled('primary')" :disabled="busy || !dirty" data-test="save-settings" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ busy ? t('shoptet.saving') : t('shoptet.save') }}
      </button>
    </div>
  </div>
</template>
