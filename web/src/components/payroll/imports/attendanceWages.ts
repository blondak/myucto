import type {
  AttendanceProfile,
  AttendanceRunNeedingRefresh,
  AttendanceSampleUpgrade,
  AttendanceWageChange,
} from '@/api/payrollImports'

/** Změny měsíční mzdy, které import opravdu zapíše (server u nich nehlásí překážku). */
export function adoptableWageChanges(changes: AttendanceWageChange[] | null | undefined): AttendanceWageChange[] {
  return (changes ?? []).filter(change => change.reason === null)
}

/** Změny, které se nepřevezmou, s důvodem od serveru. */
export function blockedWageChanges(changes: AttendanceWageChange[] | null | undefined): AttendanceWageChange[] {
  return (changes ?? []).filter(change => change.reason !== null)
}

/** Běhy k přepočtu, každý jednou, seřazené podle období. */
export function uniqueRefreshRuns(runs: AttendanceRunNeedingRefresh[] | null | undefined): AttendanceRunNeedingRefresh[] {
  const byId = new Map<number, AttendanceRunNeedingRefresh>()
  for (const run of runs ?? []) byId.set(run.run_id, run)
  return [...byId.values()].sort((a, b) => a.period.localeCompare(b.period) || a.run_id - b.run_id)
}

/** Běhy, kterých se převzetí mezd z náhledu dotkne. */
export function previewRefreshRuns(changes: AttendanceWageChange[] | null | undefined): AttendanceRunNeedingRefresh[] {
  return uniqueRefreshRuns(adoptableWageChanges(changes).flatMap(change => change.runs_needing_refresh))
}

/** Týká se nabídka nové verze vzoru právě tohoto profilu? */
export function upgradeMatchesProfile(
  upgrade: AttendanceSampleUpgrade | null | undefined,
  profileId: number | null | undefined,
): upgrade is AttendanceSampleUpgrade {
  return upgrade != null && profileId != null && upgrade.profile_id === profileId
}

/**
 * Profil s pravidly a složkami nové verze vzoru. Název a id zůstávají, takže
 * uložení jde běžnou cestou úpravy profilu.
 */
export function upgradedProfile(profile: AttendanceProfile, upgrade: AttendanceSampleUpgrade): AttendanceProfile {
  return {
    ...profile,
    rules: upgrade.rules.map(rule => ({ ...rule })),
    components: upgrade.components.map(component => ({ ...component })),
  }
}
