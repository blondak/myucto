<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'

const supplierStore = useSupplierStore()
const auth = useAuthStore()
const { t } = useI18n()
const props = withDefaults(defineProps<{
  placement?: 'above' | 'below'
  compact?: boolean
  align?: 'center' | 'right'
  fullWidth?: boolean
}>(), {
  placement: 'below',
  compact: false,
  align: 'center',
  fullWidth: false,
})

const open = ref(false)
const switching = ref(false)

const current = computed(() => supplierStore.currentSupplier)
const list = computed(() => supplierStore.availableSuppliers)

async function pick(id: number) {
  if (id === supplierStore.currentSupplierId) {
    open.value = false
    return
  }
  switching.value = true
  auth.clearPermissions()
  supplierStore.setSupplier(id)
  open.value = false

  const refreshed = await auth.refresh()
  if (!refreshed) {
    switching.value = false
    return
  }

  // Pokud je user na detail/edit záznamu, který v jiném supplier neexistuje, přesměruj na list.
  // Jinak hard reload (invaliduje všechny seznamy/cache stores čistě).
  const path = window.location.pathname
  const detailMatch = path.match(/^\/(invoices|clients|projects|bank)\/\d+/)
  if (detailMatch) {
    window.location.href = '/' + detailMatch[1]
  } else {
    window.location.reload()
  }
}
</script>

<template>
  <div v-if="supplierStore.hasMultiple" class="relative" :class="{ 'w-full': props.fullWidth }">
    <button type="button" :disabled="switching" @click="open = !open"
      class="cursor-pointer inline-flex items-center gap-1.5 h-8 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50"
      :class="[
        props.compact ? 'px-2 bg-neutral-50' : 'px-3',
        props.fullWidth ? 'w-full max-w-none justify-between' : 'max-w-72',
      ]">
      <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/></svg>
      <span class="min-w-0 flex-1 truncate text-left font-medium">{{ current?.company_name || '—' }}</span>
      <svg class="w-3 h-3 ml-0.5 transition" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>
    <transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition duration-75 ease-in"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="open"
        class="absolute bg-surface border border-neutral-200 rounded-lg shadow-lg py-1 z-40"
        :class="[
          props.placement === 'above' ? 'bottom-full mb-1' : 'top-full mt-1',
          props.fullWidth
            ? 'left-0 right-0 w-full'
            : [
              'w-72',
              props.align === 'right' ? 'right-0' : 'left-1/2 -translate-x-1/2',
            ],
        ]"
      >
        <button v-for="s in list" :key="s.id" type="button" :disabled="switching" @click="pick(s.id)"
          class="cursor-pointer w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 flex items-start gap-2"
          :class="s.id === supplierStore.currentSupplierId ? 'bg-primary-50' : ''">
          <svg v-if="s.id === supplierStore.currentSupplierId" class="w-4 h-4 mt-0.5 text-primary-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span v-else class="w-4 h-4 mt-0.5 shrink-0"></span>
          <div class="flex-1 min-w-0">
            <div class="font-medium text-neutral-900 truncate">{{ s.company_name }}</div>
            <div v-if="s.ic" class="text-xs text-neutral-500">{{ t('common.ic') }} {{ s.ic }}</div>
          </div>
        </button>
      </div>
    </transition>
    <div v-if="open" @click="open = false" class="fixed inset-0 z-10" aria-hidden="true"></div>
  </div>
</template>
