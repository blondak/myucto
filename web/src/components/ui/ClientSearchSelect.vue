<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

/**
 * Výběr kontaktu se server-side hledáním (SearchableSelect v remote režimu),
 * stejný vzor jako výběr odběratele ve faktuře a VendorPicker, jen bez popisku
 * a tlačítka „nový", aby šel vložit do libovolné karty formuláře.
 */
type Opt = { value: number; label: string; secondary?: string }

const props = withDefaults(defineProps<{
  modelValue: number | null
  /** Název vybraného kontaktu, když ho rodič zná (edit), aby nebyl potřeba další dotaz. */
  selectedLabel?: string | null
  /** Bez dotazu nabídne tuto roli, s dotazem hledá napříč adresářem. */
  role?: 'customers' | 'vendors'
  placeholder?: string
  teleport?: boolean
}>(), {
  selectedLabel: null,
  role: 'customers',
  placeholder: undefined,
  teleport: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  'selected': [client: Client | null]
}>()

const { t } = useI18n()
const cache = new Map<number, Client>()
const options = ref<Opt[]>([])
const loading = ref(false)
let searchVersion = 0

function toOpt(c: Client): Opt {
  return { value: c.id, label: c.company_name, secondary: c.ic ?? undefined }
}

const selectedOption = computed<Opt | null>(() => {
  if (props.modelValue === null) return null
  const cached = cache.get(props.modelValue)
  if (cached) return toOpt(cached)
  return { value: props.modelValue, label: props.selectedLabel ?? `#${props.modelValue}` }
})

async function onSearch(q: string) {
  const version = ++searchVersion
  loading.value = true
  try {
    const query = q.trim()
    const res = await clientsApi.list({ q: query || undefined, role: query ? 'all' : props.role, archived: false, per_page: 50 })
    if (version !== searchVersion) return
    for (const c of res.data) cache.set(c.id, c)
    options.value = res.data.map(toOpt)
  } catch { /* hledání je jen nabídka, chyba nechá výsledky prázdné */ } finally {
    if (version === searchVersion) loading.value = false
  }
}

function onChange(id: number | string | null) {
  const numId = id === null ? null : Number(id)
  emit('update:modelValue', numId)
  emit('selected', numId === null ? null : (cache.get(numId) ?? null))
}
</script>

<template>
  <SearchableSelect
    :model-value="modelValue"
    remote
    :teleport="teleport"
    :loading="loading"
    :options="options"
    :selected-option="selectedOption"
    :placeholder="placeholder"
    :no-results-label="t('common.no_results')"
    @search="onSearch"
    @update:model-value="onChange"
  />
</template>
