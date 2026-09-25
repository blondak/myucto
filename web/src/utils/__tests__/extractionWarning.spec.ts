import { describe, expect, it } from 'vitest'
import { parseExtractionWarning, withoutExpenseKindSection } from '@/utils/extractionWarning'

describe('withoutExpenseKindSection', () => {
  it('drops only the expense kind section', () => {
    const warning = 'Reverse charge: zkontrolujte povahu plnění.'
      + '\n\nAI navrhuje druh nákladu u 1 řádků — NENÍ nastaven:\n• řádek 1: Služba (AI)'
      + '\n\nSoučet řádků nesedí.'

    expect(withoutExpenseKindSection(warning)).toBe('Reverse charge: zkontrolujte povahu plnění.\n\nSoučet řádků nesedí.')
    expect(withoutExpenseKindSection(null)).toBe('')
  })
})

describe('parseExtractionWarning', () => {
  it('splits sections and bullet lines with row labels', () => {
    const warning = 'Reverse charge (přijetí služby ze 3. země): zkontrolujte povahu plnění.'
      + '\n\nAI navrhuje druh nákladu u 2 řádků — NENÍ nastaven, potvrďte nebo opravte v editoru u každé položky:'
      + '\n• řádek 1 „Subscription to Team": Služba (AI, jistota 40 %; AI z dokladu ⇒ Služba)'
      + '\n• řádek 3 „1 reserved cron monitors": Drobný majetek (AI, jistota 90 %; text obsahuje „monitor")'

    const sections = parseExtractionWarning(warning)

    expect(sections).toHaveLength(2)
    const rc = 'Reverse charge (přijetí služby ze 3. země): zkontrolujte povahu plnění.'
    expect(sections[0]).toEqual({ raw: rc, paragraphs: [rc], items: [] })
    expect(sections[1].raw.startsWith('AI navrhuje druh nákladu')).toBe(true)
    expect(sections[1].paragraphs).toHaveLength(1)
    expect(sections[1].items).toEqual([
      { label: 'řádek 1 „Subscription to Team"', text: 'Služba (AI, jistota 40 %; AI z dokladu ⇒ Služba)' },
      { label: 'řádek 3 „1 reserved cron monitors"', text: 'Drobný majetek (AI, jistota 90 %; text obsahuje „monitor")' },
    ])
  })

  it('splits inline bullets of older stored warnings', () => {
    const sections = parseExtractionWarning('Hlavička: • řádek 2: Služba (AI) • řádek 4: Zboží (AI)')

    expect(sections[0].paragraphs).toEqual(['Hlavička:'])
    expect(sections[0].items.map((i) => i.label)).toEqual(['řádek 2', 'řádek 4'])
  })

  it('returns nothing for empty warning', () => {
    expect(parseExtractionWarning(null)).toEqual([])
    expect(parseExtractionWarning('  \n\n ')).toEqual([])
  })
})
