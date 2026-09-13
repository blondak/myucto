<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollImportsApi,
  type AttendanceApplyResult,
  type AttendancePersonCreate,
  type AttendancePersonsResult,
  type AttendancePreview,
} from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import AttendancePersonsStep from './AttendancePersonsStep.vue'
import AttendanceSummaryStep from './AttendanceSummaryStep.vue'
import AttendanceBatchHistory from './AttendanceBatchHistory.vue'
import AttendanceRecognitionStrip from './AttendanceRecognitionStrip.vue'
import { useAttendanceWorkspace } from './attendanceWorkspace'
import {
  autoPersonCreateDefaults,
  buildAttendanceLinks,
  buildPersonsPayload,
  chunk,
  componentsToCreate,
  filesFingerprint,
  guessRelationType,
  isValidPeriod,
  personCanBeCreated,
  personsWithoutEmploymentCount,
  pruneManualLinks,
  type ManualLinks,
} from './importHelpers'

const props = defineProps<{
  canWrite: boolean
  canCreatePersons: boolean
}>()

const { t, te } = useI18n()
const toast = useToast()
const workspace = useAttendanceWorkspace()
const { period, files, profiles } = workspace

type Step = 1 | 2 | 3
const STEPS: Step[] = [1, 2, 3]
const STEP_KEYS: Record<Step, string> = { 1: 'upload', 2: 'persons', 3: 'summary' }

const profileId = ref<number | null>(null)
const step = ref<Step>(1)
const preview = ref<AttendancePreview | null>(null)
const previewFingerprint = ref('')
const manualLinks = ref<ManualLinks>({})
const createResults = ref<AttendancePersonsResult | null>(null)
const saveLinks = ref(true)
const createInputs = ref(true)
const createComponents = ref(true)
const adoptPersonalNumbers = ref(true)
const autoCreateMissingPersons = ref(true)
const autoCreateResult = ref<AttendancePersonsResult | null>(null)
const applyPhase = ref<'creating' | 'importing' | null>(null)
const result = ref<AttendanceApplyResult | null>(null)
const busy = ref<'preview' | 'apply' | 'persons' | null>(null)
const error = ref('')
const profileStale = ref(false)
const history = ref<InstanceType<typeof AttendanceBatchHistory> | null>(null)

const fingerprint = computed(() => `${period.value}#${filesFingerprint(files.value)}#${profileId.value ?? 'auto'}`)
const links = computed(() => preview.value ? buildAttendanceLinks(preview.value.persons, manualLinks.value) : [])
const fileErrors = computed(() => (preview.value?.files ?? []).filter(file => file.error))
const willCreate = computed(() => preview.value ? componentsToCreate(preview.value.component_checks) : [])

const loadBlockedReason = computed(() => {
  if (!isValidPeriod(period.value)) return t('payroll_imports.attendance.reason.no_period')
  if (files.value.length === 0) return t('payroll_imports.attendance.reason.no_files')
  return ''
})
const applyBlockedReason = computed(() => {
  if (!preview.value) return t('payroll_imports.attendance.reason.no_preview')
  if (profileStale.value) return t('payroll_imports.attendance.reason.profile_changed')
  if (links.value.length === 0 && !(autoCreateMissingPersons.value && showAutoCreateOption.value)) {
    return t('payroll_imports.attendance.reason.nothing_to_apply')
  }
  return ''
})
const creatableMissingPersons = computed(() =>
  preview.value ? preview.value.persons.filter(person => personCanBeCreated(person, manualLinks.value)) : [])
const missingEmploymentCount = computed(() =>
  preview.value ? personsWithoutEmploymentCount(preview.value.persons, manualLinks.value) : 0)
const showAutoCreateOption = computed(() => props.canCreatePersons && creatableMissingPersons.value.length > 0)
const applyButtonLabel = computed(() => {
  if (applyPhase.value === 'creating') return t('payroll_imports.attendance.summary.phase_creating')
  if (applyPhase.value === 'importing') return t('payroll_imports.attendance.summary.phase_importing')
  if (busy.value === 'apply') return t('payroll_imports.common.working')
  return t('payroll_imports.attendance.summary.apply', { count: links.value.length })
})

