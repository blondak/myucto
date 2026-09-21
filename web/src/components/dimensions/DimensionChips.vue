<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useDimensions } from '@/composables/useDimensions'

/** Dimenze řádku deníku / dokladu jako štítky „Typ: hodnota". */
const props = defineProps<{
  dimensions: Record<number, number | null> | null | undefined
}>()

const dims = useDimensions()
onMounted(() => { void dims.load() })

const chips = computed(() => Object.entries(props.dimensions ?? {})
  .filter(([, valueId]) => !!valueId)
  .map(([typeId, valueId]) => {
    const type = dims.typeById.value.get(Number(typeId))
    const value = dims.valueById.value.get(Number(valueId))
    return {
      key: `${typeId}-${valueId}`,
      type: type?.name ?? '',
      label: dims.valueLabel(Number(valueId)),
      closed: value ? !value.is_active : false,
      path: value ? dims.pathOf(value).join(' › ') : '',
    }
  }))
</script>

<template>
  <span v-if="dims.enabled.value && chips.length > 0" class="inline-flex flex-wrap gap-1" data-test="dimension-chips">
    <span v-for="chip in chips" :key="chip.key"
          :title="chip.path ? `${chip.path} › ${chip.label}` : chip.label"
          class="inline-flex items-center gap-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 whitespace-nowrap"
          :class="{ 'opacity-60': chip.closed }">
      <span v-if="chip.type" class="text-primary-500">{{ chip.type }}:</span>{{ chip.label }}
    </span>
  </span>
</template>
