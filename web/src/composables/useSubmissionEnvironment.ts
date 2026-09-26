import { computed, watch, type Ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

export type SubmissionEnvironment = 'production' | 'test'

function authStoreOrNull(): { submissionTestEnvironmentAllowed?: boolean } | null {
  try {
    return useAuthStore()
  } catch {
    return null
  }
}

/**
 * Politika prostředí podání úřadům (ČSSZ, ZP, ISDS). Výběr testovacího prostředí
 * se nabízí jen ve vývojové instalaci (`app.env = development`); jinak se podává
 * vždy do produkce a backend test odmítne. Zkušební podání na EPO sem nepatří.
 *
 * Předaný model prostředí composable hlídá: když by mimo vývoj zůstal na `test`
 * (výchozí hodnota, uložený stav panelu), přepne ho na produkci.
 */
export function useSubmissionEnvironment(model?: Ref<string | null | undefined>) {
  // Bez aktivní pinie (izolovaný mount komponenty) platí totéž co před načtením
  // /api/auth/me: fail-closed, jen produkce.
  const auth = authStoreOrNull()
  const testAllowed = computed(() => auth?.submissionTestEnvironmentAllowed === true)

  if (model) {
    watch([model, testAllowed], ([value, allowed]) => {
      if (value === 'test' && !allowed) model.value = 'production'
    }, { immediate: true })
  }

  return { testAllowed, defaultEnvironment: 'production' as SubmissionEnvironment }
}
