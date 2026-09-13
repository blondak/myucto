/** Jedna volba v nabídce sloupců rychlého měsíčního vstupu. */
export interface PayrollQuickColumnOption {
  /** Klíč preference: `surcharge:<druh>` nebo `component:<KÓD>`. */
  key: string
  label: string
  code?: string
  /** Počet vztahů s hodnotou za celé období. */
  rowsWithValue: number
  /** Složku v rychlém vstupu zadat nejde, sloupec je jen pro přehled. */
  summaryOnly: boolean
}

export interface PayrollQuickColumnGroup {
  key: string
  label: string
  options: PayrollQuickColumnOption[]
}
