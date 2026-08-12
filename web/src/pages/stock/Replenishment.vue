<script setup lang="ts">
/**
 * „Co objednat" (Epic SKLAD „na cestě", § 5.6 / § 7 plánu).
 *
 * Tahle obrazovka je celý payoff epicu. Tlačítko „Doplnění zásob" dřív vedlo na
 * `/stock/items?only_below_min=1`, což je prostý filtr `skladem < minimum` —
 * NEVIDÍ rezervace, zboží na cestě, balení ani minimum odběru, takže ukazoval
 * jiná čísla než API a sváděl objednat podruhé to, co už je na cestě.
 *
 * Čísla se proto berou VÝHRADNĚ z `GET /api/stock/replenishment`:
 *
 *     návrh = max(0, minimum × koef − skladem + rezervováno − na cestě)
 *
 * zaokrouhleno nahoru na balení preferovaného dodavatele a podlahováno jeho
 * minimem odběru. Nic z toho se tady nepočítá znovu — jediný zdroj pravdy je
 * `ReplenishmentService`.
 *
 * Odeslání jde přes `POST /api/stock/purchase-orders/bulk`, který ploché
 * zaškrtnuté řádky seskupí na JEDNU OBJEDNÁVKU NA DODAVATELE. Náhled toho
 * seskupení uživatel vidí předem, aby ho počet vzniklých objednávek
 * nepřekvapil. Objednávky vznikají jako koncepty — odeslání (a tím i vstup
 * do „na cestě") zůstává vědomý krok na detailu objednávky.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  purchaseOrdersApi,
  type StockReplenishmentRow,
  type PurchaseOrderBulkSkipped,
} from '@/api/purchaseOrders'
import { stockApi, type Warehouse } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import FilterBar from '@/components/ui/FilterBar.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const router = useRouter()

const rows = ref<StockReplenishmentRow[]>([])
const loading = ref(false)
const submitting = ref(false)
const total = ref(0)

const filters = reactive({
  warehouse_id: '' as number | '',
  below_min: false,
  coefficient: 1,
})

const warehouses = ref<Warehouse[]>([])

/** stock_item_id → uživatelem upravené množství (prázdné = drž návrh z API). */
const qtyOverride = reactive<Record<number, string>>({})
const selected = ref<Set<number>>(new Set())

const canWrite = computed(() => auth.canWrite('stock.orders.write'))

function num(v: string | null | undefined): number {
  const n = Number(v ?? 0)
  return Number.isFinite(n) ? n : 0
}

/** Bez dodavatele nemá co objednat — bulk endpoint takový řádek stejně vrátí ve `skipped`. */
function orderable(r: StockReplenishmentRow): boolean {
  return r.preferred_vendor !== null
}

function qtyFor(r: StockReplenishmentRow): number {
  const override = qtyOverride[r.stock_item_id]
  return override !== undefined && override !== '' ? num(override) : num(r.suggested_qty)
}

function estimatedCost(r: StockReplenishmentRow): number | null {
  const price = r.preferred_vendor?.purchase_price
  if (price === null || price === undefined) return null
  return qtyFor(r) * num(price)
}

const selectableIds = computed(() => rows.value.filter(orderable).map(r => r.stock_item_id))
const allSelected = computed(() =>
  selectableIds.value.length > 0 && selectableIds.value.every(id => selected.value.has(id)),
)

function toggleAll() {
  const next = new Set<number>()
  if (!allSelected.value) selectableIds.value.forEach(id => next.add(id))
  selected.value = next
}
function toggleRow(r: StockReplenishmentRow) {
  if (!orderable(r)) return
  const next = new Set(selected.value)
  if (next.has(r.stock_item_id)) next.delete(r.stock_item_id)
  else next.add(r.stock_item_id)
  selected.value = next
}

const selectedRows = computed(() => rows.value.filter(r => selected.value.has(r.stock_item_id)))

