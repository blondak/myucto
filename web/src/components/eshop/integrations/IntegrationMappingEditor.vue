<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ConnectorMappingType, LookupOption, MappingSource } from '@/api/eshopIntegrations'
import { isPlaceholderValue, type MappingRow, type MappingRows } from '@/utils/integrationEditor'
import { btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  types: ConnectorMappingType[]
  lookups: Partial<Record<MappingSource, LookupOption[]>>
  disabled?: boolean
}>()
const rows = defineModel<MappingRows>({ required: true })
const { t } = useI18n()

const list = (type: string): MappingRow[] => rows.value[type] ?? []
const available = (source: MappingSource) => props.lookups[source] ?? []

function add(type: string) {
  rows.value = { ...rows.value, [type]: [...list(type), { local: '', remote: '' }] }
}

function remove(type: string, index: number) {
  rows.value = { ...rows.value, [type]: list(type).filter((_, i) => i !== index) }
}

// Neaktivní hodnotu nabídneme jen tam, kde už je vybraná, aby se uložené mapování neztratilo.
function options(source: MappingSource, current: string) {
  return available(source).filter(option => option.active || option.value === current)
}

function usedElsewhere(type: string, value: string, index: number) {
  return list(type).some((row, i) => i !== index && row.local === value)
}
</script>

<template>
  <div class="space-y-3">
    <section v-for="mapping in types" :key="mapping.type" class="rounded-md border border-neutral-200 p-3" :data-test="`mapping-${mapping.type}`">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <h4 class="text-sm font-medium">{{ t(`eshop.integrations.mapping_types.${mapping.type}.title`) }}</h4>
          <p class="text-xs text-neutral-500">{{ t(`eshop.integrations.mapping_types.${mapping.type}.hint`) }}</p>
        </div>
        <button v-if="!disabled" type="button" :class="btnOutlineSm('primary')" :disabled="available(mapping.source).length === 0" :data-test="`mapping-add-${mapping.type}`" @click="add(mapping.type)">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('eshop.integrations.mapping_add') }}
        </button>
      </div>
      <p v-if="available(mapping.source).length === 0" class="mt-2 text-xs text-warning-700">{{ t('eshop.integrations.mapping_no_local') }}</p>
      <p v-else-if="list(mapping.type).length === 0" class="mt-2 text-xs text-neutral-500">{{ t('eshop.integrations.mapping_empty') }}</p>
      <div v-else class="mt-3 space-y-2">
        <div class="hidden gap-2 text-xs font-medium text-neutral-500 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_1.75rem]">
          <span>{{ t('eshop.integrations.mapping_local') }}</span>
          <span>{{ t('eshop.integrations.mapping_remote') }}</span>
          <span />
        </div>
        <div v-for="(row, index) in list(mapping.type)" :key="index" class="flex flex-col gap-2 rounded border border-neutral-100 p-2 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_1.75rem] sm:items-center sm:border-0 sm:p-0" :data-test="`mapping-row-${mapping.type}-${index}`">
          <label class="min-w-0 text-sm">
            <span class="mb-1 block text-xs text-neutral-500 sm:hidden">{{ t('eshop.integrations.mapping_local') }}</span>
            <select v-model="row.local" class="form-select w-full" :disabled="disabled" :data-test="`mapping-local-${mapping.type}-${index}`">
              <option value="">{{ t('eshop.integrations.mapping_select') }}</option>
              <option v-for="option in options(mapping.source, row.local)" :key="option.value" :value="option.value" :disabled="usedElsewhere(mapping.type, option.value, index)">
                {{ option.label }}{{ option.active ? '' : ` (${t('eshop.integrations.mapping_inactive')})` }}
              </option>
            </select>
          </label>
          <label class="min-w-0 text-sm">
            <span class="mb-1 block text-xs text-neutral-500 sm:hidden">{{ t('eshop.integrations.mapping_remote') }}</span>
            <input v-model="row.remote" maxlength="190" class="form-input w-full" :class="isPlaceholderValue(row.remote) ? 'border-warning-500' : ''" :disabled="disabled" :placeholder="t('eshop.integrations.mapping_remote_placeholder')" :data-test="`mapping-remote-${mapping.type}-${index}`" />
            <span v-if="isPlaceholderValue(row.remote)" class="mt-1 block text-xs text-warning-700" :data-test="`mapping-placeholder-${mapping.type}-${index}`">{{ t('eshop.integrations.mapping_placeholder') }}</span>
          </label>
          <button v-if="!disabled" type="button" class="self-end sm:self-center" :class="btnIconSm('danger')" :title="t('eshop.integrations.mapping_remove')" :aria-label="t('eshop.integrations.mapping_remove')" :data-test="`mapping-remove-${mapping.type}-${index}`" @click="remove(mapping.type, index)">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
          </button>
        </div>
      </div>
    </section>
  </div>
</template>