// Jiné období, soubory nebo profil = jiný náhled; vazby osob se týkaly toho starého.
watch(fingerprint, value => {
  result.value = null
  autoCreateResult.value = null
  if (preview.value && value !== previewFingerprint.value) {
    preview.value = null
    manualLinks.value = {}
    createResults.value = null
    profileStale.value = false
    step.value = 1
  }
})

watch(profiles, list => {
  if (profileId.value !== null && !list.some(profile => profile.id === profileId.value)) profileId.value = null
})

// Profil upravený v záložce Mapování: náhled postavený na starých pravidlech
// už neodpovídá tomu, co by server při použití spočítal.
watch(workspace.profileRevision, revision => {
  if (!revision || !preview.value) return
  if (profileId.value === null || preview.value.profile?.id === revision.profileId) profileStale.value = true
})

function stepReachable(target: Step): boolean {
  return target === 1 || preview.value !== null
}

function goTo(target: Step) {
  if (stepReachable(target)) step.value = target
}

function kindLabel(kind: string | null): string {
  if (!kind) return ''
  const key = `payroll_imports.component_kinds.${kind}`
  return te(key) ? t(key) : kind
}

async function previewSource() {
  return {
    files: await workspace.payloadFiles(),
    rules: null,
    profile_id: profileId.value,
    components: null,
  }
}

// Po dávkách: server bere nejvýš 200 osob na požadavek a firma jich může mít víc.
const PERSONS_PER_REQUEST = 100

async function createPersonsInChunks(payload: AttendancePersonCreate[]): Promise<AttendancePersonsResult> {
  const source = await previewSource()
  const results: AttendancePersonsResult['results'] = []
  for (const part of chunk(payload, PERSONS_PER_REQUEST)) {
    const response = await payrollImportsApi.createAttendancePersons(period.value, part, source)
    results.push(...response.results)
  }
  return { results }
}

async function requestPreview(): Promise<boolean> {
  error.value = ''
  const current = fingerprint.value
  try {
    const response = await payrollImportsApi.previewAttendance({
      period: period.value,
      ...await previewSource(),
    })
    preview.value = response
    previewFingerprint.value = current
    profileStale.value = false
    manualLinks.value = pruneManualLinks(manualLinks.value, response.persons, response.employment_options)
    return true
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.preview_failed'))
    return false
  }
}

