/**
 * Uložení PDF do složky zvolené pro firmu (#104).
 *
 * Cestu pro stahování webová aplikace určit nesmí, to je věc prohlížeče. Chromium
 * (Chrome, Edge) ale v dialogu „Uložit jako" (`showSaveFilePicker`) pamatuje
 * poslední složku zvlášť pro každé `id` — s id podle firmy se dialog otevře tam,
 * kam se naposledy ukládalo PDF té firmy. Ostatní prohlížeče (Firefox, Safari)
 * dialog neumí; volající pak stáhne soubor postaru.
 */

type SaveFilePicker = (options: {
  id?: string
  suggestedName?: string
  startIn?: string
  types?: Array<{ description?: string, accept: Record<string, string[]> }>
}) => Promise<{ createWritable: () => Promise<{ write: (data: Blob) => Promise<void>, close: () => Promise<void> }> }>

export type CompanyPdfSaveResult = 'saved' | 'cancelled' | 'unsupported'

export function canSaveToCompanyFolder(): boolean {
  return typeof window !== 'undefined' && typeof (window as unknown as { showSaveFilePicker?: unknown }).showSaveFilePicker === 'function'
}

/** Id dialogu: nejvýš 32 znaků z [A-Za-z0-9_-], jedno pro každou firmu. */
export function companyPickerId(supplierId: number | string | null | undefined): string {
  const sid = String(supplierId ?? '').replace(/\D+/g, '')
  return `myucto-pdf-${sid || 'default'}`.slice(0, 32)
}

/**
 * Otevře dialog Uložit jako ve složce firmy a uloží do zvoleného souboru PDF z `url`.
 * Musí se volat přímo z kliknutí — prohlížeč dialog jinak odmítne.
 */
export async function savePdfToCompanyFolder(
  url: string,
  suggestedName: string,
  supplierId: number | string | null | undefined,
): Promise<CompanyPdfSaveResult> {
  if (!canSaveToCompanyFolder()) return 'unsupported'
  const picker = (window as unknown as { showSaveFilePicker: SaveFilePicker }).showSaveFilePicker
  let handle: Awaited<ReturnType<SaveFilePicker>>
  try {
    handle = await picker({
      id: companyPickerId(supplierId),
      suggestedName,
      startIn: 'downloads',
      types: [{ description: 'PDF', accept: { 'application/pdf': ['.pdf'] } }],
    })
  } catch (e: unknown) {
    if ((e as { name?: string })?.name === 'AbortError') return 'cancelled'
    return 'unsupported'
  }

  const response = await fetch(url, { credentials: 'same-origin' })
  if (!response.ok) throw new Error(`PDF download failed (${response.status})`)
  const writable = await handle.createWritable()
  await writable.write(await response.blob())
  await writable.close()
  return 'saved'
}

/** Název souboru z čísla dokladu — bez znaků, které souborový systém nepřijme. */
export function pdfFileName(number: string | null | undefined, fallback: string): string {
  const base = (number ?? '').trim().replace(/[\\/:*?"<>|]+/g, '-') || fallback
  return base.toLowerCase().endsWith('.pdf') ? base : `${base}.pdf`
}
