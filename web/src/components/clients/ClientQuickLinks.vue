<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { ICONS } from '@/components/ui/buttonStyles'

/** Ikonky „Detail" a „Upravit" klienta (dodavatele) vedle jeho názvu na dokladu. */
const props = defineProps<{ clientId: number; vendor?: boolean }>()

const { t } = useI18n()
const auth = useAuthStore()

const linkClass = 'inline-flex w-7 h-7 items-center justify-center rounded text-neutral-400 hover:text-primary-700 hover:bg-neutral-100 align-middle'
</script>

<template>
  <span v-if="!auth.isClientRole && auth.canRead('clients')" class="inline-flex items-center gap-0.5 ml-1 align-middle">
    <RouterLink :to="{ name: 'client-detail', params: { id: props.clientId } }" :class="linkClass"
      :title="props.vendor ? t('purchase_invoice.filters.vendor_detail') : t('invoice.client_detail')"
      data-test="client-quick-detail">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
    </RouterLink>
    <RouterLink v-if="auth.canWrite('clients')" :to="{ name: 'client-edit', params: { id: props.clientId } }" :class="linkClass"
      :title="props.vendor ? t('purchase_invoice.filters.vendor_edit') : t('invoice.client_edit')"
      data-test="client-quick-edit">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
    </RouterLink>
  </span>
</template>
