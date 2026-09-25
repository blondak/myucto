export interface ExtractionWarningItem {
  label: string | null
  text: string
}

export interface ExtractionWarningSection {
  paragraphs: string[]
  items: ExtractionWarningItem[]
}

const ITEM_LABEL = /^(řádek \d+(?: „[^"”]*["”])?):\s*(.*)$/s

function toItem(raw: string): ExtractionWarningItem {
  const text = raw.replace(/^•\s*/, '').trim()
  const m = ITEM_LABEL.exec(text)
  return m ? { label: m[1], text: m[2] } : { label: null, text }
}

/**
 * Rozloží `extraction_warning` na sekce. Backend skládá sekce oddělené prázdným
 * řádkem a odrážky `• …` na samostatných řádcích; starší uložené texty můžou mít
 * odrážky i v jednom řádku, proto se dělí i na „ • ".
 */
export function parseExtractionWarning(warning: string | null | undefined): ExtractionWarningSection[] {
  if (!warning) return []
  return warning
    .split(/\r?\n\s*\r?\n/)
    .map((block) => {
      const section: ExtractionWarningSection = { paragraphs: [], items: [] }
      for (const line of block.split(/\r?\n/)) {
        const parts = line.split(/\s+(?=•\s)/).map((p) => p.trim()).filter(Boolean)
        for (const part of parts) {
          if (part.startsWith('•')) section.items.push(toItem(part))
          else section.paragraphs.push(part)
        }
      }
      return section
    })
    .filter((s) => s.paragraphs.length > 0 || s.items.length > 0)
}
