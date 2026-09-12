<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  eshopIntegrationsApi, type ConnectionInput, type ConnectorCatalog, type ConnectorDefinition,
  type IntegrationConnection, type IntegrationDiagnostics, type IntegrationStatus,
} from '@/api/eshopIntegrations'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import IntegrationStepHeading from '@/components/eshop/integrations/IntegrationStepHeading.vue'
import IntegrationMappingEditor from '@/components/eshop/integrations/IntegrationMappingEditor.vue'
import IntegrationOwnershipEditor from '@/components/eshop/integrations/IntegrationOwnershipEditor.vue'
import IntegrationCredentialsForm from '@/components/eshop/integrations/IntegrationCredentialsForm.vue'
import IntegrationDeveloperPanel from '@/components/eshop/integrations/IntegrationDeveloperPanel.vue'
import {
  asObject, isPlaceholderValue, mappingRowsFrom, mappingsFromRows, ownershipFromRows, ownershipRowsFrom, parseJsonObject,
  validateMappingsObject, validateOwnershipObject,
  type CredentialDraft, type MappingIssue, type MappingRows, type OwnershipIssue, type OwnershipRow,
} from '@/utils/integrationEditor'

type EditableStatus = Exclude<IntegrationStatus, never>
interface FormState {
  connector_key: string
  name: string
  status: EditableStatus
  rate_limit_per_minute: number
  retention_days: number
}
type StepId = 'connector' | 'mapping' | 'ownership' | 'credentials' | 'webhook' | 'activation'
type StepState = 'done' | 'todo' | 'optional' | 'after_save'

const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()

const connections = ref<IntegrationConnection[]>([])
const catalog = ref<ConnectorCatalog | null>(null)
const selectedId = ref<number | null>(null)
const diagnostics = ref<IntegrationDiagnostics | null>(null)
const loading = ref(false)
const failed = ref(false)
const acting = ref(false)
const creating = ref(false)
const webhookSecret = ref<string | null>(null)
const showExample = ref(false)
const formErrors = ref<string[]>([])
let timer: ReturnType<typeof setInterval> | undefined

const emptyForm = (): FormState => ({ connector_key: '', name: '', status: 'draft', rate_limit_per_minute: 60, retention_days: 30 })
const form = ref<FormState>(emptyForm())
const mappingRows = ref<MappingRows>({})
const ownershipRows = ref<OwnershipRow[]>([])
const credentialDraft = ref<CredentialDraft>({ values: {}, clear: [] })
const legacyCredentialJson = ref('')
const advanced = ref(false)
const mappingsJson = ref('{}')
const ownershipJson = ref('{}')
const baseline = ref('')

const selected = computed(() => connections.value.find(row => row.id === selectedId.value) ?? null)
const canWrite = computed(() => auth.canWrite('eshop.integrations'))
const activeJob = computed(() => diagnostics.value?.jobs.find(job => ['queued', 'running'].includes(job.status)) ?? null)
const definitions = computed(() => catalog.value?.connectors ?? [])
const definition = computed<ConnectorDefinition | null>(() => definitions.value.find(item => item.key === form.value.connector_key) ?? null)
const isLegacy = computed(() => form.value.connector_key !== '' && catalog.value !== null && definition.value === null)
const jsonMode = computed(() => isLegacy.value || advanced.value)
const lookups = computed(() => catalog.value?.lookups ?? {})
const storedCredentials = computed(() => selected.value?.credentials_fields ?? [])

const statusClass: Record<IntegrationStatus, string> = {
  draft: 'bg-neutral-100 text-neutral-700', active: 'bg-success-50 text-success-700',
  paused: 'bg-warning-50 text-warning-700', error: 'bg-danger-50 text-danger-700',
}
const stepStateClass: Record<StepState, string> = {
  done: 'border-success-500/40 bg-success-50 text-success-700',
  todo: 'border-warning-500/40 bg-warning-50 text-warning-700',
  optional: 'border-neutral-300 bg-surface text-neutral-600',
  after_save: 'border-neutral-300 bg-surface text-neutral-500',
}

function connectorLabel(key: string) {
  const item = definitions.value.find(def => def.key === key)
  return item ? t(`eshop.integrations.connectors.${item.i18n}.name`) : key
}

