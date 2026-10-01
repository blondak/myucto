import { computed, ref } from 'vue'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useSupplierStore } from '@/stores/supplier'

/**
 * Výsledkové účty firmy pro výchozí účet produktu, kategorie a položky dokladu
 * (Účtování podle dimenzí, F1). Výnosy = třída 6, náklady = třída 5 — stejné pravidlo
 * ověřuje backend (ProductPostingDefaults::validateAccount).
 *
 * Funkce patří k podvojnému účetnictví; v daňové evidenci ani bez práva číst osnovu
 * se nic nenačte a `available` zůstane false.
 */
export function useResultAccounts() {
  const supplierStore = useSupplierStore()
  const accounts = ref<ChartAccount[]>([])
  const loaded = ref(false)

  const doubleEntry = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')
  const available = computed(() => doubleEntry.value && loaded.value && accounts.value.length > 0)
  const revenueAccounts = computed(() =>
    accounts.value.filter(a => a.account_type === 'revenue' && a.account_code.startsWith('6')))
  const expenseAccounts = computed(() =>
    accounts.value.filter(a => a.account_type === 'expense' && a.account_code.startsWith('5')))

  async function load(): Promise<void> {
    if (!doubleEntry.value || loaded.value) return
    try {
      accounts.value = await accountingApi.listAccounts()
    } catch {
      accounts.value = []
    } finally {
      loaded.value = true
    }
  }

  return { accounts, loaded, available, doubleEntry, revenueAccounts, expenseAccounts, load }
}
