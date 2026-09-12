<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import ShoptetOrdersTab from '@/components/eshop/shoptet/ShoptetOrdersTab.vue'
import ShoptetDocumentsTab from '@/components/eshop/shoptet/ShoptetDocumentsTab.vue'
import ShoptetFeedTab from '@/components/eshop/shoptet/ShoptetFeedTab.vue'
import ShoptetSettingsTab from '@/components/eshop/shoptet/ShoptetSettingsTab.vue'
import { shoptetApi, type ShoptetSettingsPayload } from '@/api/shoptet'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

type Tab = 'orders' | 'documents' | 'feed' | 'settings'
const tabs: { key: Tab; label: string }[] = [
  { key: 'orders', label: t('shoptet.tabs.orders') },
  { key: 'documents', label: t('shoptet.tabs.documents') },
  { key: 'feed', label: t('shoptet.tabs.feed') },
  { key: 'settings', label: t('shoptet.tabs.settings') },
]
const keys = tabs.map(tt => tt.key) as string[]
const tab = ref<Tab>(keys.includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'orders')
watch(tab, v => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})
watch(() => route.query.tab, v => {
  if (typeof v === 'string' && keys.includes(v) && v !== tab.value) tab.value = v as Tab
})

const payload = ref<ShoptetSettingsPayload | null>(null)
const error = ref('')
const canWrite = computed(() => auth.canWrite('eshop.write'))

async function load() {
  error.value = ''
  try {
    payload.value = await shoptetApi.settings()
  } catch (e) {
    error.value = apiErrorMessage(e, t('shoptet.load_failed'))
  }
}
function updated(next: ShoptetSettingsPayload) {
  payload.value = next
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('shoptet.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('shoptet.subtitle') }}</p>
    </div>

    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt.key" :data-test="`tab-${tt.key}`"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt.key ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        @click="tab = tt.key">
        {{ tt.label }}
      </button>
    </div>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
    <p v-else-if="!payload" class="text-sm text-neutral-500">{{ t('shoptet.loading') }}</p>

    <template v-else>
      <ShoptetOrdersTab v-if="tab === 'orders'" :settings="payload.settings" :can-write="canWrite"
        @open-settings="tab = 'settings'" @changed="load" />
      <ShoptetDocumentsTab v-else-if="tab === 'documents'" :settings="payload.settings" :can-write="canWrite"
        @open-settings="tab = 'settings'" />
      <ShoptetFeedTab v-else-if="tab === 'feed'" :settings="payload.settings" :warehouses="payload.warehouses"
        :can-write="canWrite" @updated="updated" />
      <ShoptetSettingsTab v-else :settings="payload.settings" :warehouses="payload.warehouses"
        :can-write="canWrite" @updated="updated" />
    </template>
  </div>
</template>
