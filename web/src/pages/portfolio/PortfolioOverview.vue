<script setup lang="ts">
/**
 * Přehled firem pro účetní kancelář — cross-supplier dashboard (Fáze F,
 * audit 2026-07 P2/M). Agreguje přes user_suppliers membership (BE), zobrazuje
 * jen role accountant/admin/readonly (nav gate v AppLayout, route RBAC v BE).
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { portfolioApi, type PortfolioCompany } from '@/api/portfolio'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const router = useRouter()
const supplierStore = useSupplierStore()

const companies = ref<PortfolioCompany[]>([])
const loading = ref(true)
const error = ref('')
const generatedAt = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await portfolioApi.overview()
    companies.value = res.companies
    generatedAt.value = res.generated_at
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** Přepne aktivní firmu (X-Supplier-Id) a naviguje na cílovou agendu — mirror SupplierSwitcher.pick(). */
function switchTo(supplierId: number, path: string) {
  if (supplierId !== supplierStore.currentSupplierId) {
    supplierStore.setSupplier(supplierId)
    window.location.href = path
    return
  }
  router.push(path)
}

function deadlineClass(days: number): string {
  if (days < 0) return 'text-danger-600 font-semibold'
  if (days <= 2) return 'text-danger-500 font-medium'
  if (days <= 7) return 'text-warning-600 font-medium'
  return 'text-neutral-700'
}

function countClass(n: number): string {
  return n > 0 ? 'text-warning-600 font-medium' : 'text-neutral-400'
}

/**
 * „K doúčtování" sčítá tři různé entity (FV / PF / bankovní pohyby), takže napevno
 * zadrátovaný proklik na `/invoices?booked=0` končil na prázdném seznamu, kdykoliv
 * číslo tvořily jen banka nebo přijaté faktury. Cíl proto bere BE rozpad — první
 * neprázdný typ; jednotlivé typy jsou prolinkované vedle čísla.
 */
function unbookedLink(c: PortfolioCompany): string {
  return c.unbooked_breakdown?.[0]?.link ?? '/invoices?booked=0'
}

function periodBadgeClass(status: string): string {
  if (status === 'open') return 'bg-success-50 text-success-600'
  if (status === 'closing') return 'bg-warning-50 text-warning-600'
  return 'bg-neutral-100 text-neutral-500'
}
</script>

