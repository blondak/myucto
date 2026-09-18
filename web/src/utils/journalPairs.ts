import type { JournalLine } from '@/api/accounting'

/**
 * Souvztažnosti účetního zápisu — převod řádků po stranách na dvojice MD/DAL.
 *
 * Úložiště drží zápis po nohách (`journal_entry_lines.side`), protože tak ho
 * §13 ZoÚ popisuje a tak se sčítá hlavní kniha. Účetní ho ale nečte po nohách:
 * POHODA i Money ERP ukazují deník po SOUVZTAŽNOSTECH — jeden řádek = jedna
 * částka a dva účty vedle sebe (311/602 84 000). Rozpad po nohách tutéž částku
 * napíše dvakrát a nutí oko párovat účty ručně.
 *
 * Párování je čistě zobrazovací: součet zůstává, součty na účtech zůstávají,
 * jen se noha rozpadne na tolik dílů, s kolika protistranami se potkává.
 */
export interface JournalPair {
  /** Klíč do `v-for` — stabilní přes id obou nohou a pořadí dílu. */
  key: string
  debit: JournalLine | null
  credit: JournalLine | null
  amount: number
  /**
   * Cizí měna jen tam, kde se noha nedělila — proporcionální rozpad by vyrobil
   * číslo, které v účetnictví nikde není.
   */
  amountForeign: number | null
  currencyCode: string | null
  costCenter: string | null
}

/** Haléře — párování běží v celých jednotkách, ať se nesčítají chyby floatu. */
function cents(amount: number | string | null | undefined): number {
  return Math.round(Number(amount || 0) * 100)
}

/**
 * Jsou částky na obou stranách po dvojicích shodné a každá jen jednou?
 *
 * Tohle je přenesená daň: 518/321 na cenu a 343.100/343.200 na daň, tedy dvě
 * nohy proti dvěma. Protože se každá částka vyskytuje právě jednou na každé
 * straně, je přiřazení jednoznačné — nedohaduje se, jen se přečte. Jakmile se
 * některá částka opakuje (dvě střediska po 500 proti dvěma závazkům po 500),
 * jednoznačné být přestává a párování se nedělá.
 */
function hasUniqueAmountMatching(debits: JournalLine[], credits: JournalLine[]): boolean {
  if (debits.length !== credits.length) return false
  const d = debits.map(l => cents(l.amount)).sort((x, y) => x - y)
  const c = credits.map(l => cents(l.amount)).sort((x, y) => x - y)
  if (new Set(d).size !== d.length) return false
  return d.every((v, i) => v === c[i])
}

/**
 * Lze zápis poctivě ukázat po souvztažnostech?
 *
 * Ano jen tam, kde přiřazení nemusíme vymýšlet:
 *  - jedna ze stran má právě jednu nohu (faktura 311 proti 602+343, úhrada
 *    221 proti 311) — pak je rozpad té jediné nohy mezi protistrany jednoznačný;
 *  - nebo si nohy odpovídají částkami jedna ku jedné ({@see hasUniqueAmountMatching});
 *  - obě strany sedí v součtu (nevyrovnaný koncept musí být vidět tak, jak je).
 *
 * Jinak (rozúčtování na střediska proti víc nákladům) by si jakékoli párování
 * vztahy vymýšlelo — tam se vrací rozpad po stranách.
 */
export function canPair(lines: JournalLine[]): boolean {
  const debits = lines.filter(l => l.side === 'debit')
  const credits = lines.filter(l => l.side === 'credit')
  if (debits.length === 0 || credits.length === 0) return false

  const debitSum = debits.reduce((s, l) => s + cents(l.amount), 0)
  const creditSum = credits.reduce((s, l) => s + cents(l.amount), 0)
  if (debitSum !== creditSum) return false

  const oneSided = debits.length === 1 || credits.length === 1
  if (!oneSided && !hasUniqueAmountMatching(debits, credits)) return false

  // Dělená noha s cizí měnou by potřebovala rozpočítat i devizovou částku —
  // to je číslo, které v dokladu není. Radši rozpad po stranách.
  const splitSide = debits.length === 1 ? debits : credits
  if (oneSided && splitSide.length === 1 && lines.length > 2 && splitSide[0].amount_foreign != null) return false

  return true
}

/**
 * Rozpad zápisu na dvojice MD/DAL. Volat jen po {@see canPair} — jinak vrací
 * dvojice, jejichž přiřazení nevyplývá z dat.
 */
export function pairLines(lines: JournalLine[]): JournalPair[] {
  const debits = lines.filter(l => l.side === 'debit').slice().sort(byLineNo)
  let credits = lines.filter(l => l.side === 'credit').slice().sort(byLineNo)

  // Odpovídají-li si nohy částkami, seřaď protistranu podle nich — jinak by
  // dvojice pospojoval pořadím řádků, tedy 518 s daní a 343 se závazkem.
  if (debits.length > 1 && credits.length > 1 && hasUniqueAmountMatching(debits, credits)) {
    credits = debits.map(d => credits.find(c => cents(c.amount) === cents(d.amount))!)
  }

  const pairs: JournalPair[] = []
  let di = 0
  let ci = 0
  let debitRest = debits.length > 0 ? cents(debits[0].amount) : 0
  let creditRest = credits.length > 0 ? cents(credits[0].amount) : 0

  while (di < debits.length && ci < credits.length) {
    const d = debits[di]
    const c = credits[ci]
    const take = Math.min(debitRest, creditRest)

    // Noha se dělí, jen když ji protistrana nevyčerpá celou.
    const debitWhole = take === cents(d.amount)
    const creditWhole = take === cents(c.amount)

    pairs.push({
      key: `${d.id}-${c.id}-${pairs.length}`,
      debit: d,
      credit: c,
      amount: take / 100,
      ...foreignOf(d, c, debitWhole, creditWhole),
      costCenter: d.cost_center || c.cost_center || null,
    })

    debitRest -= take
    creditRest -= take
    if (debitRest === 0) { di += 1; debitRest = di < debits.length ? cents(debits[di].amount) : 0 }
    if (creditRest === 0) { ci += 1; creditRest = ci < credits.length ? cents(credits[ci].amount) : 0 }
  }

  return pairs
}

function byLineNo(a: JournalLine, b: JournalLine): number {
  return (a.line_no ?? 0) - (b.line_no ?? 0) || a.id - b.id
}

/**
 * Devizová částka dvojice. Bere se z té nohy, která do dvojice vstoupila celá —
 * u dělené nohy by šlo o dopočet, ne o údaj z dokladu.
 */
function foreignOf(
  debit: JournalLine, credit: JournalLine, debitWhole: boolean, creditWhole: boolean,
): Pick<JournalPair, 'amountForeign' | 'currencyCode'> {
  const source = debitWhole && debit.amount_foreign != null ? debit
    : creditWhole && credit.amount_foreign != null ? credit
      : null
  return source
    ? { amountForeign: source.amount_foreign, currencyCode: source.currency_code }
    : { amountForeign: null, currencyCode: null }
}
