/**
 * Rozpoznání období časového rozlišení (381/384) z textu položky dokladu.
 *
 * Dvojče backendového `api/src/Service/Accounting/Accrual/AccrualPeriodDetector.php`;
 * obě implementace sdílejí sadu případů `__tests__/accrualPeriod.cases.json`, takže
 * editor nabídne totéž období, jaké doplní vytěžení. Při změně upravuj obě strany.
 *
 * Samotné datum („DUZP 28. 9. 2026") období není. Období vzniká z rozsahu (dvě data,
 * dva měsíce, dva roky spojené pomlčkou / „až" / „do"), nebo z výslovného měsíce či
 * roku s klíčovým slovem („za září 2026", „na rok 2027").
 */

export interface AccrualPeriod {
  from: string
  to: string
}

export const ACCRUAL_MAX_SPAN_YEARS = 5

const MONTHS: Record<string, number> = {
  leden: 1, ledna: 1, lednu: 1, january: 1, jan: 1,
  únor: 2, února: 2, únoru: 2, unor: 2, unora: 2, february: 2, feb: 2,
  březen: 3, března: 3, březnu: 3, brezen: 3, brezna: 3, march: 3, mar: 3,
  duben: 4, dubna: 4, dubnu: 4, april: 4, apr: 4,
  květen: 5, května: 5, květnu: 5, kveten: 5, kvetna: 5, may: 5,
  červen: 6, června: 6, červnu: 6, cerven: 6, cervna: 6, june: 6, jun: 6,
  červenec: 7, července: 7, červenci: 7, cervenec: 7, cervence: 7, july: 7, jul: 7,
  srpen: 8, srpna: 8, srpnu: 8, august: 8, aug: 8,
  září: 9, zari: 9, september: 9, sept: 9, sep: 9,
  říjen: 10, října: 10, říjnu: 10, rijen: 10, rijna: 10, october: 10, oct: 10,
  listopad: 11, listopadu: 11, november: 11, nov: 11,
  prosinec: 12, prosince: 12, prosinci: 12, december: 12, dec: 12,
}

const SEPARATOR = /^\s*(?:[-–—‒−]+|až|az|do|to|until|till|through|thru)\s*$/u
const MONTH_KEYWORD = /(?:^|[^\p{L}])(?:za|na|pro|období|obdobi|měsíc|mesic|for|period|month)\s*:?\s*$/u
const YEAR_KEYWORD = /(?:^|[^\p{L}])(?:rok|roku|year)\s*:?\s*$/u

