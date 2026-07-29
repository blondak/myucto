<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'

const props = defineProps<{ ctrl: TablePrefsCtrl }>()
const { t } = useI18n()

const root = ref<HTMLElement | null>(null)
const open = ref(false)
onClickOutside(root, () => { open.value = false })
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      @click="open = !open"
      :aria-expanded="open"
      class="cursor-pointer shrink-0 whitespace-nowrap h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface text-sm text-neutral-700 hover:bg-neutral-50 transition-colors"
      :class="open ? 'bg-neutral-50' : ''"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 4v16" />
      </svg>
      <span>{{ t('common.columns') }}</span>
      <svg class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
      leave-active-class="transition duration-75 ease-in"
      leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="open"
        class="absolute right-0 mt-1 w-56 bg-surface border border-neutral-200 rounded-lg shadow-lg py-1 z-40 max-h-72 overflow-y-auto"
      >
        <label
          v-for="col in ctrl.columns"
          :key="col.key"
          class="flex items-center gap-2.5 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 cursor-pointer"
          :class="col.required ? 'opacity-60 cursor-not-allowed' : ''"
        >
          <input
            type="checkbox"
            class="rounded border-neutral-300 text-primary-600"
            :checked="ctrl.isVisible(col.key)"
            :disabled="col.required"
            @change="ctrl.toggleColumn(col.key)"
          />
          <span class="truncate">{{ t(col.labelKey) }}</span>
        </label>

        <div class="border-t border-neutral-100 mt-1 pt-1">
          <button
            type="button"
            @click="ctrl.resetColumns()"
            class="cursor-pointer w-full text-left px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-50 hover:text-primary-700"
          >{{ t('common.columns_reset') }}</button>
        </div>
      </div>
    </transition>
  </div>
</template>
