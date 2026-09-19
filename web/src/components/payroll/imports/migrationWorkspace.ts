import { inject, provide, ref, type InjectionKey, type Ref } from 'vue'
import {
  payrollMigrationStateApi,
  type PayrollMigrationState,
} from '@/api/payrollMigrationState'
import type { PayrollMigrationSource } from '@/api/payrollMigrationReconciliation'

/**
 * Stav sdílený záložkami přechodu z jiného mzdového programu: Převzaté mzdy,
 * Kontrola a Kontace.
 *
 * Rok a zdroj drží jedno místo schválně. Účetní se na týž měsíc dívá ze tří
 * stran a přepínat rok zvlášť na každé záložce znamená srovnávat dvě různá
 * období, aniž by to bylo vidět.
 *
 * `state` rozhoduje, jestli se agenda vůbec nabídne — ptá se napříč roky, takže
 * převzetí z loňska neschová letošní prázdný přehled.
 */
export interface MigrationWorkspace {
  year: Ref<number>
  source: Ref<PayrollMigrationSource | ''>
  state: Ref<PayrollMigrationState | null>
  stateLoading: Ref<boolean>
  /** Zvýší se po úspěšném importu; Kontrola i Kontace se z něj přenačtou. */
  revision: Ref<number>
  loadState(): Promise<void>
  markImported(): void
}

const KEY: InjectionKey<MigrationWorkspace> = Symbol('payroll-migration-workspace')

export function createMigrationWorkspace(): MigrationWorkspace {
  const year = ref(new Date().getFullYear())
  const source = ref<PayrollMigrationSource | ''>('')
  const state = ref<PayrollMigrationState | null>(null)
  const stateLoading = ref(false)
  const revision = ref(0)

  async function loadState(): Promise<void> {
    stateLoading.value = true
    try {
      const loaded = await payrollMigrationStateApi.show()
      state.value = loaded
      // Rok převzetí je skoro vždycky jiný než ten letošní — bez tohohle by
      // účetní po otevření viděla prázdno a musela rok uhodnout.
      if (loaded.latest_takeover_year !== null) year.value = loaded.latest_takeover_year
    } catch {
      // Příznaky jsou jen pro viditelnost záložek; jejich selhání nesmí shodit
      // celé Importy. Agenda přechodu zůstane schovaná, přímé URL funguje dál.
      state.value = null
    } finally {
      stateLoading.value = false
    }
  }

  function markImported(): void {
    revision.value += 1
    void loadState()
  }

  return { year, source, state, stateLoading, revision, loadState, markImported }
}

export function provideMigrationWorkspace(workspace: MigrationWorkspace): void {
  provide(KEY, workspace)
}

export function useMigrationWorkspace(): MigrationWorkspace {
  const workspace = inject(KEY, null)
  if (workspace === null) {
    throw new Error('MigrationWorkspace není k dispozici — chybí provideMigrationWorkspace().')
  }
  return workspace
}
