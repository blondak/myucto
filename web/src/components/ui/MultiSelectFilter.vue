<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'

export interface MultiSelectOption {
  value: string
  label: string
  secondary?: string
}

/**
 * Filtr „vyber N z číselníku" — tlačítko s odznáčkem počtu + rozbalovací seznam
 * se zaškrtávátky a hledáním.
 *
 * Why nová komponenta místo režimu v `SearchableSelect`: ten je combobox nad JEDNOU
 * hodnotou (`modelValue: T | null`, text v inputu = label vybrané položky) a používá
 * ho půlka editorů. Přidat mu „multi" znamená rozdvojit typ modelu i chování inputu
 * ve všech těch místech; tady stačí zaškrtávátka. Vzhled i mechanika (onClickOutside,
 * transition, `h-9` lišta) kopírují `ColumnPicker`, ať filtry vypadají jako jedna rodina.
 *
 * Hodnoty jsou stringy schválně — seznam míchá ID číselníku se sentinelem
 * (`none` = položka bez kategorie), takže číselný typ by stejně nestačil.
 */
const props = withDefaults(defineProps<{
  modelValue: string[]
  options: MultiSelectOption[]
  /** Popisek tlačítka, když není nic vybráno („Kategorie tržby: vše"). */
  label: string
  /** Popisek tlačítka, když je něco vybráno („Kategorie tržby"). */
  activeLabel?: string
  title?: string
  disabled?: boolean
  /** Tlačítko dostane výstražný odstín — pro režim „skrýt vybrané". */
  tone?: 'primary' | 'warning'
}>(), {
  activeLabel: '',
  title: '',
  disabled: false,
  tone: 'primary',
})

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
const open = ref(false)
const query = ref('')
onClickOutside(root, () => { open.value = false })

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter(o =>
    o.label.toLowerCase().includes(q) || (o.secondary?.toLowerCase().includes(q) ?? false)
  )
})

const selectedCount = computed(() => props.modelValue.length)
const buttonLabel = computed(() => {
  if (selectedCount.value === 0) return props.label
  if (selectedCount.value === 1) {
    const only = props.options.find(o => o.value === props.modelValue[0])
    if (only) return only.label
  }
  return props.activeLabel || props.label
})

function isSelected(value: string): boolean {
  return props.modelValue.includes(value)
}

function toggle(value: string): void {
  emit('update:modelValue', isSelected(value)
    ? props.modelValue.filter(v => v !== value)
    : [...props.modelValue, value])
}

function clear(): void {
  emit('update:modelValue', [])
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      :disabled="disabled"
      :title="title || label"
      :aria-expanded="open"
      @click="open = !open"
      class="cursor-pointer shrink-0 whitespace-nowrap h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md border bg-surface text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      :class="[
        selectedCount > 0 && tone === 'warning'
          ? 'border-warning-500 text-warning-600 hover:bg-warning-50'
          : selectedCount > 0
            ? 'border-primary-400 text-primary-700 hover:bg-primary-50'
            : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50',
        open ? 'bg-neutral-50' : '',
      ]"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h13M7 12h13M7 17h13M3 7h.01M3 12h.01M3 17h.01" />
      </svg>
      <span class="truncate max-w-40">{{ buttonLabel }}</span>
      <span
        v-if="selectedCount > 1"
        class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full text-white text-xs font-medium tabular-nums"
        :class="tone === 'warning' ? 'bg-warning-500' : 'bg-primary-600'"
      >{{ selectedCount }}</span>
      <svg class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
        class="absolute left-0 mt-1 w-64 bg-surface border border-neutral-200 rounded-lg shadow-lg py-1 z-40"
      >
        <div class="px-2 pb-1">
          <input
            v-model="query"
            type="search"
            :placeholder="t('common.search')"
            class="w-full h-8 px-2 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          />
        </div>

        <div class="max-h-64 overflow-y-auto">
          <div v-if="filtered.length === 0" class="px-3 py-2 text-sm text-neutral-400">
            {{ t('common.no_results') }}
          </div>
          <label
            v-for="o in filtered"
            :key="o.value"
            class="flex items-center gap-2.5 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 cursor-pointer"
          >
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600"
              :checked="isSelected(o.value)"
              @change="toggle(o.value)"
            />
            <span class="truncate">{{ o.label }}</span>
            <span v-if="o.secondary" class="ml-auto text-xs text-neutral-400 truncate">{{ o.secondary }}</span>
          </label>
        </div>

        <div v-if="selectedCount > 0" class="border-t border-neutral-100 mt-1 pt-1">
          <button
            type="button"
            @click="clear()"
            class="cursor-pointer w-full text-left px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-50 hover:text-primary-700"
          >{{ t('common.bulk_clear') }}</button>
        </div>
      </div>
    </transition>
  </div>
</template>
