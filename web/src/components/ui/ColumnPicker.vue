<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'

type ColumnPreset = { key: string; labelKey: string; visibleKeys: string[] | null }
const props = defineProps<{ ctrl: TablePrefsCtrl; presets?: ColumnPreset[] }>()
const { t } = useI18n()

const root = ref<HTMLElement | null>(null)
const open = ref(false)
onClickOutside(root, () => { open.value = false })
function applyPreset(preset: ColumnPreset) {
  if (preset.visibleKeys === null) props.ctrl.resetColumns()
  else props.ctrl.setVisibleColumns(preset.visibleKeys)
  open.value = false
}
function isPresetActive(preset: ColumnPreset): boolean {
  const visible = new Set(preset.visibleKeys ?? props.ctrl.columns.filter(c => !c.defaultHidden).map(c => c.key))
  return props.ctrl.columns.every(c => c.available?.() === false || props.ctrl.isVisible(c.key) === (c.required || visible.has(c.key)))
}
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
        class="absolute right-0 mt-1 w-64 bg-surface border border-neutral-200 rounded-lg shadow-lg py-1 z-40 max-h-[min(70vh,32rem)] flex flex-col"
      >
        <div v-if="presets?.length" class="px-3 pt-1.5 pb-2 border-b border-neutral-100">
          <div class="text-[10px] font-semibold uppercase tracking-wide text-neutral-500 mb-1.5">{{ t('common.columns_presets') }}</div>
          <button v-for="preset in presets" :key="preset.key" type="button"
            class="cursor-pointer w-full text-left px-2.5 py-1.5 rounded-md text-sm inline-flex items-center justify-between gap-2 hover:bg-primary-50 hover:text-primary-700"
            :class="isPresetActive(preset) ? 'bg-primary-50 text-primary-700 font-medium' : 'text-neutral-700'"
            :aria-pressed="isPresetActive(preset)"
            @click="applyPreset(preset)">
            <span>{{ t(preset.labelKey) }}</span>
            <svg v-if="isPresetActive(preset)" class="w-4 h-4 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg>
          </button>
        </div>
        <div class="min-h-0 overflow-y-auto scrollbar-slim py-1">
        <label
          v-for="col in ctrl.columns.filter(c => c.available?.() !== false)"
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
        </div>
        <div v-if="!presets?.length" class="border-t border-neutral-100 mt-1 pt-1">
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
