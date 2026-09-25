<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'
import type { SaldoItem } from '@/api/accounting'
import { invoicesApi } from '@/api/invoices'
import { useToast } from '@/composables/useToast'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Doplněk řádku saldokonta pro zálohu čekající na vyúčtování (kind = advance_pending):
 * štítek, odkaz na daňový doklad k platbě a u přijaté zálohy akce „Vystavit fakturu
 * k záloze" — stejná jako na detailu proformy.
 */
const props = defineProps<{
  item: SaldoItem
  side: 'receivable' | 'payable'
}>()

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const busy = ref(false)

function taxDocumentRoute() {
  const id = props.item.tax_document_id
  if (!id) return null
  return props.item.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id } }
    : { name: 'invoice-detail', params: { id } }
}

async function issueFinal() {
  if (!confirm(t('invoice.issue_final_confirm', { varsymbol: props.item.doc_no }))) return
  busy.value = true
  try {
    const r = await invoicesApi.issueFinal(props.item.doc_id)
    if (!r?.final_invoice_id) {
      toast.error(t('invoice.invalid_response'))
      return
    }
    router.push(r.edit_url || `/invoices/${r.final_invoice_id}/edit`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.issue_final_failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mt-1 flex flex-wrap items-center gap-1.5" data-test="saldo-advance-info">
    <span class="rounded bg-primary-100 px-1.5 py-0.5 text-xs font-medium text-primary-700 whitespace-nowrap">
      {{ t(`accounting.saldo.advance_pending_${side}`) }}
    </span>
    <RouterLink v-if="taxDocumentRoute()" :to="taxDocumentRoute()!"
      class="text-xs text-primary-600 hover:underline whitespace-nowrap" data-test="saldo-advance-tax-document">
      {{ t('accounting.saldo.advance_tax_document') }}
    </RouterLink>
    <button v-if="side === 'receivable' && item.doc_type === 'invoice'" type="button"
      :disabled="busy" @click="issueFinal" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
      data-test="saldo-advance-issue-final">
      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
      </svg>
      {{ t('invoice.issue_final') }}
    </button>
  </div>
</template>