<template>
  <div class="max-w-7xl">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('portfolio.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('portfolio.subtitle') }}</p>
      </div>
      <button type="button" @click="load" :class="btnOutline('neutral')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('common.refresh') }}
      </button>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>
    <div v-else-if="companies.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
      {{ t('portfolio.empty') }}
    </div>

    <template v-else>
      <!-- Desktop tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2.5">{{ t('portfolio.col_company') }}</th>
              <th class="text-left px-3 py-2.5">{{ t('portfolio.col_deadline') }}</th>
              <th class="text-right px-3 py-2.5">{{ t('portfolio.col_unbooked') }}</th>
              <th class="text-right px-3 py-2.5">{{ t('portfolio.col_bank_unmatched') }}</th>
              <th class="text-right px-3 py-2.5">{{ t('portfolio.col_purchase_drafts') }}</th>
              <th class="text-left px-3 py-2.5">{{ t('portfolio.col_period') }}</th>
              <th class="text-left px-3 py-2.5">{{ t('portfolio.col_last_bank_import') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in companies" :key="c.supplier_id" class="hover:bg-neutral-50">
              <td class="px-4 py-2.5">
                <button type="button" class="cursor-pointer font-medium text-neutral-900 hover:text-primary-600 text-left" @click="switchTo(c.supplier_id, '/')">
                  {{ c.company_name }}
                </button>
                <div v-if="c.ic" class="text-xs text-neutral-400 font-mono">{{ t('common.ic') }} {{ c.ic }}</div>
              </td>
              <td class="px-3 py-2.5">
                <button v-if="c.next_deadline" type="button" class="cursor-pointer text-left"
                  :class="deadlineClass(c.next_deadline.days)" @click="switchTo(c.supplier_id, '/reports/dph')">
                  {{ c.next_deadline.label }} · {{ c.next_deadline.date }}
                  <span class="text-xs">({{ c.next_deadline.days < 0 ? t('portfolio.overdue_days', { n: Math.abs(c.next_deadline.days) }) : t('portfolio.days_left', { n: c.next_deadline.days }) }})</span>
                </button>
                <span v-else class="text-neutral-400 text-xs">{{ t('portfolio.no_deadline') }}</span>
              </td>
              <td class="px-3 py-2.5 text-right">
                <button type="button" class="cursor-pointer" :class="countClass(c.unbooked_documents)" @click="switchTo(c.supplier_id, unbookedLink(c))">
                  {{ c.unbooked_documents }}
                </button>
                <div v-if="(c.unbooked_breakdown?.length ?? 0) > 1" class="flex justify-end flex-wrap gap-1 mt-1">
                  <button v-for="b in c.unbooked_breakdown" :key="b.key" type="button"
                    class="cursor-pointer px-1.5 rounded-full bg-neutral-100 hover:bg-primary-50 text-xs text-neutral-600 hover:text-primary-700 whitespace-nowrap"
                    @click="switchTo(c.supplier_id, b.link)">
                    {{ t('crm.action_items.breakdown_' + b.key) }} <span class="font-semibold">{{ b.count }}</span>
                  </button>
                </div>
              </td>
              <td class="px-3 py-2.5 text-right">
                <button type="button" class="cursor-pointer" :class="countClass(c.unmatched_bank_transactions)" @click="switchTo(c.supplier_id, '/bank')">
                  {{ c.unmatched_bank_transactions }}
                </button>
              </td>
              <td class="px-3 py-2.5 text-right">
                <button type="button" class="cursor-pointer" :class="countClass(c.purchase_drafts)" @click="switchTo(c.supplier_id, '/purchase-invoices?status=draft')">
                  {{ c.purchase_drafts }}
                </button>
              </td>
              <td class="px-3 py-2.5">
                <span v-if="c.period_status" class="inline-block px-2 py-0.5 rounded text-xs font-medium" :class="periodBadgeClass(c.period_status.status)">
                  {{ c.period_status.fiscal_year }} · {{ t('portfolio.period_status_' + c.period_status.status) }}
                </span>
                <span v-else class="text-neutral-400 text-xs">—</span>
              </td>
              <td class="px-3 py-2.5 text-xs text-neutral-500">
                {{ c.last_bank_import_at ? new Date(c.last_bank_import_at).toLocaleString() : '—' }}
              </td>
              <td class="px-4 py-2.5 text-right">
                <button type="button" class="cursor-pointer px-3 h-8 text-xs bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md whitespace-nowrap"
                  @click="switchTo(c.supplier_id, '/')">
                  {{ t('portfolio.open_company') }} →
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden space-y-3">
        <div v-for="c in companies" :key="c.supplier_id" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <button type="button" class="cursor-pointer font-medium text-neutral-900" @click="switchTo(c.supplier_id, '/')">{{ c.company_name }}</button>
            <span v-if="c.period_status" class="inline-block px-2 py-0.5 rounded text-xs font-medium" :class="periodBadgeClass(c.period_status.status)">
              {{ t('portfolio.period_status_' + c.period_status.status) }}
            </span>
          </div>
          <div v-if="c.next_deadline" class="text-sm mb-2" :class="deadlineClass(c.next_deadline.days)">
            {{ c.next_deadline.label }} · {{ c.next_deadline.date }}
          </div>
          <div class="grid grid-cols-3 gap-2 text-xs text-center">
            <button type="button" class="cursor-pointer bg-neutral-50 rounded p-2" @click="switchTo(c.supplier_id, unbookedLink(c))">
              <div class="text-neutral-400">{{ t('portfolio.col_unbooked') }}</div>
              <div class="font-semibold" :class="countClass(c.unbooked_documents)">{{ c.unbooked_documents }}</div>
            </button>
            <div class="bg-neutral-50 rounded p-2">
              <div class="text-neutral-400">{{ t('portfolio.col_bank_unmatched') }}</div>
              <div class="font-semibold" :class="countClass(c.unmatched_bank_transactions)">{{ c.unmatched_bank_transactions }}</div>
            </div>
            <div class="bg-neutral-50 rounded p-2">
              <div class="text-neutral-400">{{ t('portfolio.col_purchase_drafts') }}</div>
              <div class="font-semibold" :class="countClass(c.purchase_drafts)">{{ c.purchase_drafts }}</div>
            </div>
          </div>
        </div>
      </div>

      <p class="text-xs text-neutral-400 mt-3">{{ t('portfolio.generated_at') }}: {{ new Date(generatedAt).toLocaleString() }}</p>
    </template>
  </div>
</template>
