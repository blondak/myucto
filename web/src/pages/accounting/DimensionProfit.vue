<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { dimensionsApi, type DimensionProfitReport } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

/**
 * Výsledovka po dimenzi: hodnoty jednoho typu (strom) × výnosy, náklady, výsledek.
 * Globální typ jde sečíst za všechny firmy skupiny, ke kterým má uživatel přístup.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const dims = useDimensions()

const year = new Date().getFullYear()
const typeId = ref<number | null>(Number(route.query.type_id) || null)
const from = ref(String(route.query.from || `${year}-01-01`))
const to = ref(String(route.query.to || `${year}-12-31`))
const groupScope = ref(route.query.scope === 'group')
const report = ref<DimensionProfitReport | null>(null)
const loading = ref(false)
const failed = ref(false)
const collapsed = ref<Set<number>>(new Set())

const types = computed(() => dims.types.value.filter(ty => ty.is_active))
const selectedType = computed(() => types.value.find(ty => ty.id === typeId.value) ?? null)

async function load() {
  if (!typeId.value || !from.value || !to.value) return
  loading.value = true
  failed.value = false
  try {
    report.value = await dimensionsApi.profit({
      type_id: typeId.value,
      from: from.value,
      to: to.value,
      ...(groupScope.value && selectedType.value?.level === 'global' ? { scope: 'group' as const } : {}),
    })
    void router.replace({ query: { type_id: String(typeId.value), from: from.value, to: to.value, ...(groupScope.value ? { scope: 'group' } : {}) } })
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await dims.load()
  if (!typeId.value) typeId.value = types.value[0]?.id ?? null
  await load()
})

watch(typeId, () => { collapsed.value = new Set() })

const visibleRows = computed(() => {
  const hidden = new Set<number>()
  return (report.value?.rows ?? []).filter((row) => {
    if (row.parent_id !== null && (hidden.has(row.parent_id) || collapsed.value.has(row.parent_id))) {
      hidden.add(row.value_id)
      return false
    }
    return true
  })
})

function toggle(valueId: number) {
  const next = new Set(collapsed.value)
  if (next.has(valueId)) next.delete(valueId)
  else next.add(valueId)
  collapsed.value = next
}

function money(v: number) {
  return formatMoney(v, 'CZK')
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('dimensions.profit_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('dimensions.profit_subtitle') }}</p>
    </div>

    <EmptyState v-if="!dims.enabled.value" boxed icon="tag" :title="t('dimensions.disabled_title')" :message="t('dimensions.disabled_hint')" />

    <template v-else>
      <div class="flex flex-wrap items-end gap-3 mb-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.filter_type') }}</label>
          <select v-model="typeId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="profit-type">
            <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_from') }}</label>
          <input v-model="from" type="date" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_to') }}</label>
          <input v-model="to" type="date" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <label v-if="selectedType?.level === 'global'" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
          <input v-model="groupScope" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.profit_group_scope') }}
        </label>
        <button type="button" :disabled="loading || !typeId" :class="btnFilled('primary')" class="whitespace-nowrap" @click="load">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('dimensions.profit_show') }}
        </button>
      </div>

      <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
      <EmptyState v-else-if="types.length === 0" boxed icon="tag" :title="t('dimensions.types_empty')" to="/company/dimensions" :cta="t('dimensions.title')" />
      <template v-else-if="report">
        <p v-if="report.supplier_ids.length > 1 || report.hidden_companies > 0" class="mb-3 text-sm text-neutral-600">
          {{ t('dimensions.profit_companies', { count: report.supplier_ids.length }) }}
          <span v-if="report.hidden_companies > 0" class="text-warning-700">{{ t('dimensions.profit_hidden', { count: report.hidden_companies }) }}</span>
        </p>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm" data-test="profit-table">
            <thead class="bg-neutral-50 text-neutral-600">
              <tr>
                <th class="px-4 py-3 text-left font-medium">{{ selectedType?.name }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_revenue') }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_cost') }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_result') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in visibleRows" :key="row.value_id" :class="{ 'opacity-60': !row.is_active, 'font-medium': row.has_children }">
                <td class="px-4 py-2">
                  <div class="flex items-center gap-1" :style="{ paddingLeft: `${row.depth * 1.25}rem` }">
                    <button v-if="row.has_children" type="button" class="w-5 h-5 inline-flex items-center justify-center text-neutral-500"
                            :aria-expanded="!collapsed.has(row.value_id)" @click="toggle(row.value_id)">
                      <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-90': !collapsed.has(row.value_id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </button>
                    <span v-else class="w-5" />
                    <span class="font-mono text-xs text-neutral-500">{{ row.code }}</span>
                    <span class="truncate">{{ row.name }}</span>
                  </div>
                </td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :title="row.has_children ? t('dimensions.profit_own', { amount: money(row.own.revenue) }) : undefined">{{ money(row.total.revenue) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :title="row.has_children ? t('dimensions.profit_own', { amount: money(row.own.cost) }) : undefined">{{ money(row.total.cost) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :class="row.total.result < 0 ? 'text-danger-600' : ''">{{ money(row.total.result) }}</td>
              </tr>
              <tr class="text-neutral-500 italic">
                <td class="px-4 py-2">{{ t('dimensions.profit_unassigned') }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ money(report.unassigned.revenue) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ money(report.unassigned.cost) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ money(report.unassigned.result) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(report.totals.revenue) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(report.totals.cost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" :class="report.totals.result < 0 ? 'text-danger-600' : ''">{{ money(report.totals.result) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
