<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItemPostingDefaults } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import EntityDimensionDefaults from '@/components/dimensions/EntityDimensionDefaults.vue'
import { useResultAccounts } from '@/composables/useResultAccounts'
import { useAuthStore } from '@/stores/auth'

/**
 * Účtování produktu (Účtování podle dimenzí, F1): výchozí účet výnosů a nákladů
 * a výchozí dimenze karty. Položka dokladu s kartou je dostane, pokud je sama nemá;
 * prázdný účet karty dědí z kategorie, pak platí předkontace dokladu.
 *
 * Ukládá se až po uložení karty (`save(id)`), stejně jako ostatní doplňky editoru.
 */
const props = defineProps<{ productId: number }>()

const { t } = useI18n()
const auth = useAuthStore()
const result = useResultAccounts()

const defaults = ref<StockItemPostingDefaults | null>(null)
const revenue = ref('')
const expense = ref('')
const saved = ref('')
const dimensionDefaults = ref<InstanceType<typeof EntityDimensionDefaults> | null>(null)

const canEdit = computed(() => auth.canWrite('stock.items.write'))
const snapshot = () => JSON.stringify([revenue.value.trim(), expense.value.trim()])
const accountsDirty = computed(() => saved.value !== '' && snapshot() !== saved.value)
const dirty = computed(() => accountsDirty.value || !!dimensionDefaults.value?.dirty)

/** Účet, který karta zdědí z kategorie, když vlastní nemá (jen jako nápověda). */
function inherited(kind: 'revenue' | 'expense'): string | null {
  const d = defaults.value
  if (!d) return null
  return d[`${kind}_account_source`] === 'product_category' ? d[`${kind}_account_code`] : null
}

async function load() {
  await result.load()
  if (!result.available.value) return
  try {
    defaults.value = await stockApi.getPostingDefaults(props.productId)
    revenue.value = defaults.value.own_revenue_account_code ?? ''
    expense.value = defaults.value.own_expense_account_code ?? ''
  } catch {
    defaults.value = null
  }
  saved.value = snapshot()
}

/** Uloží změny po uložení karty. Vrací chybovou hlášku, nebo null. */
async function save(id: number): Promise<string | null> {
  if (!canEdit.value || id <= 0) return null
  if (accountsDirty.value) {
    const submitted = snapshot()
    try {
      await stockApi.savePostingAccounts(id, {
        revenue_account_code: revenue.value.trim() || null,
        expense_account_code: expense.value.trim() || null,
      })
      saved.value = submitted
      defaults.value = await stockApi.getPostingDefaults(id).catch(() => defaults.value)
    } catch (e: any) {
      return apiErrorMessage(e)
    }
  }
  await dimensionDefaults.value?.save(id)
  return null
}

onMounted(load)
watch(() => props.productId, load)

defineExpose({ save, dirty })
</script>

<template>
  <div class="p-5 space-y-5" data-test="product-posting-defaults">
    <div>
      <h2 class="text-base font-semibold text-neutral-900">{{ t('product_posting.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('product_posting.hint') }}</p>
    </div>
    <p v-if="!result.doubleEntry.value" class="text-sm text-neutral-500">{{ t('product_posting.double_entry_only') }}</p>
    <template v-else-if="result.available.value">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('product_posting.revenue_account') }}</label>
          <ChartAccountSelect v-model="revenue" :accounts="result.revenueAccounts.value" :disabled="!canEdit"
            :placeholder="inherited('revenue') ?? t('product_posting.by_document')" data-test="product-revenue-account" />
          <p class="mt-1 text-xs text-neutral-500">
            {{ inherited('revenue') ? t('product_posting.inherited', { code: inherited('revenue') }) : t('product_posting.revenue_hint') }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('product_posting.expense_account') }}</label>
          <ChartAccountSelect v-model="expense" :accounts="result.expenseAccounts.value" :disabled="!canEdit"
            :placeholder="inherited('expense') ?? t('product_posting.by_document')" data-test="product-expense-account" />
          <p class="mt-1 text-xs text-neutral-500">
            {{ inherited('expense') ? t('product_posting.inherited', { code: inherited('expense') }) : t('product_posting.expense_hint') }}
          </p>
        </div>
      </div>
      <EntityDimensionDefaults ref="dimensionDefaults" entity="stock/items" :entity-id="productId" />
    </template>
  </div>
</template>
