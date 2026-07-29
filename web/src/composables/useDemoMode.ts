import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

export function useDemoMode() {
  const auth = useAuthStore()
  const toast = useToast()
  const { t } = useI18n()

  function blockDemoMutation(): boolean {
    if (!auth.isDemo) return false
    toast.info(t('demo.read_only'))
    return true
  }

  return { blockDemoMutation }
}
