import { api } from './client'

/**
 * Příznaky přechodu z jiného mzdového programu.
 *
 * Agenda přechodu je jednorázová, takže Importy ji nenabízí firmě, která nic
 * nepřevzala. Roční přehled (`payrollTakeoverWagesApi.overview`) se na to ptát
 * nedá — je vázaný na rok a mlčel by i tam, kde převzetí je, jen v jiném roce.
 */
export interface PayrollMigrationState {
  has_takeover_wages: boolean
  has_posting_map: boolean
  /** Roky, ve kterých jsou převzaté mzdy; vzestupně. */
  takeover_years: number[]
  latest_takeover_year: number | null
}

export const payrollMigrationStateApi = {
  show: () =>
    api.get<{ state: PayrollMigrationState }>('/payroll/migration/state')
      .then(response => response.data.state),
}
