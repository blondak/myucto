<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import ExtractionWarningText from '@/components/purchase/ExtractionWarningText.vue'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'
import {
  purchaseInvoicesApi,
  type ExpenseKind,
  type ExtractionExpenseKindProposal,
  type PurchaseInvoice,
} from '@/api/purchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { withoutExpenseKindSection } from '@/utils/extractionWarning'

/**
 * Kontrola AI vytěžených přijatých faktur po importu — faktura po faktuře.
 *
 * Řádky, u kterých hlášení navrhuje druh nákladu a druh zatím není zvolený, jsou
 * orámované červeně a návrh jde převzít jedním klikem. Uložení jde přes úzký
 * endpoint `expense-kinds`, takže funguje i u dokladu, který import rovnou označil
 * jako zaplacený (dřív jen přes vynucenou úpravu).
 */
const props = withDefaults(defineProps<{
  invoiceIds: number[]
  /** Přeskočí faktury bez hlášení (po hromadném importu jich je většina v pořádku). */
  onlyFlagged?: boolean
  /** Když není co kontrolovat, zavřít bez oznámení (rodič pokračuje vlastním krokem). */
  silentWhenEmpty?: boolean
}>(), { onlyFlagged: true, silentWhenEmpty: false })

const emit = defineEmits<{
  /** navigated = uživatel odešel na detail dokladu, rodič už nemá kam přesměrovávat. */
  (e: 'close', navigated?: boolean): void
  (e: 'updated', invoice: PurchaseInvoice): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const EXPENSE_KINDS: ExpenseKind[] = ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset']

const queue = ref<PurchaseInvoice[]>([])
const index = ref(0)
const loading = ref(true)
const saving = ref(false)
const kinds = reactive<Record<number, ExpenseKind | null>>({})

const invoice = computed<PurchaseInvoice | null>(() => queue.value[index.value] ?? null)
const isLast = computed(() => index.value >= queue.value.length - 1)

const proposals = computed(() => {
  const map = new Map<number, ExtractionExpenseKindProposal>()
  for (const p of invoice.value?.extraction_review?.expense_kinds ?? []) map.set(p.order_index, p)
  return map
})

const items = computed(() => (invoice.value?.items ?? []).filter((it) => it.id !== undefined))
const otherWarning = computed(() => withoutExpenseKindSection(invoice.value?.extraction_warning))

function proposalFor(orderIndex: number): ExtractionExpenseKindProposal | undefined {
  return proposals.value.get(orderIndex)
}
function needsAttention(it: { id?: number; order_index: number }): boolean {
  return !!proposalFor(it.order_index) && !kinds[it.id as number]
}
const pendingProposals = computed(() => items.value.filter((it) => {
  const p = proposalFor(it.order_index)
  return p && kinds[it.id as number] !== p.kind
}))
const attentionCount = computed(() => items.value.filter(needsAttention).length)

const readOnlyReason = computed<string | null>(() => {
  const inv = invoice.value
  if (!inv) return null
  if (inv.status === 'cancelled') return t('purchase_invoice.extraction_review.readonly_cancelled')
  // `locked.is_locked` znamená „zamčeno pro klienta" (účetní spravuje, datum…) —
  // účetní blokuje jen uzavřené období, stejně jako backend (GuardsDocumentLock).
  const closedPeriod = ['closed', 'approved'].includes(inv.locked?.period_status ?? '')
  if (closedPeriod || (auth.isClientRole && inv.locked?.is_locked)) return t('purchase_invoice.extraction_review.readonly_locked')
  if (inv.status !== 'draft' && auth.isClientRole) return t('purchase_invoice.extraction_review.readonly_client')
  return null
})

function resetKinds(): void {
  for (const k of Object.keys(kinds)) delete kinds[Number(k)]
  for (const it of items.value) kinds[it.id as number] = it.expense_kind ?? null
}

function applyProposal(it: { id?: number; order_index: number }): void {
  const p = proposalFor(it.order_index)
  if (p) kinds[it.id as number] = p.kind
}
function applyAll(): void {
  for (const it of pendingProposals.value) applyProposal(it)
}

function goTo(i: number): void {
  index.value = i
  resetKinds()
}

async function save(): Promise<void> {
  const inv = invoice.value
  if (!inv || saving.value) return
  saving.value = true
  try {
    let updated: PurchaseInvoice = inv
    const changed = items.value
      .filter((it) => (it.expense_kind ?? null) !== kinds[it.id as number])
      .map((it) => ({ id: it.id as number, expense_kind: kinds[it.id as number] }))
    // Odrážky hlášení u řádků, které teď druh mají, odebere backend sám; ostatní
    // body hlášení zůstávají, dokud je uživatel neoznačí jako vyřešené.
    if (changed.length && !readOnlyReason.value) {
      const res = await purchaseInvoicesApi.setExpenseKinds(inv.id, changed)
      if (res._repost) toast.info(t('purchase_invoice.extraction_review.reposted'))
      updated = res
    }
    queue.value[index.value] = updated
    emit('updated', updated)
    next()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

const resolving = ref(false)
async function resolveSection(section: string): Promise<void> {
  const inv = invoice.value
  if (!inv || resolving.value) return
  resolving.value = true
  try {
    const updated = await purchaseInvoicesApi.dismissExtractionWarning(inv.id, section)
    queue.value[index.value] = updated
    emit('updated', updated)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    resolving.value = false
  }
}

function next(): void {
  if (isLast.value) {
    toast.success(t('purchase_invoice.extraction_review.done'))
    emit('close')
    return
  }
  goTo(index.value + 1)
}

onMounted(async () => {
  try {
    const loaded = await Promise.all(props.invoiceIds.map((id) => purchaseInvoicesApi.get(id).catch(() => null)))
    queue.value = loaded.filter((inv): inv is PurchaseInvoice =>
      inv !== null && (!props.onlyFlagged || !!inv.extraction_warning))
  } finally {
    loading.value = false
  }
  if (!queue.value.length) {
    if (!props.silentWhenEmpty) toast.success(t('purchase_invoice.extraction_review.nothing'))
    emit('close')
    return
  }
  goTo(0)
})
</script>

<template>
  <Modal v-if="!loading && invoice" :title="t('purchase_invoice.extraction_review.title')" width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4">
      <!-- Hlavička dokladu -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="font-medium text-neutral-900 truncate">{{ invoice.vendor_company_name }}</div>
          <div class="text-sm text-neutral-500">
            {{ invoice.vendor_invoice_number || invoice.varsymbol }} · {{ formatDate(invoice.issue_date) }}
            · {{ t(`purchase_invoice.status.${invoice.status}`) }}
          </div>
        </div>
        <div class="text-right">
          <div class="font-mono font-semibold">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</div>
          <RouterLink :to="`/purchase-invoices/${invoice.id}`" class="text-sm text-primary-600 hover:underline" @click="emit('close', true)">
            {{ t('purchase_invoice.extraction_review.open_invoice') }}
          </RouterLink>
        </div>
      </div>

      <!-- Ostatní části hlášení (sekce o druhu nákladu je níž jako seznam) -->
      <div v-if="otherWarning" class="p-3 bg-warning-50 border border-warning-500/40 rounded-md text-sm text-warning-700">
        <ExtractionWarningText :warning="otherWarning" :dismissible="!readOnlyReason" :busy="resolving" @dismiss="resolveSection" />
      </div>

      <!-- Druh nákladu po položkách -->
      <div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <h3 class="text-sm font-medium text-neutral-700">
            {{ t('purchase_invoice.extraction_review.items_title') }}
            <span v-if="attentionCount" class="ml-1 text-danger-600">
              ({{ t('purchase_invoice.extraction_review.attention_count', { count: attentionCount }) }})
            </span>
          </h3>
          <button v-if="pendingProposals.length && !readOnlyReason" type="button" :class="btnOutlineSm('primary')" @click="applyAll">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ t('purchase_invoice.extraction_review.apply_all', { count: pendingProposals.length }) }}
          </button>
        </div>

        <ul class="space-y-2">
          <li v-for="it in items" :key="it.id"
            class="rounded-md border p-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4"
            :class="needsAttention(it) ? 'border-danger-500 ring-2 ring-danger-500/30 bg-danger-50/40' : 'border-neutral-200'">
            <div class="min-w-0 flex-1">
              <div class="text-sm text-neutral-900 break-words">
                <span class="text-neutral-400 font-mono mr-1">{{ it.order_index + 1 }}.</span>{{ it.description }}
              </div>
              <div class="text-xs text-neutral-500 font-mono">{{ formatMoney(it.total_without_vat ?? it.quantity * it.unit_price_without_vat, invoice.currency) }}</div>
              <div v-if="proposalFor(it.order_index)" class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-neutral-600">
                  {{ t('purchase_invoice.extraction_review.proposal', {
                    kind: t(`purchase_invoice.expense_kind.${proposalFor(it.order_index)!.kind}`),
                    pct: Math.round(proposalFor(it.order_index)!.confidence * 100),
                  }) }}
                  <span class="text-neutral-400">— {{ proposalFor(it.order_index)!.reason }}</span>
                </span>
                <button v-if="kinds[it.id as number] !== proposalFor(it.order_index)!.kind && !readOnlyReason" type="button"
                  :class="btnOutlineSm('primary')" @click="applyProposal(it)">
                  {{ t('purchase_invoice.extraction_review.apply') }}
                </button>
              </div>
            </div>
            <select v-model="kinds[it.id as number]" :disabled="!!readOnlyReason"
              class="w-full sm:w-56 shrink-0 rounded-md border px-2 py-1.5 text-sm bg-surface disabled:opacity-60"
              :class="needsAttention(it) ? 'border-danger-500' : 'border-neutral-300'">
              <option :value="null">{{ t('purchase_invoice.extraction_review.kind_unset') }}</option>
              <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ t(`purchase_invoice.expense_kind.${k}`) }}</option>
            </select>
          </li>
        </ul>
        <p v-if="readOnlyReason" :class="BTN_DISABLED_NOTE" class="mt-2">{{ readOnlyReason }}</p>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4 text-sm text-neutral-600">
          <span>{{ t('purchase_invoice.extraction_review.counter', { n: index + 1, total: queue.length }) }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="index === 0 || saving" @click="goTo(index - 1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            {{ t('purchase_invoice.extraction_review.back') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="saving" @click="next">
            {{ isLast ? t('purchase_invoice.extraction_review.close') : t('purchase_invoice.extraction_review.skip') }}
          </button>
          <button type="button" :class="btnFilled('success')" class="whitespace-nowrap"
            :disabled="saving || !!readOnlyReason" :title="disabledTitle(!!readOnlyReason, readOnlyReason)" @click="save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ isLast ? t('purchase_invoice.extraction_review.save_finish') : t('purchase_invoice.extraction_review.save_next') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>
</template>