async function loadPreview() {
  if (loadBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'preview'
  result.value = null
  createResults.value = null
  manualLinks.value = {}
  try {
    if (await requestPreview()) step.value = 2
  } finally {
    busy.value = null
  }
}

async function reloadPreview() {
  if (busy.value !== null) return
  busy.value = 'preview'
  try {
    await requestPreview()
  } finally {
    busy.value = null
  }
}

function editMapping() {
  const used = preview.value?.profile ?? null
  // Bez profilu se do mapování přenese automatický návrh — z něj vznikne nový profil.
  workspace.openMapping(used?.id ?? null, used?.id == null ? preview.value?.rules ?? null : null)
}

function setLink(personKey: string, employmentId: number | null) {
  const person = preview.value?.persons.find(item => item.key === personKey)
  const next = { ...manualLinks.value }
  // Volba shodná s automatickou shodou není ruční přebití.
  if (person && person.match.employment_id === employmentId) delete next[personKey]
  else next[personKey] = employmentId
  manualLinks.value = next
}

async function createPersons(payload: AttendancePersonCreate[]) {
  if (!preview.value || busy.value !== null) return
  busy.value = 'persons'
  error.value = ''
  try {
    const response = await createPersonsInChunks(payload)
    createResults.value = response
    const created = response.results.filter(item => item.status === 'created').length
    const failed = response.results.length - created
    if (failed > 0) toast.warning(t('payroll_imports.attendance.persons.created', { created, failed }))
    else toast.success(t('payroll_imports.attendance.persons.created', { created, failed }))
    // Založené osoby mají uloženou vazbu, nový náhled je už ukáže jako propojené.
    await requestPreview()
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.persons_failed'))
  } finally {
    busy.value = null
  }
}

async function apply() {
  if (!preview.value || applyBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'apply'
  error.value = ''
  autoCreateResult.value = null
  try {
    if (autoCreateMissingPersons.value && showAutoCreateOption.value) {
      applyPhase.value = 'creating'
      const payload = buildPersonsPayload(
        creatableMissingPersons.value,
        {},
        autoPersonCreateDefaults(period.value),
        person => guessRelationType(person.relation_label),
      )
      try {
        autoCreateResult.value = await createPersonsInChunks(payload)
      } catch (err) {
        error.value = apiErrorMessage(err, t('payroll_imports.attendance.persons_failed'))
      }
      applyPhase.value = 'importing'
      // requestPreview() by chybu ze založení přepsalo — obnova náhledu ji nesmí smazat.
      const creationError = error.value
      // Nově založené osoby mají uloženou vazbu — nový náhled je ukáže jako propojené.
      await requestPreview()
      if (creationError) error.value = creationError
    }
    if (!preview.value) return
    const response = await payrollImportsApi.applyAttendance({
      period: period.value,
      files: await workspace.payloadFiles(),
      rules: preview.value.rules,
      profile_id: preview.value.profile?.id ?? null,
      components: null,
      links: links.value,
      save_links: saveLinks.value,
      create_inputs: createInputs.value,
      create_components: willCreate.value.length > 0 && createComponents.value,
      adopt_personal_numbers: adoptPersonalNumbers.value,
    })
    result.value = response
    if (response.replayed) toast.warning(t('payroll_imports.attendance.summary.replayed_toast', { id: response.batch.id }))
    else toast.success(t('payroll_imports.attendance.summary.applied', { id: response.batch.id, count: response.inputs.created }))
    void history.value?.reload()
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.apply_failed'))
  } finally {
    busy.value = null
    applyPhase.value = null
  }
}
</script>

<template>
  <section class="space-y-4" data-testid="attendance-import">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.attendance.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.hint') }}</p>
    </div>

    <p v-if="!canWrite" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.attendance.no_permission') }}
    </p>

    <nav class="overflow-x-auto" :aria-label="t('payroll_imports.attendance.steps.label')">
      <ol class="flex min-w-max items-center gap-2">
        <li v-for="item in STEPS" :key="item" class="flex items-center gap-2">
          <button type="button"
            class="inline-flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
            :class="step === item
              ? 'border-payroll-600 bg-payroll-600 text-white'
              : stepReachable(item) ? 'border-payroll-500/40 bg-surface text-payroll-700 hover:bg-payroll-50' : 'border-neutral-200 bg-surface text-neutral-500'"
            :disabled="!stepReachable(item) || busy !== null"
            :aria-current="step === item ? 'step' : undefined"
            @click="goTo(item)">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-xs"
              :class="step === item ? 'bg-white/20' : 'bg-neutral-100 text-neutral-600'">{{ item }}</span>
            {{ t(`payroll_imports.attendance.steps.${STEP_KEYS[item]}`) }}
          </button>
          <span v-if="item < 3" class="h-px w-6 bg-neutral-300" aria-hidden="true" />
        </li>
      </ol>
    </nav>

    <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>

    <!-- 1. Období a soubory -->
    <section v-show="step === 1" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.period') }}</span>
          <input v-model="period" type="month" data-testid="attendance-period" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="!canWrite || busy !== null">
        </label>
        <label class="block lg:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.profile') }}</span>
          <select v-model="profileId" data-testid="attendance-profile" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="!canWrite || busy !== null">
            <option :value="null">{{ t('payroll_imports.attendance.profile_auto') }}</option>
            <option v-for="profile in profiles" :key="profile.id" :value="profile.id">
              {{ profile.name }}{{ profile.is_sample ? ` · ${t('payroll_imports.sample_badge')}` : '' }}
            </option>
          </select>
          <span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.profile_hint') }}</span>
        </label>
      </div>

      <ImportFilesDropzone
        v-model:files="files"
        test-id="attendance-dropzone"
        accept=".xlsx,.csv"
        :allowed-extensions="['xlsx', 'csv']"
        :drop-hint="t('payroll_imports.attendance.drop_hint')"
        :disabled="!canWrite || busy !== null"
      />

      <div class="mt-4 flex flex-col items-start gap-1.5">
        <button type="button" data-testid="attendance-load" :class="btnFilled('primary')"
          :disabled="!canWrite || busy !== null || loadBlockedReason !== ''"
          :title="disabledTitle(loadBlockedReason !== '', loadBlockedReason)" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t('payroll_imports.attendance.load_preview') }}
        </button>
        <p v-if="loadBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ loadBlockedReason }}</p>
      </div>
    </section>

    <template v-if="preview">
      <div v-if="fileErrors.length" role="status" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">
        <p v-for="file in fileErrors" :key="file.name">{{ t('payroll_imports.attendance.file_error', { name: file.name, error: file.error }) }}</p>
      </div>

      <div v-if="profileStale" role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        <p class="max-w-3xl">{{ t('payroll_imports.attendance.profile_stale') }}</p>
        <button type="button" data-testid="attendance-reload" :class="btnOutline('warning')" :disabled="busy !== null" @click="reloadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t('payroll_imports.attendance.reload_preview') }}
        </button>
      </div>

      <AttendanceRecognitionStrip :preview="preview" can-edit @edit-mapping="editMapping" />

      <!-- 2. Osoby -->
      <section v-show="step === 2" class="space-y-4">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.persons.title') }}</h3>
          <p class="max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.persons.intro') }}</p>
        </div>
        <AttendancePersonsStep
          :persons="preview.persons"
          :options="preview.employment_options"
          :manual-links="manualLinks"
          :period="period"
          :can-create="canCreatePersons"
          :disabled="!canWrite || busy !== null"
          :create-results="createResults"
          @set-link="setLink"
          @create="createPersons"
        />
        <div class="flex flex-wrap items-center justify-between gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="busy !== null" @click="goTo(1)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
            {{ t('payroll_imports.attendance.back') }}
          </button>
          <button type="button" data-testid="attendance-to-summary" :class="btnFilled('primary')" :disabled="busy !== null" @click="goTo(3)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chart" /></svg>
            {{ t('payroll_imports.attendance.next_summary') }}
          </button>
        </div>
      </section>

      <!-- 3. Souhrn a použití -->
      <section v-show="step === 3" class="space-y-4">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.title') }}</h3>
          <p class="max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.summary.intro') }}</p>
        </div>
        <AttendanceSummaryStep :preview="preview" :manual-links="manualLinks" />

        <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div v-if="willCreate.length" class="mb-4 rounded-lg border border-primary-500/20 bg-surface p-3 text-sm" data-testid="attendance-create-components">
            <p class="font-medium text-neutral-900">{{ t('payroll_imports.attendance.summary.components_to_create') }}</p>
            <ul class="mt-1 space-y-0.5 text-neutral-700">
              <li v-for="check in willCreate" :key="check.component_code">
                <span class="font-mono text-xs font-medium">{{ check.component_code }}</span>
                <template v-if="check.name"> – {{ check.name }}</template>
                <span v-if="check.kind" class="text-neutral-500"> ({{ kindLabel(check.kind) }})</span>
              </li>
            </ul>
          </div>
          <p v-if="missingEmploymentCount > 0" class="mb-3 text-xs text-neutral-600">
            {{ t('payroll_imports.attendance.summary.missing_employment_note', { count: missingEmploymentCount }) }}
            <button type="button" class="ml-1 font-medium text-payroll-600 hover:underline" @click="goTo(2)">{{ t('payroll_imports.attendance.summary.edit_in_persons_step') }}</button>
          </p>
          <div class="space-y-3">
            <label v-if="showAutoCreateOption" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="autoCreateMissingPersons" type="checkbox" data-testid="attendance-auto-create-persons" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.auto_create_persons', { count: creatableMissingPersons.length }) }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.auto_create_persons_hint') }}</span></span>
            </label>
            <label v-if="willCreate.length" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="createComponents" type="checkbox" data-testid="attendance-create-components-toggle" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.create_components') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.create_components_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="saveLinks" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.save_links') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.save_links_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="adoptPersonalNumbers" type="checkbox" data-testid="attendance-adopt-personal-numbers" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.adopt_personal_numbers') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.adopt_personal_numbers_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="createInputs" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.create_inputs') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.create_inputs_hint') }}</span></span>
            </label>
          </div>
          <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="busy !== null" @click="goTo(2)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll_imports.attendance.back') }}
            </button>
            <div class="flex flex-col items-end gap-1.5">
              <button type="button" data-testid="attendance-apply" :class="btnFilled('success')"
                :disabled="!canWrite || busy !== null || applyBlockedReason !== ''"
                :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)" @click="apply">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                {{ applyButtonLabel }}
              </button>
              <p v-if="applyBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
            </div>
          </div>

          <div v-if="autoCreateResult?.results.some(item => item.status === 'failed')" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-auto-create-errors">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.summary.auto_create_failed_title', { count: autoCreateResult.results.filter(item => item.status === 'failed').length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in autoCreateResult.results.filter(entry => entry.status === 'failed')" :key="item.person_key">{{ item.message }}</li>
            </ul>
          </div>
        </section>

        <section v-if="result" class="rounded-xl border p-4 text-sm sm:p-6" data-testid="attendance-result"
          :class="result.inputs.errors.length ? 'border-warning-500/30 bg-warning-50' : 'border-success-500/30 bg-success-50'">
          <h4 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.result.title') }}</h4>
          <p class="mt-1 text-neutral-700">{{ t('payroll_imports.attendance.result.batch', { id: result.batch.id, period: result.batch.period }) }}</p>
          <p v-if="result.replayed" class="mt-1 font-medium text-warning-700">{{ t('payroll_imports.attendance.result.replayed') }}</p>
          <ul class="mt-2 space-y-0.5 text-neutral-700">
            <li>{{ t('payroll_imports.attendance.result.inputs_created', { count: result.inputs.created }) }}</li>
            <li>{{ t('payroll_imports.attendance.result.duplicates', { count: result.inputs.duplicates }) }}</li>
            <li>{{ t('payroll_imports.attendance.result.links_saved', { count: result.links_saved }) }}</li>
            <li v-if="result.personal_numbers_adopted" data-testid="attendance-personal-numbers-adopted">{{ t('payroll_imports.attendance.result.personal_numbers_adopted', { count: result.personal_numbers_adopted }) }}</li>
          </ul>
          <div v-if="result.personal_number_conflicts?.length" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-personal-number-conflicts">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.personal_number_conflicts_title', { count: result.personal_number_conflicts.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.personal_number_conflicts" :key="item.key">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div v-if="result.inputs.errors.length" class="mt-3">
            <p class="font-medium text-danger-700">{{ t('payroll_imports.attendance.result.errors_title') }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-danger-700">
              <li v-for="item in result.inputs.errors" :key="`${item.row_number}-${item.error_message}`">{{ t('payroll_imports.attendance.result.error_row', { row: item.row_number, message: item.error_message }) }}</li>
            </ul>
          </div>
          <div v-if="result.skipped_persons.length" class="mt-3">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.skipped_title', { count: result.skipped_persons.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.skipped_persons" :key="item.key">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <RouterLink :to="{ name: 'payroll-components', query: { tab: 'inputs', period: result.batch.period } }" :class="btnOutline('primary')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
              {{ t('payroll_imports.attendance.result.open_inputs') }}
            </RouterLink>
          </div>
        </section>
      </section>
    </template>

    <AttendanceBatchHistory ref="history" :period="period" />
  </section>
</template>