function snapshot() {
  return JSON.stringify([form.value, mappingRows.value, ownershipRows.value, mappingsJson.value, ownershipJson.value,
    credentialDraft.value, legacyCredentialJson.value])
}
const dirty = computed(() => (selected.value !== null || creating.value) && snapshot() !== baseline.value)

function loadStructured(mappings: unknown, ownership: unknown) {
  mappingRows.value = mappingRowsFrom(mappings, definition.value?.mappings.map(item => item.type) ?? [])
  ownershipRows.value = ownershipRowsFrom(definition.value, ownership)
  mappingsJson.value = JSON.stringify(asObject(mappings), null, 2)
  ownershipJson.value = JSON.stringify(asObject(ownership), null, 2)
}

function resetEditor(connection: IntegrationConnection | null) {
  formErrors.value = []
  advanced.value = false
  credentialDraft.value = { values: {}, clear: [] }
  legacyCredentialJson.value = ''
  if (connection) {
    form.value = {
      connector_key: connection.connector_key, name: connection.name, status: connection.status,
      rate_limit_per_minute: connection.rate_limit_per_minute, retention_days: connection.retention_days,
    }
    loadStructured(connection.mappings, connection.field_ownership)
  } else {
    form.value = emptyForm()
    loadStructured({}, {})
  }
  baseline.value = snapshot()
}

function confirmDiscard() {
  return !dirty.value || window.confirm(t('eshop.integrations.discard_confirm'))
}

function selectConnection(id: number) {
  if (id === selectedId.value || !confirmDiscard()) return
  creating.value = false
  showExample.value = false
  selectedId.value = id
}

// Výchozí hodnoty skládá server (defaults v /connectors), stránka je jen převezme.
function applyDefaults(key: string) {
  const defaults = catalog.value?.defaults?.[key]
  if (defaults) {
    form.value.status = defaults.status
    form.value.rate_limit_per_minute = defaults.rate_limit_per_minute
    form.value.retention_days = defaults.retention_days
  }
  loadStructured(defaults?.mappings ?? {}, defaults?.field_ownership ?? {})
}

function chooseConnector(item: ConnectorDefinition) {
  if (selected.value || !item.available || !canWrite.value) return
  form.value.connector_key = item.key
  if (!form.value.name) form.value.name = t(`eshop.integrations.connectors.${item.i18n}.name`)
  applyDefaults(item.key)
}

// Nové připojení je předvyplněné stejně jako ukázka, uživatel upraví jen rozdíly.
function prefillNew() {
  resetEditor(null)
  const first = definitions.value.find(item => item.available)
  if (first) chooseConnector(first)
  baseline.value = snapshot()
}

function startCreate() {
  if (!confirmDiscard()) return
  selectedId.value = null
  creating.value = true
  showExample.value = false
  webhookSecret.value = null
  diagnostics.value = null
  prefillNew()
}

// Sleduje se ID, ne objekt: po obnovení seznamu (rotace secretu, porovnání)
// by nová instance připojení jinak zahodila rozepsané změny i zobrazený secret.
watch(selectedId, id => {
  webhookSecret.value = null
  diagnostics.value = null
  if (id === null && creating.value) return
  resetEditor(selected.value)
  if (id !== null) void loadDiagnostics(id)
})
watch(() => supplier.currentSupplierId, () => { selectedId.value = null; creating.value = false; void load() })

function errorMessage(e: any) {
  return e?.response?.data?.error?.message || e?.message || t('common.error')
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const [list, connectors] = await Promise.all([eshopIntegrationsApi.list(), eshopIntegrationsApi.connectors()])
    connections.value = list
    catalog.value = connectors
    failed.value = false
    if (selectedId.value && !list.some(row => row.id === selectedId.value)) selectedId.value = null
    // Jediné připojení (typicky předem připravená ukázka) se rovnou otevře.
    if (selectedId.value === null && !creating.value && list.length === 1) selectedId.value = list[0].id
  } catch (e: any) {
    failed.value = true
    if (!silent) toast.error(errorMessage(e))
  } finally { loading.value = false }
}

async function loadDiagnostics(id: number) {
  try { diagnostics.value = await eshopIntegrationsApi.diagnostics(id) }
  catch (e: any) { toast.error(errorMessage(e)) }
}

