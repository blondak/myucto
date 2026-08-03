<script setup lang="ts">
import { watch } from 'vue'
import { useAutoSlug } from '@/composables/useAutoSlug'

/**
 * Dvojice polí „Kód" + „Název" pro číselníky. Když uživatel píše název a kód
 * zatím needitoval ručně, kód se automaticky předvyplní slugem (serverový
 * Slugifier — jediný zdroj pravdy, aby preview == uložená hodnota). Jakmile
 * uživatel do kódu sáhne, auto-generování se vypne; když kód smaže, zas zapne.
 */
const props = withDefaults(defineProps<{
  code: string
  name: string
  codeLabel: string
  nameLabel: string
  /** true u editace existujícího záznamu → kód se nepřepisuje automaticky. */
  editing?: boolean
  codeDisabled?: boolean
  codeMaxlength?: number
  nameMaxlength?: number
  codeContainerClass?: string
  nameContainerClass?: string
  codeTestid?: string
  nameTestid?: string
}>(), {
  editing: false,
  codeDisabled: false,
  codeMaxlength: 50,
  nameMaxlength: 100,
  codeContainerClass: '',
  nameContainerClass: '',
  codeTestid: undefined,
  nameTestid: undefined,
})

const emit = defineEmits<{
  'update:code': [value: string]
  'update:name': [value: string]
}>()

const auto = useAutoSlug((slug) => emit('update:code', slug), { maxLen: props.codeMaxlength })
auto.init(props.code, props.editing)

function onName(e: Event) {
  const val = (e.target as HTMLInputElement).value
  emit('update:name', val)
  auto.fromName(val)
}
function onCode(e: Event) {
  const val = (e.target as HTMLInputElement).value
  emit('update:code', val)
  auto.markManual(val)
}

// Reset stavu při přepnutí create/edit (rodič mění editing + code najednou).
watch(() => props.editing, (v) => auto.init(props.code, v))
</script>

<template>
  <div :class="nameContainerClass">
    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ nameLabel }} <span class="text-danger-500">*</span></label>
    <input
      :value="name"
      @input="onName"
      type="text"
      :maxlength="nameMaxlength"
      :data-testid="nameTestid"
      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-100"
    />
  </div>
  <div :class="codeContainerClass">
    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ codeLabel }} <span class="text-danger-500">*</span></label>
    <input
      :value="code"
      @input="onCode"
      type="text"
      :maxlength="codeMaxlength"
      :disabled="codeDisabled"
      :data-testid="codeTestid"
      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100"
    />
  </div>
</template>
