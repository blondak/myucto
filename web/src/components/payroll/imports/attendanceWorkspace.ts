import { inject, provide, ref, type InjectionKey, type Ref } from 'vue'
import {
  payrollImportsApi,
  type AttendanceProfile,
  type AttendanceRule,
  type ImportFilePayload,
} from '@/api/payrollImports'
import { payrollWorkingPeriod } from '@/pages/payroll/payrollComponentsUi'
import { filesFingerprint, filesToPayload } from './importHelpers'

/** Požadavek „otevři mapování" z měsíčního importu; `seq` odliší dva stejné požadavky za sebou. */
export interface MappingFocus {
  seq: number
  profileId: number | null
  /** Pravidla automatického návrhu, když náhled žádný profil nepoužil. */
  seedRules: AttendanceRule[] | null
}

export interface ProfileRevision {
  seq: number
  profileId: number
}

/**
 * Stav sdílený záložkami Docházka a Mapování sloupců: období, nahrané soubory
 * a profily firmy. Mapování se nastavuje jednou, ale ladí se na souborech
 * z konkrétního měsíce — proto se soubory nahrávají jen jednou pro obě záložky.
 */
export interface AttendanceWorkspace {
  period: Ref<string>
  files: Ref<File[]>
  profiles: Ref<AttendanceProfile[]>
  profilesLoading: Ref<boolean>
  profilesError: Ref<string>
  /** Poslední uložený nebo smazaný profil — náhled z něj postavený přestává platit. */
  profileRevision: Ref<ProfileRevision | null>
  mappingFocus: Ref<MappingFocus | null>
  loadProfiles(): Promise<void>
  upsertProfile(profile: AttendanceProfile): void
  removeProfile(id: number): void
  payloadFiles(): Promise<ImportFilePayload[]>
  openMapping(profileId: number | null, seedRules?: AttendanceRule[] | null): void
}

const KEY: InjectionKey<AttendanceWorkspace> = Symbol('attendance-import-workspace')

function sortProfiles(profiles: AttendanceProfile[]): AttendanceProfile[] {
  return [...profiles].sort((a, b) => a.name.localeCompare(b.name))
}

export function createAttendanceWorkspace(options: {
  onOpenMapping: () => void
  errorMessage: (error: unknown) => string
}): AttendanceWorkspace {
  const period = ref(payrollWorkingPeriod())
  const files = ref<File[]>([])
  const profiles = ref<AttendanceProfile[]>([])
  const profilesLoading = ref(false)
  const profilesError = ref('')
  const profileRevision = ref<ProfileRevision | null>(null)
  const mappingFocus = ref<MappingFocus | null>(null)
  let sequence = 0
  let payloadCache: { fingerprint: string; files: ImportFilePayload[] } | null = null

  function bump(profileId: number) {
    sequence += 1
    profileRevision.value = { seq: sequence, profileId }
  }

  async function loadProfiles() {
    profilesLoading.value = true
    profilesError.value = ''
    try {
      profiles.value = sortProfiles(await payrollImportsApi.attendanceProfiles())
    } catch (error) {
      profilesError.value = options.errorMessage(error)
    } finally {
      profilesLoading.value = false
    }
  }

  function upsertProfile(profile: AttendanceProfile) {
    profiles.value = sortProfiles([...profiles.value.filter(item => item.id !== profile.id), profile])
    bump(profile.id)
  }

  function removeProfile(id: number) {
    profiles.value = profiles.value.filter(item => item.id !== id)
    bump(id)
  }

  // Base64 obou záložek z jedné cache: zkouška mapování a měsíční import
  // posílají tytéž soubory a jejich převod u 15 MB není zadarmo.
  async function payloadFiles(): Promise<ImportFilePayload[]> {
    const current = filesFingerprint(files.value)
    if (payloadCache?.fingerprint !== current) {
      payloadCache = { fingerprint: current, files: await filesToPayload(files.value) }
    }
    return payloadCache.files
  }

  function openMapping(profileId: number | null, seedRules: AttendanceRule[] | null = null) {
    sequence += 1
    mappingFocus.value = { seq: sequence, profileId, seedRules }
    options.onOpenMapping()
  }

  return {
    period,
    files,
    profiles,
    profilesLoading,
    profilesError,
    profileRevision,
    mappingFocus,
    loadProfiles,
    upsertProfile,
    removeProfile,
    payloadFiles,
    openMapping,
  }
}

export function provideAttendanceWorkspace(workspace: AttendanceWorkspace): void {
  provide(KEY, workspace)
}

export function useAttendanceWorkspace(): AttendanceWorkspace {
  const workspace = inject(KEY, null)
  if (!workspace) throw new Error('Attendance import workspace is not provided.')
  return workspace
}