function mappingIssueText(issue: MappingIssue) {
  const type = t(`eshop.integrations.mapping_types.${issue.type}.title`)
  return t(`eshop.integrations.mapping_errors.${issue.code}`, { type: definition.value ? type : issue.type, row: issue.row ?? '', value: issue.value ?? '' })
}

function ownershipIssueText(issue: OwnershipIssue) {
  return t(`eshop.integrations.ownership_errors.${issue.code}`, { key: issue.key })
}

function parsedJsonState(): { mappings: Record<string, unknown>; ownership: Record<string, unknown> } | null {
  const mappings = parseJsonObject(mappingsJson.value)
  const ownership = parseJsonObject(ownershipJson.value)
  const errors: string[] = []
  if (!mappings) errors.push(t('eshop.integrations.json_invalid', { section: t('eshop.integrations.mappings_json') }))
  if (!ownership) errors.push(t('eshop.integrations.json_invalid', { section: t('eshop.integrations.ownership_json') }))
  formErrors.value = errors
  return mappings && ownership ? { mappings, ownership } : null
}

function toggleAdvanced(event: Event) {
  const input = event.target as HTMLInputElement
  formErrors.value = []
  if (input.checked) {
    const mapped = mappingsFromRows(mappingRows.value)
    if (mapped.issues.length) {
      formErrors.value = mapped.issues.map(mappingIssueText)
    } else {
      mappingsJson.value = JSON.stringify(mapped.value, null, 2)
      ownershipJson.value = JSON.stringify(ownershipFromRows(ownershipRows.value), null, 2)
      advanced.value = true
    }
  } else if (definition.value) {
    const parsed = parsedJsonState()
    if (parsed) {
      const errors = [
        ...validateMappingsObject(parsed.mappings, definition.value, lookups.value).map(mappingIssueText),
        ...validateOwnershipObject(parsed.ownership, definition.value).map(ownershipIssueText),
      ]
      if (errors.length) {
        formErrors.value = errors
      } else {
        loadStructured(parsed.mappings, parsed.ownership)
        advanced.value = false
      }
    }
  }
  input.checked = advanced.value
}

function buildPayload(): ConnectionInput | null {
  formErrors.value = []
  if (!form.value.connector_key) { formErrors.value = [t('eshop.integrations.connector_required')]; return null }
  if (!form.value.name.trim()) { formErrors.value = [t('eshop.integrations.name_required')]; return null }
  if (jsonMode.value) {
    const parsed = parsedJsonState()
    if (!parsed) return null
    return { ...form.value, mappings: parsed.mappings, field_ownership: parsed.ownership as ConnectionInput['field_ownership'] }
  }
  const mapped = mappingsFromRows(mappingRows.value)
  if (mapped.issues.length) { formErrors.value = mapped.issues.map(mappingIssueText); return null }
  return { ...form.value, mappings: mapped.value, field_ownership: ownershipFromRows(ownershipRows.value) }
}

function credentialPayload(): { values: Record<string, string>; clear: string[] } | null | false {
  if (isLegacy.value) {
    if (legacyCredentialJson.value.trim() === '') return null
    const parsed = parseJsonObject(legacyCredentialJson.value)
    if (!parsed) { formErrors.value = [t('eshop.integrations.json_invalid', { section: t('eshop.integrations.credentials_json') })]; return false }
    return { values: parsed as Record<string, string>, clear: [] }
  }
  const values = Object.fromEntries(Object.entries(credentialDraft.value.values)
    .map(([key, value]) => [key, value.trim()]).filter(([, value]) => value !== ''))
  if (Object.keys(values).length === 0 && credentialDraft.value.clear.length === 0) return null
  return { values, clear: credentialDraft.value.clear }
}

async function save() {
  if (acting.value || !canWrite.value) return
  const payload = buildPayload()
  const credentials = payload ? credentialPayload() : null
  if (!payload || credentials === false) { toast.error(t('eshop.integrations.fix_errors')); return }
  acting.value = true
  try {
    const saved = selected.value
      ? await eshopIntegrationsApi.update(selected.value.id, payload)
      : await eshopIntegrationsApi.create(payload)
    let credentialError: string | null = null
    if (credentials) {
      try { await eshopIntegrationsApi.credentials(saved.id, credentials.values, credentials.clear) }
      catch (e: any) { credentialError = errorMessage(e) }
    }
    await load(true)
    creating.value = false
    if (selectedId.value === saved.id) {
      resetEditor(selected.value)
    } else {
      selectedId.value = saved.id
    }
    if (credentialError) {
      formErrors.value = [t('eshop.integrations.credentials_failed', { message: credentialError })]
      toast.error(formErrors.value[0])
    } else {
      toast.success(t('common.saved'))
    }
  } catch (e: any) {
    formErrors.value = [errorMessage(e)]
    toast.error(formErrors.value[0])
  } finally { acting.value = false }
}

