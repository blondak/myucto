import { describe, expect, it } from 'vitest'
import { reactive } from 'vue'
import { createRowAutoCode, rowCodeFromName } from '@/utils/rowAutoCode'

describe('rowCodeFromName', () => {
  it('odvodí malý kód bez diakritiky a vyhne se obsazeným kódům', () => {
    expect(rowCodeFromName('Barva víčka', [], 'group_1')).toBe('barva_vicka')
    expect(rowCodeFromName('Barva víčka', ['barva_vicka'], 'group_1')).toBe('barva_vicka_2')
    expect(rowCodeFromName('Barva', ['BARVA'], 'group_1')).toBe('barva_2')
  })

  it('název bez písmen a číslic nechá záložní kód', () => {
    expect(rowCodeFromName('', [], 'group_3')).toBe('group_3')
    expect(rowCodeFromName('—', [], 'option_2')).toBe('option_2')
  })

  it('výsledek projde backendovou validací setu a délkou 50', () => {
    const code = rowCodeFromName('Velmi dlouhý název skupiny, který se do padesáti znaků nevejde ani omylem', [], 'g')
    expect(code.length).toBeLessThanOrEqual(50)
    expect(code).toMatch(/^[a-zA-Z0-9_-]{1,50}$/)
  })
})

describe('createRowAutoCode', () => {
  const auto = () => createRowAutoCode<{ code: string; name: string }>((name) => rowCodeFromName(name, [], 'fallback'))

  it('nový řádek: kód sleduje název, dokud ho uživatel nezmění ručně', () => {
    const tracker = auto()
    const row = reactive({ code: 'group_1', name: '' })
    tracker.track(row)
    tracker.onName(row, 'Příchuť')
    expect(row.code).toBe('prichut')
    tracker.onCode(row, 'moje')
    tracker.onName(row, 'Jiná příchuť')
    expect(row.code).toBe('moje')
  })

  it('smazání kódu vrátí automatické generování', () => {
    const tracker = auto()
    const row = reactive({ code: 'x', name: '' })
    tracker.track(row)
    tracker.onCode(row, 'ruční')
    tracker.onCode(row, '')
    tracker.onName(row, 'Obal')
    expect(row.code).toBe('obal')
  })

  it('načtený (existující) řádek si kód sám nemění', () => {
    const tracker = auto()
    const row = reactive({ code: 'color', name: 'Barva' })
    tracker.onName(row, 'Barva těla')
    expect(row.code).toBe('color')
    expect(tracker.isAuto(row)).toBe(false)
  })

  it('stav přežije přístup přes jinou reaktivní proxy téhož objektu', () => {
    const tracker = auto()
    const list = reactive([{ code: 'a', name: '' }])
    tracker.track(list[0]!)
    const again = list[0]!
    tracker.onName(again, 'Velikost')
    expect(list[0]!.code).toBe('velikost')
  })
})
