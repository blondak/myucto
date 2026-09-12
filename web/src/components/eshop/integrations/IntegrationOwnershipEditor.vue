<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IntegrationOwner } from '@/api/eshopIntegrations'
import { FREE_FIELD_PATTERN, OWNERS, fieldI18nKey, type OwnershipRow } from '@/utils/integrationEditor'
import { btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ freeFields: boolean; disabled?: boolean }>()
const rows = defineModel<OwnershipRow[]>({ required: true })
const { t } = useI18n()
const newField = ref('')
const newFieldError = ref('')

const AREAS = ['product', 'price', 'stock', 'order'] as const
const groups = computed(() => {
  const known = AREAS
    .map(area => ({ area: area as string, rows: rows.value.filter(row => row.known && row.area === area) }))
    .filter(group => group.rows.length > 0)
  const custom = rows.value.filter(row => !row.known)
  return custom.length ? [...known, { area: 'custom', rows: custom }] : known
})

const activeClass: Record<IntegrationOwner, string> = {
  local: 'bg-primary-600 text-white',
  remote: 'bg-accent-600 text-white',
  manual: 'bg-warning-500 text-white',
}

function label(row: OwnershipRow) {
  return row.known ? t(`eshop.integrations.fields.${fieldI18nKey(row.key)}.label`) : row.key
}

function setOwner(row: OwnershipRow, owner: IntegrationOwner) {
  if (props.disabled) return
  row.owner = owner
}

function addField() {
  const key = newField.value.trim()
  newFieldError.value = ''
  if (!FREE_FIELD_PATTERN.test(key)) {
    newFieldError.value = t('eshop.integrations.ownership_invalid_key')
    return
  }
  if (rows.value.some(row => row.key === key)) {
    newFieldError.value = t('eshop.integrations.ownership_duplicate', { key })
    return
  }
  rows.value = [...rows.value, { key, owner: 'manual', known: false, defaultOwner: null, area: null }]
  newField.value = ''
}

function removeField(key: string) {
  rows.value = rows.value.filter(row => row.key !== key)
}
</script>

<template>
  <div class="space-y-4">
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <div v-for="owner in OWNERS" :key="owner" class="rounded-md border border-neutral-200 p-3">
        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium" :class="activeClass[owner]">{{ t(`eshop.integrations.owner.${owner}`) }}</span>
        <p class="mt-1.5 text-xs text-neutral-600">{{ t(`eshop.integrations.owner_help.${owner}`) }}</p>
      </div>
    </div>

    <div v-for="group in groups" :key="group.area" class="rounded-md border border-neutral-200">
      <h4 class="border-b border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-neutral-600">{{ t(`eshop.integrations.area.${group.area}`) }}</h4>
      <ul class="divide-y divide-neutral-100">
        <li v-for="row in group.rows" :key="row.key" class="flex flex-col gap-2 p-3 md:flex-row md:items-center md:justify-between" :data-test="`ownership-row-${row.key}`">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-medium">{{ label(row) }}</span>
              <code class="text-xs text-neutral-500">{{ row.key }}</code>
              <span v-if="row.known && row.owner === row.defaultOwner" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-600">{{ t('eshop.integrations.ownership_default') }}</span>
              <span v-if="!row.known" class="rounded bg-accent-50 px-1.5 py-0.5 text-[11px] text-accent-700">{{ t('eshop.integrations.ownership_custom') }}</span>
            </div>
            <p v-if="row.known" class="mt-0.5 text-xs text-neutral-500">{{ t(`eshop.integrations.fields.${fieldI18nKey(row.key)}.hint`) }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <div role="radiogroup" :aria-label="label(row)" class="inline-flex flex-wrap overflow-hidden rounded-md border border-neutral-300">
              <label v-for="owner in OWNERS" :key="owner" class="cursor-pointer whitespace-nowrap px-2.5 py-1 text-xs font-medium transition-colors" :class="[row.owner === owner ? activeClass[owner] : 'text-neutral-600 hover:bg-neutral-50', disabled ? 'cursor-not-allowed opacity-60' : '']">
                <input type="radio" class="sr-only" :name="`owner-${row.key}`" :value="owner" :checked="row.owner === owner" :disabled="disabled" :data-test="`owner-${row.key}-${owner}`" @change="setOwner(row, owner)" />{{ t(`eshop.integrations.owner.${owner}`) }}
              </label>
            </div>
            <button v-if="!row.known && !disabled" type="button" :class="btnIconSm('danger')" :title="t('eshop.integrations.ownership_remove')" :aria-label="t('eshop.integrations.ownership_remove')" @click="removeField(row.key)">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
            </button>
          </div>
        </li>
      </ul>
    </div>

    <div v-if="freeFields && !disabled" class="rounded-md border border-dashed border-neutral-300 p-3">
      <label class="block text-sm">
        <span class="mb-1 block font-medium">{{ t('eshop.integrations.ownership_add_label') }}</span>
        <span class="mb-2 block text-xs text-neutral-500">{{ t('eshop.integrations.ownership_add_hint') }}</span>
        <span class="flex flex-wrap gap-2">
          <input v-model="newField" class="form-input min-w-0 flex-1 font-mono text-sm" :placeholder="t('eshop.integrations.ownership_add_placeholder')" data-test="ownership-new-field" @keydown.enter.prevent="addField" />
          <button type="button" :class="btnOutlineSm('primary')" data-test="ownership-add" @click="addField">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('eshop.integrations.ownership_add') }}
          </button>
        </span>
      </label>
      <p v-if="newFieldError" class="mt-1 text-xs text-danger-700">{{ newFieldError }}</p>
    </div>
  </div>
</template>