function discard() {
  if (selected.value) resetEditor(selected.value)
  else prefillNew()
}

async function createSample() {
  if (acting.value || !canWrite.value) return
  acting.value = true
  try {
    const created = await eshopIntegrationsApi.createSample()
    await load(true)
    creating.value = false
    showExample.value = true
    selectedId.value = created.id
    await nextTick()
    await nextTick()
    scrollToStep('webhook')
    toast.success(t('eshop.integrations.sample_created'))
  } catch (e: any) { toast.error(errorMessage(e)) }
  finally { acting.value = false }
}

async function deleteConnection() {
  if (!selected.value || acting.value) return
  if (!window.confirm(t('eshop.integrations.delete_confirm', { name: selected.value.name }))) return
  acting.value = true
  try {
    await eshopIntegrationsApi.remove(selected.value.id)
    selectedId.value = null
    await load(true)
    toast.success(t('eshop.integrations.deleted'))
  } catch (e: any) { toast.error(errorMessage(e)) }
  finally { acting.value = false }
}

async function rotateSecret() {
  if (!selected.value || acting.value) return
  if (selected.value.webhook_configured && !window.confirm(t('eshop.integrations.rotate_confirm'))) return
  acting.value = true
  try {
    webhookSecret.value = (await eshopIntegrationsApi.rotateWebhookSecret(selected.value.id)).secret
    await load(true)
    toast.success(t('eshop.integrations.webhook_rotated'))
  } catch (e: any) { toast.error(errorMessage(e)) }
  finally { acting.value = false }
}

async function reconcile() {
  if (!selected.value || acting.value) return
  acting.value = true
  try { await eshopIntegrationsApi.reconcile(selected.value.id); await loadDiagnostics(selected.value.id); toast.success(t('eshop.integrations.reconcile_queued')) }
  catch (e: any) { toast.error(errorMessage(e)) }
  finally { acting.value = false }
}

async function retry(eventId: number) {
  if (!selected.value || acting.value) return
  acting.value = true
  try { await eshopIntegrationsApi.retryOutbox(selected.value.id, eventId); await loadDiagnostics(selected.value.id); toast.success(t('eshop.integrations.retry_queued')) }
  catch (e: any) { toast.error(errorMessage(e)) }
  finally { acting.value = false }
}

const mappedCount = computed(() => Object.values(mappingRows.value).reduce((sum, rows) => sum + rows.filter(row => row.local && row.remote.trim()).length, 0))
const hasPlaceholder = computed(() => !jsonMode.value && Object.values(mappingRows.value).some(rows => rows.some(row => isPlaceholderValue(row.remote))))
const requiredCredentialMissing = computed(() => (definition.value?.credentials ?? [])
  .some(field => field.required && !storedCredentials.value.includes(field.key) && !(credentialDraft.value.values[field.key] ?? '').trim()))
const steps = computed<Array<{ id: StepId; state: StepState }>>(() => [
  { id: 'connector', state: form.value.connector_key ? 'done' : 'todo' },
  { id: 'mapping', state: hasPlaceholder.value ? 'todo' : jsonMode.value || mappedCount.value > 0 ? 'done' : 'optional' },
  { id: 'ownership', state: form.value.connector_key ? 'done' : 'todo' },
  { id: 'credentials', state: requiredCredentialMissing.value ? 'todo' : storedCredentials.value.length ? 'done' : 'optional' },
  { id: 'webhook', state: !selected.value ? 'after_save' : selected.value.webhook_configured ? 'done' : 'todo' },
  { id: 'activation', state: form.value.status === 'active' ? 'done' : 'todo' },
])
const editableStatuses = computed<IntegrationStatus[]>(() => form.value.status === 'error' ? ['draft', 'active', 'paused', 'error'] : ['draft', 'active', 'paused'])

function scrollToStep(id: StepId) {
  document.getElementById(`integration-step-${id}`)?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
}

