import { api } from './client'

export type ImportKind = 'auto' | 'issued' | 'purchase'

export interface ImportResultRow {
  file: string
  status: 'created' | 'skipped' | 'failed'
  reason?: string
  kind?: 'issued' | 'purchase' | null   // backend dispatch route (auto → konkrétní)
  invoice_id?: number          // pro issued
  purchase_invoice_id?: number  // pro purchase
  client_id?: number
  client_created?: boolean
  vendor_id?: number
  project_id?: number | null
  varsymbol?: string
  imported_status?: 'paid' | 'issued'

  // ── Vysvětlivky k dokladu ────────────────────────────────────────────────
  // Doklad rozstřelený dřív, než se vůbec dostal k řádkům (nečitelný soubor,
  // cizí IČO, výjimka), nese jen `reason` — proto je všechno níž volitelné
  // a čte se přes `?? []` / `?? 0`.
  /** Co jsme dopočítali nebo přepočetli (kurz, ceny včetně DPH, zařazení do OSS). */
  notes?: string[]
  /** Co si uživatel musí ověřit ručně. Chodí i u úspěšně vytvořeného dokladu. */
  warnings?: string[]
  varsymbol_substituted?: boolean
  /** Číslo dokladu ze zdrojového systému — jediný údaj, pod kterým ho tam najde. */
  document_number?: string | null

  // Per-doklad čítače řádků posílá backend jen u status = 'created'.
  oss_items?: number
  oss_rate_type_unknown?: number
  oss_manual_review?: number
  oss_credit_note_pending_period?: number
}

/**
 * Souhrn počítá backend, ne frontend: kdyby si ho tabulka sečetla z `results`,
 * rozešel by se s tím, co se opravdu zapsalo.
 */
export interface ImportSummary {
  created: number
  skipped: number
  failed: number
  /** ŘÁDKŮ zařazených do OSS (jen z vytvořených dokladů). */
  oss_items?: number
  /** Z toho řádků bez typu sazby — bez ručního doplnění se do OSS podání nedostanou. */
  oss_rate_type_unknown?: number
  /**
   * ŘÁDKŮ, u nichž systém neurčil místo plnění (sazba platí v zemi dodavatele i ve státě
   * spotřeby, nebo se číselníku nedalo zeptat). Takové řádky JSOU OSS a jsou tedy
   * započítané i v `oss_items` — kategorie se PŘEKRÝVAJÍ, nejsou disjunktní.
   */
  oss_manual_review?: number
  /** DOKLADŮ typu dobropis s OSS řádkem bez původního období. */
  oss_credit_notes_pending_period?: number
  /** DOKLADŮ s dosazeným variabilním symbolem (i přeskočených a odmítnutých). */
  varsymbol_substituted?: number
  /** DOKLADŮ s aspoň jedním varováním. */
  with_warnings?: number
}

export interface ImportReport {
  summary: ImportSummary
  results: ImportResultRow[]
}

/**
 * Upload import s explicit kind:
 *   - 'auto'     (default) — per-soubor detekce dle IČO buyer/supplier
 *   - 'issued'   — vynutí issued route (vydané faktury)
 *   - 'purchase' — vynutí purchase route (přijaté faktury)
 */
export async function uploadImport(files: File[], kind: ImportKind = 'auto'): Promise<ImportReport> {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f, f.name)
  const r = await api.post<ImportReport>(`/admin/import?kind=${kind}`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return r.data
}