/** Náhled seskupení, které backend udělá: jedna objednávka na dodavatele. */
interface VendorGroup {
  clientId: number
  vendorName: string
  currencyCode: string
  lines: number
  cost: number
  costKnown: boolean
}
const groups = computed<VendorGroup[]>(() => {
  const map = new Map<number, VendorGroup>()
  for (const r of selectedRows.value) {
    const v = r.preferred_vendor
    if (!v) continue
    const g = map.get(v.client_id) ?? {
      clientId: v.client_id,
      vendorName: v.vendor_name,
      currencyCode: v.currency_code || 'CZK',
      lines: 0,
      cost: 0,
      costKnown: true,
    }
    g.lines += 1
    const c = estimatedCost(r)
    if (c === null) g.costKnown = false
    else g.cost += c
    map.set(v.client_id, g)
  }
  return [...map.values()].sort((a, b) => a.vendorName.localeCompare(b.vendorName, 'cs'))
})

async function load() {
  loading.value = true
  try {
    const r = await purchaseOrdersApi.replenishment({
      warehouse_id: filters.warehouse_id || undefined,
      below_min: filters.below_min || undefined,
      coefficient: filters.coefficient !== 1 ? filters.coefficient : undefined,
      limit: 500,
    })
    rows.value = r.items
    total.value = r.total
    // Zaškrtnutí i ruční množství drž jen pro řádky, které v novém výsledku zůstaly.
    const alive = new Set(r.items.map(i => i.stock_item_id))
    selected.value = new Set([...selected.value].filter(id => alive.has(id)))
    for (const id of Object.keys(qtyOverride)) {
      if (!alive.has(Number(id))) delete qtyOverride[Number(id)]
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function skippedMessage(skipped: PurchaseOrderBulkSkipped[]): string {
  const bySku = skipped.map(s => s.sku || `#${s.stock_item_id}`).join(', ')
  return t('stock.replenishment.toast_skipped', { count: skipped.length, items: bySku })
}

async function createOrders() {
  if (submitting.value || selectedRows.value.length === 0) return
  submitting.value = true
  try {
    const res = await purchaseOrdersApi.bulkCreate({
      warehouse_id: filters.warehouse_id || undefined,
      items: selectedRows.value.map(r => ({
        stock_item_id: r.stock_item_id,
        qty: qtyFor(r),
        vendor_id: r.preferred_vendor?.client_id,
      })),
    })
    toast.success(t('stock.replenishment.toast_created', { count: res.created }))
    if (res.skipped.length > 0) toast.error(skippedMessage(res.skipped))

    if (res.created === 1 && res.orders[0]?.id) {
      router.push(`/stock/purchase-orders/${res.orders[0].id}`)
    } else {
      router.push('/stock/purchase-orders')
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    submitting.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'create',
    label: t('stock.replenishment.create_orders'),
    icon: 'box',
    tier: 'primary',
    variant: 'primary',
    show: canWrite.value,
    disabled: selectedRows.value.length === 0 || submitting.value,
    loading: submitting.value,
    title: selectedRows.value.length === 0 ? t('stock.replenishment.select_first') : undefined,
    run: createOrders,
  },
  {
    key: 'orders',
    label: t('stock.orders.title'),
    icon: 'doc',
    tier: 'secondary',
    variant: 'neutral',
    to: '/stock/purchase-orders',
  },
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'overflow',
    variant: 'neutral',
    run: load,
  },
])

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  await load()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('stock.replenishment.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.replenishment.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <FilterBar :active-count="(filters.warehouse_id !== '' ? 1 : 0) + (filters.below_min ? 1 : 0)">
      <select v-model="filters.warehouse_id" @change="load"
        class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.replenishment.filter_warehouse')">
        <option value="">{{ t('stock.replenishment.filter_warehouse') }}: {{ t('common.all') }}</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <label class="inline-flex items-center gap-1.5 text-sm text-neutral-600 whitespace-nowrap">
        <input v-model="filters.below_min" type="checkbox" @change="load" class="rounded border-neutral-300" />
        {{ t('stock.replenishment.filter_below_min') }}
      </label>
      <label class="inline-flex items-center gap-1.5 text-sm text-neutral-600 whitespace-nowrap"
        :title="t('stock.replenishment.filter_coefficient_hint')">
        {{ t('stock.replenishment.filter_coefficient') }}
        <input v-model.number="filters.coefficient" type="number" min="0.1" step="0.1" @change="load"
          class="h-9 w-20 px-2 border border-neutral-300 rounded-md text-sm" />
      </label>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="rows.length === 0" boxed icon="check"
      :title="t('stock.replenishment.empty_title')"
      :message="t('stock.replenishment.empty_hint')" />

    <template v-else>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-10 text-center">
                  <input type="checkbox" :checked="allSelected" :disabled="selectableIds.length === 0"
                    @change="toggleAll" :aria-label="t('common.select_all')" class="rounded border-neutral-300" />
                </th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.replenishment.col_sku') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.replenishment.col_name') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.replenishment.col_on_hand') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.replenishment.col_reserved') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.replenishment.col_in_transit') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.replenishment.col_min_qty') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('stock.replenishment.col_suggested') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.replenishment.col_vendor') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.replenishment.col_cost') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in rows" :key="r.stock_item_id"
                :class="[orderable(r) ? 'cursor-pointer hover:bg-neutral-50' : 'bg-warning-50/40',
                         selected.has(r.stock_item_id) ? 'bg-primary-50/60' : '']"
                @click="toggleRow(r)">
                <td class="px-3 py-2 text-center" @click.stop>
                  <input type="checkbox" :checked="selected.has(r.stock_item_id)" :disabled="!orderable(r)"
                    @change="toggleRow(r)" :aria-label="r.sku" class="rounded border-neutral-300" />
                </td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                  <RouterLink class="row-link" :to="`/stock/items/${r.stock_item_id}`" @click.stop>{{ r.sku }}</RouterLink>
                </td>
                <td class="px-3 py-2 truncate max-w-[18rem]">{{ r.name }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ r.on_hand }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap"
                  :class="num(r.reserved) > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ r.reserved }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap"
                  :class="num(r.in_transit) > 0 ? 'text-primary-600' : 'text-neutral-400'">{{ r.in_transit }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap text-neutral-500">{{ r.min_qty ?? '—' }}</td>
                <td class="px-3 py-2 text-right" @click.stop>
                  <input :value="qtyOverride[r.stock_item_id] ?? r.suggested_qty" type="number" min="0" step="0.001"
                    :disabled="!orderable(r)" :aria-label="t('stock.replenishment.col_suggested')"
                    @input="qtyOverride[r.stock_item_id] = ($event.target as HTMLInputElement).value"
                    class="h-8 w-24 px-2 text-right font-mono border border-neutral-300 rounded-md text-sm disabled:opacity-50" />
                  <span class="ml-1 text-xs text-neutral-400">{{ r.unit }}</span>
                </td>
                <td class="px-3 py-2 truncate max-w-[14rem]">
                  <span v-if="r.preferred_vendor">{{ r.preferred_vendor.vendor_name }}</span>
                  <span v-else class="text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">
                    {{ t('stock.replenishment.no_vendor') }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="estimatedCost(r) !== null">
                    {{ formatMoney(estimatedCost(r) as number, r.preferred_vendor?.currency_code || 'CZK') }}
                  </template>
                  <span v-else class="text-neutral-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Náhled seskupení: kolik objednávek z toho vlastně vznikne. -->
      <div v-if="groups.length > 0" class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-2">
          {{ t('stock.replenishment.summary_title', { orders: groups.length, lines: selectedRows.length }) }}
        </h2>
        <ul class="text-sm divide-y divide-neutral-100">
          <li v-for="g in groups" :key="g.clientId" class="flex items-center justify-between gap-3 py-1.5">
            <span class="truncate">{{ g.vendorName }}</span>
            <span class="flex items-center gap-4 shrink-0 text-neutral-500">
              <span>{{ t('stock.replenishment.summary_lines', { count: g.lines }) }}</span>
              <span class="font-mono text-neutral-700">
                <template v-if="g.costKnown">{{ formatMoney(g.cost, g.currencyCode) }}</template>
                <template v-else>—</template>
              </span>
            </span>
          </li>
        </ul>
        <p class="text-xs text-neutral-500 mt-3">{{ t('stock.replenishment.summary_hint') }}</p>
      </div>
    </template>
  </div>
</template>
