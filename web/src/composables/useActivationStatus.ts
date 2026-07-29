import { computed, ref, watch } from 'vue'
import { activationApi, type ActivationStatus } from '@/api/activation'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'

const status = ref<ActivationStatus | null>(null)
const loading = ref(false)
let loadedSupplierId = 0

export function useActivationStatus() {
  const supplier = useSupplierStore()
  const auth = useAuthStore()

  async function refresh(force = false) {
    const supplierId = supplier.currentSupplierId
    if (!supplierId || !auth.hasCommercialFeatures) {
      status.value = null
      loadedSupplierId = 0
      return null
    }
    if (!force && loadedSupplierId === supplierId && status.value) return status.value
    loading.value = true
    try {
      status.value = await activationApi.status()
      loadedSupplierId = supplierId
      return status.value
    } finally {
      loading.value = false
    }
  }

  watch(() => supplier.currentSupplierId, () => {
    status.value = null
    loadedSupplierId = 0
    void refresh(true)
  })

  const showBanner = computed(() => auth.hasCommercialFeatures
    && status.value?.accounting_mode === 'double_entry'
    && status.value.activation_status !== 'completed'
    && (status.value.pending.total ?? 0) > 0)

  return { status, loading, showBanner, refresh }
}
