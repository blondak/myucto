<script setup lang="ts">
/**
 * § 36a ZDPH + § 23 odst. 7 ZDP — spojené osoby, ceny obvyklé a úprava základu daně.
 *
 * Stránka záměrně odděluje DVĚ různě silná tvrzení:
 *   • „ODCHYLKA" — položka fakturovaná spojené osobě proti MEDIÁNU cen téže položky
 *     fakturovaných nespojeným. Tohle systém spočítat umí, protože srovnání má z vlastních
 *     dat, a je to nejsilnější signál pro doměrek.
 *   • „TRANSAKCE" — všechno ostatní. Cenu obvyklou tu systém NEZNÁ a netvrdí ji; doložit
 *     ji musí účetní. Podložit daňové tvrzení odhadem by bylo horší než mlčet.
 *
 * Úprava základu daně se NEODVOZUJE: § 23/7 ji ukládá jen tehdy, když rozdíl NENÍ
 * uspokojivě doložen, a doložení (posudek, benchmark, obchodní důvod) leží mimo účetní
 * data. Proto ji zadává účetní a důvod je povinný — bez něj se při kontrole neobhájí.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { reportsApi, type RelatedPartyOverview, type RelatedPartyAdjustments } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const year = ref(new Date().getFullYear())
const yearOptions = useYearOptions('combined', year)

const overview = ref<RelatedPartyOverview | null>(null)
const adjustments = ref<RelatedPartyAdjustments | null>(null)
const loading = ref(false)
const error = ref('')

const canWrite = computed(() => auth.canWrite('reports.finalize'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [ov, adj] = await Promise.all([
      reportsApi.relatedParties(`${year.value}-01-01`, `${year.value}-12-31`),
      reportsApi.relatedPartyAdjustments(year.value),
    ])
    overview.value = ov
    adjustments.value = adj
  } catch (e) {
    error.value = apiErrorMessage(e)
    overview.value = null
    adjustments.value = null
  } finally {
    loading.value = false
  }
}

// ── Úprava základu daně (§ 23/7) ─────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const form = ref({ amount: 0, reason: '', movement: 'increase' as 'increase' | 'decrease' })

function openForm() {
  form.value = { amount: 0, reason: '', movement: 'increase' }
  showForm.value = true
}

async function saveAdjustment() {
  if (form.value.amount <= 0) {
    toast.error(t('reports.relatedParties.amount_required'))
    return
  }
  if (!form.value.reason.trim()) {
    toast.error(t('reports.relatedParties.reason_required'))
    return
  }
  saving.value = true
  try {
    await reportsApi.createRelatedPartyAdjustment({
      fiscal_year: year.value,
      amount: Number(form.value.amount),
      reason: form.value.reason.trim(),
      movement: form.value.movement,
    })
    showForm.value = false
    await load()
    toast.success(t('reports.relatedParties.adjustment_saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function removeAdjustment(id: number) {
  if (!confirm(t('reports.relatedParties.delete_confirm'))) return
  try {
    await reportsApi.deleteRelatedPartyAdjustment(id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload', label: t('reports.relatedParties.action_reload'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('reports'), disabled: loading.value,
    loading: loading.value, run: load,
  },
  {
    key: 'adjust', label: t('reports.relatedParties.action_adjust'), icon: 'plus',
    tier: 'secondary', variant: 'warning',
    show: canWrite.value, disabled: loading.value,
    title: t('reports.relatedParties.action_adjust_hint'), run: openForm,
  },
])

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtPct(v: number): string {
  const n = Number(v) || 0
  return (n > 0 ? '+' : '') + new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 1, maximumFractionDigits: 1,
  }).format(n) + ' %'
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function docRoute(tx: { doc_type: string; doc_id: number }) {
  return tx.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id: tx.doc_id } }
    : { name: 'invoice-detail', params: { id: tx.doc_id } }
}

const hasDeviations = computed(() => (overview.value?.deviations.length ?? 0) > 0)
const hasTransactions = computed(() => (overview.value?.transactions.length ?? 0) > 0)

watch(year, load)
onMounted(load)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.relatedParties.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.relatedParties.subtitle') }}</p>
      </div>
      <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('reports.relatedParties.explainer_title') }}</p>
      <p>{{ t('reports.relatedParties.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="overview" class="space-y-4">
      <!-- Měřitelné odchylky — nejsilnější signál, proto nahoře a barevně odlišené. -->
      <div v-if="hasDeviations" class="bg-surface border border-warning-300 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-warning-50 border-b border-warning-200">
          <h2 class="text-sm font-semibold text-warning-800">{{ t('reports.relatedParties.deviations_title') }}</h2>
          <p class="text-xs text-warning-700 mt-0.5">{{ t('reports.relatedParties.deviations_hint') }}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.doc') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.item') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.unit_price') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.market_price') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.deviation') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.samples') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(d, i) in overview.deviations" :key="i">
                <td class="px-2 py-1.5">{{ d.partner_name }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                  <RouterLink :to="docRoute(d)" class="text-primary-600 hover:underline">{{ d.doc_no }}</RouterLink>
                </td>
                <td class="px-2 py-1.5">{{ d.description }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(d.unit_price) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap text-neutral-500">{{ fmtMoney(d.market_price) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                    :class="d.deviation_pct < 0 ? 'text-danger-600' : 'text-warning-700'">
                  {{ fmtPct(d.deviation_pct) }}
                </td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap text-neutral-400">{{ d.samples }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Transakce — bez tvrzení o ceně obvyklé. -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
          <div>
            <h2 class="text-sm font-semibold">{{ t('reports.relatedParties.transactions_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('reports.relatedParties.transactions_hint') }}</p>
          </div>
          <div class="text-sm font-mono font-semibold">{{ fmtMoney(overview.total) }}</div>
        </div>
        <EmptyState v-if="!hasTransactions" accent="neutral" icon="coin" :title="t('reports.relatedParties.no_transactions')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.direction') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.relation') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.doc') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.tax_date') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(tx, i) in overview.transactions" :key="i">
                <td class="px-2 py-1.5">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded"
                        :class="tx.direction === 'issued' ? 'bg-primary-100 text-primary-700' : 'bg-neutral-100 text-neutral-600'">
                    {{ t(`reports.relatedParties.direction.${tx.direction}`) }}
                  </span>
                </td>
                <td class="px-2 py-1.5">{{ tx.partner_name }}</td>
                <td class="px-2 py-1.5 text-neutral-500">
                  {{ tx.related_party_type ? t(`client.related_party_types.${tx.related_party_type}`) : '—' }}
                </td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                  <RouterLink :to="docRoute(tx)" class="text-primary-600 hover:underline">{{ tx.doc_no }}</RouterLink>
                </td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(tx.tax_date) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(tx.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- § 23/7 úpravy základu daně -->
      <div v-if="adjustments" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
          <h2 class="text-sm font-semibold">{{ t('reports.relatedParties.adjustments_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('reports.relatedParties.adjustments_hint') }}</p>
        </div>
        <EmptyState v-if="adjustments.rows.length === 0" dense accent="neutral" icon="clipboardCheck" :title="t('reports.relatedParties.no_adjustments')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.movement') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.relatedParties.col.amount') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.relatedParties.col.reason') }}</th>
                <th class="px-2 py-2 w-8"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in adjustments.rows" :key="a.id">
                <td class="px-2 py-1.5">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded"
                        :class="a.movement === 'increase' ? 'bg-warning-100 text-warning-700' : 'bg-success-100 text-success-700'">
                    {{ t(`reports.relatedParties.movement.${a.movement}`) }}
                  </span>
                </td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(a.amount) }}</td>
                <td class="px-2 py-1.5">{{ a.reason }}</td>
                <td class="px-2 py-1.5 text-right">
                  <button v-if="canWrite" type="button" class="text-danger-500 hover:underline"
                          @click="removeAdjustment(a.id)">×</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 border-t border-neutral-200">
              <tr>
                <td class="px-2 py-2 font-medium">{{ t('reports.relatedParties.net_delta') }}</td>
                <td class="px-2 py-2 text-right font-mono font-bold">{{ fmtMoney(adjustments.net_delta) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- Formulář úpravy -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
         @click.self="showForm = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-5 space-y-3">
        <h3 class="text-lg font-semibold">{{ t('reports.relatedParties.adjustment_form_title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('reports.relatedParties.adjustment_form_hint') }}</p>

        <div>
          <label class="block text-sm mb-1">{{ t('reports.relatedParties.col.movement') }}</label>
          <select v-model="form.movement" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="increase">{{ t('reports.relatedParties.movement.increase') }}</option>
            <option value="decrease">{{ t('reports.relatedParties.movement.decrease') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.relatedParties.col.amount') }}</label>
          <input v-model.number="form.amount" type="number" min="0" step="0.01"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.relatedParties.col.reason') }}</label>
          <textarea v-model="form.reason" rows="3"
                    :placeholder="t('reports.relatedParties.reason_placeholder')"
                    class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="h-9 px-4 border border-neutral-300 rounded-md text-sm"
                  @click="showForm = false">{{ t('common.cancel') }}</button>
          <button type="button" :disabled="saving"
                  class="h-9 px-4 bg-primary-600 text-white rounded-md text-sm disabled:opacity-50"
                  @click="saveAdjustment">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
