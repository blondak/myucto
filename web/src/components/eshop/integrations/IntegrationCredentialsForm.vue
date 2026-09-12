<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ConnectorCredentialField } from '@/api/eshopIntegrations'
import type { CredentialDraft } from '@/utils/integrationEditor'

defineProps<{ fields: ConnectorCredentialField[]; stored: string[]; disabled?: boolean }>()
const draft = defineModel<CredentialDraft>({ required: true })
const { t } = useI18n()

const inputType = (field: ConnectorCredentialField) => field.type === 'secret' ? 'password' : field.type === 'url' ? 'url' : 'text'

function setValue(key: string, value: string) {
  draft.value = { ...draft.value, values: { ...draft.value.values, [key]: value } }
}

function toggleClear(key: string, clear: boolean) {
  const rest = draft.value.clear.filter(item => item !== key)
  const values = { ...draft.value.values }
  if (clear) delete values[key]
  draft.value = { values, clear: clear ? [...rest, key] : rest }
}
</script>

<template>
  <p v-if="fields.length === 0" class="text-sm text-neutral-500">{{ t('eshop.integrations.credentials_none') }}</p>
  <div v-else class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2">
    <div v-for="field in fields" :key="field.key" class="min-w-0 text-sm" :data-test="`credential-field-${field.key}`">
      <div class="mb-1 flex flex-wrap items-center gap-2">
        <label :for="`credential-${field.key}`" class="font-medium">{{ t(`eshop.integrations.credential_fields.${field.key}.label`) }}</label>
        <span v-if="field.required" class="text-xs text-danger-700">{{ t('eshop.integrations.credential_required') }}</span>
        <span class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="stored.includes(field.key) ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'" :data-test="`credential-state-${field.key}`">
          {{ t(stored.includes(field.key) ? 'eshop.integrations.credential_stored' : 'eshop.integrations.credential_not_stored') }}
        </span>
      </div>
      <input
        :id="`credential-${field.key}`"
        :type="inputType(field)"
        :value="draft.values[field.key] ?? ''"
        :disabled="disabled || draft.clear.includes(field.key)"
        :placeholder="stored.includes(field.key) ? t('eshop.integrations.credential_placeholder_stored') : field.type === 'url' ? 'https://' : ''"
        autocomplete="new-password"
        spellcheck="false"
        class="form-input w-full"
        :data-test="`credential-${field.key}`"
        @input="setValue(field.key, ($event.target as HTMLInputElement).value)"
      />
      <p class="mt-1 text-xs text-neutral-500">{{ t(`eshop.integrations.credential_fields.${field.key}.hint`) }}</p>
      <label v-if="stored.includes(field.key) && !field.required && !disabled" class="mt-1 inline-flex items-center gap-2 text-xs text-danger-700">
        <input type="checkbox" :checked="draft.clear.includes(field.key)" :data-test="`credential-clear-${field.key}`" @change="toggleClear(field.key, ($event.target as HTMLInputElement).checked)" />
        {{ t('eshop.integrations.credential_clear') }}
      </label>
    </div>
  </div>
</template>
