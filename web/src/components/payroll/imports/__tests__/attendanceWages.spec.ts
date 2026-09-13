import { describe, expect, it } from 'vitest'
import type { AttendanceProfile, AttendanceSampleUpgrade, AttendanceWageChange } from '@/api/payrollImports'
import {
  adoptableWageChanges,
  blockedWageChanges,
  previewRefreshRuns,
  uniqueRefreshRuns,
  upgradedProfile,
  upgradeMatchesProfile,
} from '../attendanceWages'

function change(overrides: Partial<AttendanceWageChange> = {}): AttendanceWageChange {
  return {
    key: 'osoba-1',
    display_name: 'Jana Testovací',
    employment_id: 11,
    current_minor: null,
    imported_minor: 3_500_000,
    mode: 'correct',
    reason: null,
    runs_needing_refresh: [],
    ...overrides,
  }
}

const upgrade: AttendanceSampleUpgrade = {
  profile_id: 7,
  name: 'Vzor GIRITON',
  version: 1,
  latest_version: 2,
  rules: [{ sheet: null, header: 'Mzda', meaning: 'monthly_wage', unit: 'amount', component_code: null }],
  components: [{ code: 'ODMENA', name: 'Odměna', kind: 'bonus' }],
}

describe('převzetí měsíční mzdy', () => {
  const changes = [
    change(),
    change({ key: 'osoba-2', mode: 'add', current_minor: 3_000_000, runs_needing_refresh: [
      { run_id: 5, period: '2026-08', status: 'calculated' },
    ] }),
    change({ key: 'osoba-3', reason: 'Mzdový běh za 2026-08 je schválený.' }),
    change({ key: 'osoba-4', runs_needing_refresh: [
      { run_id: 5, period: '2026-08', status: 'calculated' },
      { run_id: 4, period: '2026-07', status: 'draft' },
    ] }),
  ]

  it('rozdělí změny na zapisované a zablokované', () => {
    expect(adoptableWageChanges(changes).map(item => item.key)).toEqual(['osoba-1', 'osoba-2', 'osoba-4'])
    expect(blockedWageChanges(changes).map(item => item.key)).toEqual(['osoba-3'])
  })

  it('snese chybějící seznam ze staršího serveru', () => {
    expect(adoptableWageChanges(undefined)).toEqual([])
    expect(blockedWageChanges(null)).toEqual([])
  })

  it('běhy k přepočtu vypíše každý jednou a podle období', () => {
    expect(previewRefreshRuns(changes).map(run => run.run_id)).toEqual([4, 5])
    expect(uniqueRefreshRuns([
      { run_id: 9, period: '2026-09', status: 'draft' },
      { run_id: 9, period: '2026-09', status: 'draft' },
    ])).toHaveLength(1)
  })
})

describe('nová verze vzoru', () => {
  const profile: AttendanceProfile = {
    id: 7,
    name: 'Vzor GIRITON (upravený)',
    is_sample: true,
    sample_version: 1,
    upgrade_available: true,
    updated_at: '2026-08-30 10:00:00',
    rules: [],
    components: [],
  }

  it('páruje nabídku jen se stejným profilem', () => {
    expect(upgradeMatchesProfile(upgrade, 7)).toBe(true)
    expect(upgradeMatchesProfile(upgrade, 8)).toBe(false)
    expect(upgradeMatchesProfile(null, 7)).toBe(false)
    expect(upgradeMatchesProfile(upgrade, null)).toBe(false)
  })

  it('převezme pravidla a složky, název a id nechá', () => {
    const next = upgradedProfile(profile, upgrade)
    expect(next.id).toBe(7)
    expect(next.name).toBe('Vzor GIRITON (upravený)')
    expect(next.rules).toEqual(upgrade.rules)
    expect(next.components).toEqual(upgrade.components)
    expect(next.rules[0]).not.toBe(upgrade.rules[0])
  })
})