const NAME = Object.keys(MONTHS)
  .sort((a, b) => b.length - a.length)
  .map((s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  .join('|')

const TOKEN = new RegExp(
  '(?<![\\d./])(\\d{4})-(\\d{1,2})-(\\d{1,2})(?!\\d)'
  + '|(?<![\\d./])(\\d{1,2})\\.\\s*(\\d{1,2})\\.(?:\\s*(\\d{4})(?!\\d))?'
  + '|(?<![\\d./])(\\d{1,2})/(\\d{1,2})/(\\d{4})(?!\\d)'
  + '|(?<![\\d./])(\\d{1,2})\\.?\\s*(' + NAME + ')(?!\\p{L})(?:\\s*(\\d{4})(?!\\d))?'
  + '|(?<![\\d./])(\\d{1,2})\\s*[/.]\\s*(\\d{4})(?!\\d)'
  + '|(?<!\\p{L})(' + NAME + ')(?!\\p{L})(?:\\s*(\\d{4})(?!\\d))?'
  + '|(?<![\\p{L}\\d./])((?:19|20)\\d{2})(?!\\d)',
  'gu',
)

type Kind = 'day' | 'month' | 'year'

interface Token {
  kind: Kind
  start: number
  end: number
  y: number | null
  m1: number
  d1: number
  m2: number
  d2: number | null
}

function pad(n: number, len = 2): string {
  return String(n).padStart(len, '0')
}

function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate()
}

function ymd(y: number, m: number, d: number): string | null {
  if (y < 1900 || y > 2100 || m < 1 || m > 12 || d < 1 || d > daysInMonth(y, m)) return null
  return `${pad(y, 4)}-${pad(m)}-${pad(d)}`
}

function startOf(t: Token, y: number): string | null {
  return ymd(y, t.m1, t.d1)
}

function endOf(t: Token, y: number): string | null {
  if (t.m2 < 1 || t.m2 > 12) return null
  return ymd(y, t.m2, t.d2 ?? daysInMonth(y, t.m2))
}

function addYears(iso: string, years: number): string {
  const [y, m, d] = iso.split('-').map(Number) as [number, number, number]
  const dt = new Date(Date.UTC(y + years, m - 1, d))
  return `${pad(dt.getUTCFullYear(), 4)}-${pad(dt.getUTCMonth() + 1)}-${pad(dt.getUTCDate())}`
}

function valid(from: string | null, to: string | null): AccrualPeriod | null {
  if (from === null || to === null || from > to) return null
  if (to > addYears(from, ACCRUAL_MAX_SPAN_YEARS)) return null
  return { from, to }
}

function tokens(text: string): Token[] {
  const out: Token[] = []
  for (const m of text.matchAll(TOKEN)) {
    const g = (i: number): string | null => m[i] ?? null
    const start = m.index ?? 0
    const end = start + m[0].length
    const num = (i: number): number => Number(g(i))
    let tok: Omit<Token, 'start' | 'end'>
    if (g(1) !== null) {
      tok = { kind: 'day', y: num(1), m1: num(2), d1: num(3), m2: num(2), d2: num(3) }
    } else if (g(4) !== null) {
      tok = { kind: 'day', y: g(6) !== null ? num(6) : null, m1: num(5), d1: num(4), m2: num(5), d2: num(4) }
    } else if (g(7) !== null) {
      tok = { kind: 'day', y: num(9), m1: num(8), d1: num(7), m2: num(8), d2: num(7) }
    } else if (g(10) !== null) {
      const mo = MONTHS[g(11) as string] as number
      tok = { kind: 'day', y: g(12) !== null ? num(12) : null, m1: mo, d1: num(10), m2: mo, d2: num(10) }
    } else if (g(13) !== null) {
      tok = { kind: 'month', y: num(14), m1: num(13), d1: 1, m2: num(13), d2: null }
    } else if (g(15) !== null) {
      const mo = MONTHS[g(15) as string] as number
      tok = { kind: 'month', y: g(16) !== null ? num(16) : null, m1: mo, d1: 1, m2: mo, d2: null }
    } else {
      tok = { kind: 'year', y: num(17), m1: 1, d1: 1, m2: 12, d2: 31 }
    }
    out.push({ ...tok, start, end })
  }
  return out
}

function pairPeriod(a: Token, b: Token): AccrualPeriod | null {
  let ya = a.y
  let yb = b.y
  if (ya === null && yb === null) return null
  if (ya === null && yb !== null) {
    ya = yb
    const end = endOf(b, yb)
    const start = startOf(a, ya)
    if (start !== null && end !== null && start > end) ya = yb - 1
  } else if (yb === null && ya !== null) {
    yb = ya
    const start = startOf(a, ya)
    const end = endOf(b, yb)
    if (start !== null && end !== null && end < start) yb = ya + 1
  }
  return valid(startOf(a, ya as number), endOf(b, yb as number))
}

export function detectAccrualPeriod(text: string): AccrualPeriod | null {
  const normalized = (text ?? '').replace(/[   ]/g, ' ').toLowerCase()
  if (normalized.trim() === '') return null
  const list = tokens(normalized)

  for (let i = 0; i < list.length - 1; i++) {
    const a = list[i] as Token
    const b = list[i + 1] as Token
    if (!SEPARATOR.test(normalized.slice(a.end, b.start))) continue
    const period = pairPeriod(a, b)
    if (period) return period
  }

  for (const t of list) {
    if (t.y === null || t.kind === 'day') continue
    const before = normalized.slice(0, t.start)
    if (!(t.kind === 'year' ? YEAR_KEYWORD : MONTH_KEYWORD).test(before)) continue
    const period = valid(startOf(t, t.y), endOf(t, t.y))
    if (period) return period
  }
  return null
}