onMounted(async () => { await load(); timer = setInterval(() => { if (selected.value && activeJob.value) void loadDiagnostics(selected.value.id) }, 3000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('eshop.integrations.title') }}</h1><p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.integrations.subtitle') }}</p></div>
      <button v-if="canWrite" type="button" :class="btnFilled('primary')" data-test="new-connection" @click="startCreate"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('eshop.integrations.new') }}</button>
    </div>
    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="failed" variant="failed" boxed @action="load()" />
    <section v-else-if="connections.length === 0 && !creating" class="mx-auto max-w-2xl rounded-lg border border-neutral-200 bg-surface p-6 text-center shadow-sm" data-test="empty-state">
      <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-700"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg></span>
      <h2 class="mt-3 text-lg font-semibold">{{ t('eshop.integrations.empty_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('eshop.integrations.empty_hint') }}</p>
      <template v-if="canWrite">
        <p class="mt-3 text-sm text-neutral-500">{{ t('eshop.integrations.sample_hint') }}</p>
        <div class="mt-4 flex flex-wrap justify-center gap-2">
          <button type="button" :class="btnFilled('primary')" :disabled="acting" data-test="create-sample" @click="createSample"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('eshop.integrations.sample_create') }}</button>
          <button type="button" :class="btnOutline('neutral')" :disabled="acting" data-test="empty-new" @click="startCreate"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('eshop.integrations.new') }}</button>
        </div>
      </template>
      <p v-else class="mt-3 text-sm text-neutral-500">{{ t('eshop.integrations.empty_readonly') }}</p>
    </section>
    <div v-else class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-[minmax(15rem,20rem)_minmax(0,1fr)]">
      <nav class="space-y-2" :aria-label="t('eshop.integrations.connections')">
        <button v-for="connection in connections" :key="connection.id" :data-test="`connection-${connection.id}`" type="button" class="w-full rounded-lg border p-3 text-left shadow-sm transition-colors hover:border-primary-400" :class="selectedId === connection.id ? 'border-primary-500 bg-surface-raised ring-2 ring-primary-500/30' : 'border-neutral-200 bg-surface'" @click="selectConnection(connection.id)">
          <span class="flex flex-wrap items-center justify-between gap-2"><strong class="truncate">{{ connection.name }}</strong><span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass[connection.status]">{{ t(`eshop.integrations.status.${connection.status}`) }}</span></span>
          <span class="mt-1 block truncate text-xs text-neutral-500">{{ connectorLabel(connection.connector_key) }}</span>
        </button>
      </nav>

      <section v-if="selected || creating" class="min-w-0 space-y-4">
        <div class="rounded-lg border border-neutral-200 bg-surface shadow-sm">
          <header class="border-b border-neutral-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="text-lg font-semibold">{{ selected ? selected.name : t('eshop.integrations.new') }}</h2>
              <span v-if="selected" class="flex flex-wrap items-center gap-2">
                <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass[selected.status]">{{ t(`eshop.integrations.status.${selected.status}`) }}</span>
                <button v-if="canWrite" type="button" :class="btnOutline('danger')" :disabled="acting" data-test="delete-connection" @click="deleteConnection"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('eshop.integrations.delete_connection') }}</button>
              </span>
            </div>
            <p class="mt-1 max-w-prose text-sm text-neutral-500">{{ t('eshop.integrations.editor_intro') }}</p>
            <ol class="mt-3 flex flex-wrap gap-2" :aria-label="t('eshop.integrations.steps_title')">
              <li v-for="(step, index) in steps" :key="step.id">
                <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-medium" :class="stepStateClass[step.state]" :data-test="`step-${step.id}`" :data-state="step.state" @click="scrollToStep(step.id)">
                  <span>{{ index + 1 }}.</span>{{ t(`eshop.integrations.step.${step.id}`) }}
                  <span class="opacity-75">· {{ t(`eshop.integrations.step_state.${step.state}`) }}</span>
                </button>
              </li>
            </ol>
          </header>

          <div class="divide-y divide-neutral-200">
            <section id="integration-step-connector" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="1" :title="t('eshop.integrations.connector_title')" :hint="t('eshop.integrations.connector_hint')" />
              <div v-if="isLegacy" class="rounded-md border border-warning-200 bg-warning-50 p-3 text-sm" data-test="legacy-warning">
                <p class="font-medium text-warning-700">{{ t('eshop.integrations.connector_legacy_title', { key: form.connector_key }) }}</p>
                <p class="mt-1 text-warning-700">{{ t('eshop.integrations.connector_legacy_hint') }}</p>
              </div>
              <div v-else class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
                <button v-for="item in definitions" :key="item.key" type="button" class="min-w-0 rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed" :class="[form.connector_key === item.key ? 'border-primary-500 ring-2 ring-primary-500/30' : 'border-neutral-200', item.available ? 'hover:border-primary-400' : 'bg-neutral-50 opacity-80']" :disabled="!item.available || !!selected || !canWrite" :aria-pressed="form.connector_key === item.key" :data-test="`connector-${item.key}`" @click="chooseConnector(item)">
                  <span class="flex flex-wrap items-center justify-between gap-2">
                    <strong class="text-sm">{{ t(`eshop.integrations.connectors.${item.i18n}.name`) }}</strong>
                    <span v-if="!item.available" class="rounded bg-neutral-200 px-2 py-0.5 text-[11px] font-medium text-neutral-700">{{ t('eshop.integrations.connector_coming_soon') }}</span>
                    <span v-else-if="form.connector_key === item.key" class="rounded bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700">{{ t('eshop.integrations.connector_selected') }}</span>
                  </span>
                  <span class="mt-1 block text-xs text-neutral-600">{{ t(`eshop.integrations.connectors.${item.i18n}.description`) }}</span>
                  <span v-if="item.capabilities.length" class="mt-2 flex flex-wrap gap-1">
                    <span v-for="capability in item.capabilities" :key="capability" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-700">{{ t(`eshop.integrations.capability.${capability}`) }}</span>
                  </span>
                  <span v-for="note in item.notes" :key="note" class="mt-2 block text-xs text-neutral-500">{{ t(`eshop.integrations.connectors.${item.i18n}.notes.${note}`) }}</span>
                </button>
              </div>
              <p v-if="selected && !isLegacy" class="text-xs text-neutral-500">{{ t('eshop.integrations.connector_locked') }}</p>
              <fieldset :disabled="!canWrite" class="min-w-0">
                <label class="block max-w-xl text-sm">
                  <span class="mb-1 block font-medium">{{ t('eshop.integrations.name') }}</span>
                  <input v-model="form.name" required maxlength="150" class="form-input w-full" data-test="connection-name" />
                  <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.name_hint') }}</span>
                </label>
              </fieldset>
            </section>

            <section id="integration-step-mapping" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="2" :title="t('eshop.integrations.mapping_title')" :hint="t('eshop.integrations.mapping_hint')">
                <template v-if="definition && !isLegacy" #actions>
                  <label class="inline-flex items-center gap-2 text-xs font-medium text-neutral-600">
                    <input type="checkbox" :checked="advanced" data-test="advanced-toggle" @change="toggleAdvanced" />{{ t('eshop.integrations.advanced_toggle') }}
                  </label>
                </template>
              </IntegrationStepHeading>
              <p v-if="advanced" class="text-xs text-neutral-500">{{ t('eshop.integrations.advanced_hint') }}</p>
              <p v-if="!form.connector_key" class="text-sm text-neutral-500">{{ t('eshop.integrations.pick_connector_first') }}</p>
              <fieldset v-else :disabled="!canWrite" class="min-w-0">
                <label v-if="jsonMode" class="block text-sm">
                  <span class="mb-1 block font-medium">{{ t('eshop.integrations.mappings_json') }}</span>
                  <textarea v-model="mappingsJson" data-test="mappings" rows="8" spellcheck="false" class="form-textarea w-full font-mono text-xs"></textarea>
                </label>
                <IntegrationMappingEditor v-else-if="definition" v-model="mappingRows" :types="definition.mappings" :lookups="lookups" :disabled="!canWrite" />
              </fieldset>
            </section>

            <section id="integration-step-ownership" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="3" :title="t('eshop.integrations.ownership_title')" :hint="t('eshop.integrations.ownership_hint')" />
              <p v-if="!form.connector_key" class="text-sm text-neutral-500">{{ t('eshop.integrations.pick_connector_first') }}</p>
              <fieldset v-else :disabled="!canWrite" class="min-w-0">
                <label v-if="jsonMode" class="block text-sm">
                  <span class="mb-1 block font-medium">{{ t('eshop.integrations.ownership_json') }}</span>
                  <textarea v-model="ownershipJson" data-test="ownership" rows="8" spellcheck="false" class="form-textarea w-full font-mono text-xs"></textarea>
                  <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.ownership_json_hint') }}</span>
                </label>
                <IntegrationOwnershipEditor v-else-if="definition" v-model="ownershipRows" :free-fields="definition.free_fields" :disabled="!canWrite" />
              </fieldset>
            </section>

            <section id="integration-step-credentials" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="4" :title="t('eshop.integrations.credentials_title')" :hint="t('eshop.integrations.credentials_hint')" />
              <p v-if="!form.connector_key" class="text-sm text-neutral-500">{{ t('eshop.integrations.pick_connector_first') }}</p>
              <fieldset v-else :disabled="!canWrite" class="min-w-0">
                <label v-if="isLegacy" class="block text-sm">
                  <span class="mb-1 block font-medium">{{ t('eshop.integrations.credentials_json') }}</span>
                  <textarea v-model="legacyCredentialJson" rows="5" spellcheck="false" autocomplete="off" class="form-textarea w-full font-mono text-xs" placeholder="{}" data-test="credentials-json"></textarea>
                  <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.credentials_legacy_hint') }}</span>
                  <span class="mt-1 block text-xs" :class="selected?.credentials_configured ? 'text-success-700' : 'text-neutral-500'">{{ t(selected?.credentials_configured ? 'eshop.integrations.configured' : 'eshop.integrations.not_configured') }}</span>
                </label>
                <IntegrationCredentialsForm v-else-if="definition" v-model="credentialDraft" :fields="definition.credentials" :stored="storedCredentials" :disabled="!canWrite" />
              </fieldset>
            </section>

            <section id="integration-step-webhook" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="5" :title="t('eshop.integrations.webhook_title')" :hint="t('eshop.integrations.webhook_hint')" />
              <p v-if="definition && !definition.capabilities.includes('webhook_inbound')" class="text-sm text-neutral-500">{{ t('eshop.integrations.webhook_not_used') }}</p>
              <IntegrationDeveloperPanel v-else-if="selected" :connection="selected" :supplier-id="supplier.currentSupplierId ?? null" :can-write="canWrite" :acting="acting" :revealed-secret="webhookSecret" :expand-example="showExample" @rotate="rotateSecret" />
              <p v-else class="text-sm text-neutral-500" data-test="webhook-after-save">{{ t('eshop.integrations.webhook_after_save') }}</p>
            </section>

            <section id="integration-step-activation" class="scroll-mt-20 space-y-4 p-4">
              <IntegrationStepHeading :step="6" :title="t('eshop.integrations.activation_title')" :hint="t('eshop.integrations.activation_hint')" />
              <fieldset :disabled="!canWrite" class="min-w-0 space-y-4">
                <legend class="sr-only">{{ t('eshop.integrations.status_label') }}</legend>
                <div class="grid min-w-0 grid-cols-1 gap-2 md:grid-cols-3">
                  <label v-for="status in editableStatuses" :key="status" class="flex min-w-0 cursor-pointer gap-2 rounded-md border p-3 text-sm" :class="form.status === status ? 'border-primary-500 ring-2 ring-primary-500/20' : 'border-neutral-200'">
                    <input v-model="form.status" type="radio" name="integration-status" :value="status" class="mt-0.5" :disabled="status === 'error'" :data-test="`status-${status}`" />
                    <span class="min-w-0"><span class="block font-medium">{{ t(`eshop.integrations.status.${status}`) }}</span><span class="mt-0.5 block text-xs text-neutral-500">{{ t(`eshop.integrations.status_help.${status}`) }}</span></span>
                  </label>
                </div>
                <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
                  <label class="min-w-0 text-sm">
                    <span class="mb-1 block font-medium">{{ t('eshop.integrations.rate_limit') }}</span>
                    <input v-model.number="form.rate_limit_per_minute" type="number" min="1" max="6000" class="form-input w-full" data-test="rate-limit" />
                    <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.rate_limit_hint') }}</span>
                  </label>
                  <label class="min-w-0 text-sm">
                    <span class="mb-1 block font-medium">{{ t('eshop.integrations.retention') }}</span>
                    <input v-model.number="form.retention_days" type="number" min="1" max="365" class="form-input w-full" data-test="retention" />
                    <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.retention_hint') }}</span>
                  </label>
                </div>
              </fieldset>
            </section>
          </div>

          <div v-if="canWrite" class="sticky bottom-0 z-10 rounded-b-lg border-t border-neutral-200 bg-surface/95 p-3 backdrop-blur">
            <ul v-if="formErrors.length" class="mb-2 list-disc space-y-0.5 pl-5 text-sm text-danger-700" data-test="form-errors">
              <li v-for="(error, index) in formErrors" :key="index">{{ error }}</li>
            </ul>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" data-test="save" :disabled="acting" :class="btnFilled('primary')" @click="save"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
              <button type="button" data-test="discard" :disabled="acting || !dirty" :class="btnOutline('neutral')" @click="discard"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('eshop.integrations.discard') }}</button>
              <span v-if="dirty" class="text-xs text-warning-700" data-test="dirty">{{ t('eshop.integrations.unsaved') }}</span>
            </div>
          </div>
        </div>

        <section v-if="selected" class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h3 class="font-semibold">{{ t('eshop.integrations.diagnostics') }}</h3><p class="mt-1 max-w-prose text-xs text-neutral-500">{{ t('eshop.integrations.diagnostics_hint') }}</p><p class="mt-1 text-xs text-neutral-500">{{ t('eshop.integrations.last_reconcile') }}: {{ diagnostics?.last_synced_at ? formatDateTime(diagnostics.last_synced_at) : t('eshop.integrations.never_synced') }}</p></div><button v-if="canWrite" type="button" :disabled="acting" :class="btnOutline('primary')" data-test="reconcile" @click="reconcile"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('eshop.integrations.reconcile') }}</button></div>
          <CatalogJobProgress v-if="activeJob" class="mt-3" :job="activeJob" />
          <div v-if="diagnostics" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"><div class="rounded bg-neutral-50 p-2"><div class="text-xs text-neutral-500">{{ t('eshop.integrations.inbox_pending') }}</div><strong>{{ (diagnostics.inbox.queued || 0) + (diagnostics.inbox.retry || 0) }}</strong></div><div class="rounded bg-neutral-50 p-2"><div class="text-xs text-neutral-500">{{ t('eshop.integrations.outbox_pending') }}</div><strong>{{ (diagnostics.outbox.pending || 0) + (diagnostics.outbox.retry || 0) }}</strong></div><div class="rounded bg-danger-50 p-2"><div class="text-xs text-danger-700">{{ t('eshop.integrations.dead_letters') }}</div><strong>{{ (diagnostics.inbox.dead_letter || 0) + (diagnostics.outbox.dead_letter || 0) }}</strong></div><div class="rounded bg-success-50 p-2"><div class="text-xs text-success-700">{{ t('eshop.integrations.delivered') }}</div><strong>{{ diagnostics.outbox.delivered || 0 }}</strong></div></div>
          <div v-if="diagnostics?.errors.length" class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-neutral-200 text-left text-xs text-neutral-500"><th class="p-2">{{ t('eshop.integrations.direction') }}</th><th class="p-2">{{ t('eshop.integrations.entity') }}</th><th class="p-2">{{ t('eshop.integrations.error') }}</th><th class="p-2"></th></tr></thead><tbody><tr v-for="error in diagnostics.errors" :key="`${error.direction}-${error.id}`" class="border-b border-neutral-100"><td class="p-2">{{ t(`eshop.integrations.direction_${error.direction}`) }}</td><td class="p-2"><span class="block">{{ error.entity_type }} · {{ error.entity_id }}</span><span class="text-xs text-neutral-500">{{ error.event_type }} · v{{ error.aggregate_version }}</span></td><td class="p-2 text-danger-700">{{ error.last_error_code }}</td><td class="p-2 text-right"><button v-if="canWrite && error.direction === 'outbox' && error.status === 'dead_letter' && !error.payload_redacted" type="button" :disabled="acting" :class="btnOutline('warning')" @click="retry(error.id)"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.retry') }}</button></td></tr></tbody></table></div>
        </section>
      </section>
      <EmptyState v-else dense boxed accent="neutral" icon="link" :title="t('eshop.integrations.select_title')" :message="t('eshop.integrations.select_hint')" />
    </div>
  </div>
</template>
