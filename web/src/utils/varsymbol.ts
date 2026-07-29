// Klient-side render varsymbol templatu — zrcadlí PHP VarsymbolGenerator::render().
// Používá se pro live preview v Settings; v editoru faktury preferujeme volání
// /api/invoices/preview-varsymbol (zná aktuální hodnotu counteru z DB).
//
// Placeholdery: {YYYY}, {YY}, {MM}, {C+} (variabilní padding 1..6 znaků),
// {PP} = daňový prefix přijaté faktury (jen náhled — dosadí se vzorek "PF").

export function renderVarsymbolTemplate(
  template: string | null | undefined,
  date: Date,
  counter: number,
): string {
  if (!template) return ''
  const Y = String(date.getFullYear())
  const M = String(date.getMonth() + 1).padStart(2, '0')
  let out = template
    .replaceAll('{PP}', 'PF') // náhledový vzorek; reálný prefix dle daňového typu dokladu
    .replaceAll('{YYYY}', Y)
    .replaceAll('{YY}', Y.slice(-2))
    .replaceAll('{MM}', M)
  out = out.replace(/\{(C+)\}/g, (_match, cs: string) => {
    return String(counter).padStart(cs.length, '0')
  })
  return out
}

/**
 * True pokud template obsahuje counter placeholder {C+}. Bez něj generator selže
 * (resp. dva inserty se stejným varsymbolem narazí na unique constraint).
 */
export function hasCounterPlaceholder(template: string | null | undefined): boolean {
  if (!template) return false
  return /\{C+\}/.test(template)
}

// Featura G (private/REAL_data_followup_UX.md) — client-side zrcadlo
// VarsymbolSeriesCollisionChecker::digitSkeleton()/templatesCollide() (PHP), pro
// OKAMŽITÉ varování při psaní do formuláře (backend kontrola se přepočítá až po
// uložení). Musí zůstat 1:1 v souladu s PHP verzí — testováno v obou (PHPUnit +
// Vitest) na stejných příkladech.
//
// Kolize = po zahození nečíselných znaků (přesně to, co dělá bankovní matcher při
// párování VS) šablony vyprodukují STEJNOU strukturu číslic → pro shodné datum
// a počítadlo IDENTICKÝ VS.
export function digitSkeleton(template: string): string {
  const parts = template.split(/(\{YYYY\}|\{YY\}|\{MM\}|\{C+\}|\{PP\})/)
  const tokens: string[] = []
  for (const part of parts) {
    if (part === '') continue
    if (part === '{YYYY}') { tokens.push('Y4'); continue }
    if (part === '{YY}') { tokens.push('Y2'); continue }
    if (part === '{MM}') { tokens.push('M2'); continue }
    if (part === '{PP}') continue // vždy písmena — normalizace na číslice je odstraní
    const counterMatch = /^\{(C+)\}$/.exec(part)
    if (counterMatch) { tokens.push('C' + counterMatch[1].length); continue }
    const digits = part.replace(/\D+/g, '')
    if (digits !== '') tokens.push('L' + digits)
  }
  return tokens.join('|')
}

export function templatesCollide(a: string | null | undefined, b: string | null | undefined): boolean {
  const ta = (a ?? '').trim()
  const tb = (b ?? '').trim()
  if (ta === '' || tb === '') return false
  if (ta === tb) return true
  return digitSkeleton(ta) === digitSkeleton(tb)
}
