<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accountingApi, type JournalSourceSummary, type SourceAction, type SourceFieldFormat } from '@/api/accounting'
import type { PermissionKey } from '@/security/permissions'
import Drawer from '@/components/ui/Drawer.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SourceBlockRenderer from '@/components/accounting/SourceBlockRenderer.vue'

/**
 * Náhled zdrojového dokladu účetního zápisu.
 *
 * READ-ONLY a NAVIGAČNÍ: ukazuje shrnutí + bloky (položky, DPH, úhrady…) a
 * nabízí prokliky. Mutace (vystavení, vyřazení majetku, párování banky) sem
 * ZÁMĚRNĚ nepatří — ty žijí na detailu dokladu, kde je k nim celý kontext.
 *
 * Data tahá jediný generický endpoint /journal/{id}/source klíčovaný ID ZÁPISU;
 * FE nikdy neposílá source_type/source_id, takže přes drawer nejde dotáhnout
 * cizí doklad.
 */
const props = defineProps<{ entryId: number }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(false)
const summary = ref<JournalSourceSummary | null>(null)

async function load(id: number) {
  loading.value = true
  summary.value = null
  try {
    summary.value = await accountingApi.getJournalSource(id)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    emit('close')
  } finally {
    loading.value = false
  }
}

// Drawer se mountuje s v-if, ale entryId se může změnit klikem na jiný řádek.
watch(() => props.entryId, id => { if (id > 0) load(id) }, { immediate: true })

const sourceTypeLabel = computed(() => {
  const type = summary.value?.source_type
  if (!type) return ''
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
})

const title = computed(() => {
  if (!summary.value) return t('common.loading')
  const num = summary.value.title
  return num ? `${sourceTypeLabel.value} ${num}` : sourceTypeLabel.value
})

const statusClass = computed(() => {
  const variant = summary.value?.status?.variant
  switch (variant) {
    case 'success': return 'bg-success-100 text-success-700'
    case 'danger': return 'bg-danger-100 text-danger-700'
    case 'warning': return 'bg-warning-100 text-warning-700'
    case 'primary': return 'bg-primary-100 text-primary-700'
    default: return 'bg-neutral-100 text-neutral-600'
  }
})

const statusLabel = computed(() => {
  const key = summary.value?.status?.key
  if (!key) return ''
  const i18nKey = `accounting.journal.source_drawer.status.${key}`
  const v = t(i18nKey)
  return v === i18nKey ? key : v
})

const unavailableText = computed(() => {
  const reason = summary.value?.unavailable_reason || 'no_source'
  const key = `accounting.journal.source_drawer.unavailable.${reason}`
  const v = t(key)
  return v === key ? t('accounting.journal.source_drawer.unavailable.no_source') : v
})

/**
 * Backend posílá jen `key` + `permission` + cíl; ActionItem[] pro sdílený
 * ActionBar si skládá FE (závazná UI konvence projektu).
 */
const actions = computed<ActionItem[]>(() => {
  const list = summary.value?.actions ?? []
  return list
    .filter((a: SourceAction) => auth.canRead(a.permission as PermissionKey))
    .map((a: SourceAction, i): ActionItem => ({
      key: a.key,
      label: t(`accounting.journal.source_drawer.action.${a.key}`),
      icon: a.key === 'open_pdf' || a.key === 'open_original' ? 'download' : 'doc',
      tier: i === 0 ? 'primary' : 'secondary',
      variant: i === 0 ? 'primary' : 'neutral',
      ...(a.href ? { href: a.href } : {}),
      ...(a.route ? { to: { name: a.route.name, params: a.route.params, query: a.route.query } } : {}),
    }))
})

function label(key: string): string {
  const v = t(key)
  return v === key ? key.split('.').pop() || key : v
}

function fmt(value: unknown, format: SourceFieldFormat): string {
  if (value === null || value === undefined || value === '') return '—'
  const currency = summary.value?.currency || 'CZK'
  switch (format) {
    case 'currency': return formatMoney(Number(value), currency)
    case 'date': return formatDate(String(value))
    case 'percent': return `${Number(value)} %`
    case 'number': return new Intl.NumberFormat().format(Number(value))
    case 'bool': return value ? t('common.yes') : t('common.no')
    case 'doc_ref': return `#${value}`
    default: return String(value)
  }
}
</script>

<template>
  <Drawer :title="title" :subtitle="summary?.subtitle ?? null" width-class="max-w-3xl" @close="emit('close')">
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <template v-else-if="summary">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 pb-4">
        <span v-if="summary.status" class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass">
          {{ statusLabel }}
        </span>
        <span v-else></span>
        <ActionBar v-if="actions.length" :actions="actions" />
      </div>

      <!-- Uzávěrkové typy se syntetickým source_id, provision, manual… -->
      <p v-if="!summary.available" class="mt-4 rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ unavailableText }}
      </p>

      <template v-else>
        <dl v-if="summary.fields.length" class="mt-5 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <div v-for="f in summary.fields" :key="f.key"
               class="flex items-baseline justify-between gap-3 border-b border-neutral-200 py-2 text-sm">
            <dt class="shrink-0 text-neutral-500">{{ label(f.label_key) }}</dt>
            <dd class="min-w-0 truncate text-right font-medium"
                :class="f.format === 'currency' || f.format === 'number' ? 'font-mono' : ''">
              {{ fmt(f.value, f.format) }}
            </dd>
          </div>
        </dl>

        <SourceBlockRenderer
          v-for="b in summary.blocks"
          :key="b.key"
          :block="b"
          :fallback-currency="summary.currency"
        />
      </template>
    </template>
  </Drawer>
</template>
