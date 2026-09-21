<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  dimensionsApi,
  type DimensionGroupInfo,
  type DimensionKind,
  type DimensionLevel,
  type DimensionType,
  type DimensionValue,
} from '@/api/dimensions'
import { accountingApi, type CostCenter } from '@/api/accounting'
import { logbookApi, type Car } from '@/api/logbook'
import { projectsApi, type Project } from '@/api/projects'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, btnFilledSm, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'

/**
 * Firma → Dimenze: typy dimenzí a stromy jejich hodnot. Záložky dělí firemní
 * dimenze (jen tahle firma) a globální (sdílené skupinou firem).
 */
const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const dims = useDimensions()

const KINDS: DimensionKind[] = ['cost_center', 'project', 'vehicle', 'location', 'deal', 'custom']

const loading = ref(true)
const failed = ref(false)
const busy = ref(false)
const level = ref<DimensionLevel>('company')
const selectedTypeId = ref<number | null>(null)
const groupInfo = ref<DimensionGroupInfo | null>(null)
const expanded = ref<Set<number>>(new Set())

const canWrite = computed(() => auth.canWrite('accounting'))
const canManageCompany = computed(() => auth.canWrite('settings.company.write') && auth.isCompanyAdminRole)
const enabled = computed(() => supplierStore.currentSupplier?.dimensions_enabled === true)
const group = computed(() => dims.overview.value?.group ?? null)
const typesOfLevel = computed(() => dims.types.value.filter(ty => ty.level === level.value))
const selectedType = computed(() => dims.types.value.find(ty => ty.id === selectedTypeId.value) ?? null)

async function load() {
  loading.value = true
  failed.value = false
  try {
    await dims.reload()
    if (selectedTypeId.value === null || !typesOfLevel.value.some(ty => ty.id === selectedTypeId.value)) {
      selectedTypeId.value = typesOfLevel.value[0]?.id ?? null
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadGroup() {
  try {
    groupInfo.value = await dimensionsApi.group()
  } catch {
    groupInfo.value = null
  }
}

onMounted(async () => {
  if (!enabled.value) {
    loading.value = false
    return
  }
  await load()
  await loadGroup()
})

watch(level, () => {
  selectedTypeId.value = typesOfLevel.value[0]?.id ?? null
  closeForms()
})

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

async function run<T>(fn: () => Promise<T>, success?: string): Promise<T | null> {
  busy.value = true
  try {
    const result = await fn()
    if (success) toast.success(success)
    return result
  } catch (e) {
    toast.error(errorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

async function enable() {
  const data = await run(() => dimensionsApi.setEnabled(true, true), t('dimensions.enabled_toast'))
  if (!data) return
  supplierStore.patchSupplier(supplierStore.currentSupplierId, { dimensions_enabled: true })
  dims.setOverview(data)
  await load()
  await loadGroup()
}

async function createDefaults() {
  const data = await run(() => dimensionsApi.createDefaults(), t('dimensions.defaults_created'))
  if (!data) return
  dims.setOverview(data)
  await load()
}

// ── typy ─────────────────────────────────────────────────────────────────

const typeFormOpen = ref(false)
const typeForm = reactive({
  id: null as number | null,
  code: '',
  name: '',
  kind: 'custom' as DimensionKind,
  level: 'company' as DimensionLevel,
  show_on_documents: true,
  is_active: true,
  sort_order: 100,
})

function newType() {
  closeForms()
  Object.assign(typeForm, { id: null, code: '', name: '', kind: 'custom', level: level.value, show_on_documents: true, is_active: true, sort_order: 100 })
  typeFormOpen.value = true
}

function editType(type: DimensionType) {
  closeForms()
  Object.assign(typeForm, {
    id: type.id, code: type.code, name: type.name, kind: type.kind, level: type.level,
    show_on_documents: type.show_on_documents, is_active: type.is_active, sort_order: type.sort_order,
  })
  typeFormOpen.value = true
}

async function saveType() {
  const payload = {
    name: typeForm.name.trim(),
    show_on_documents: typeForm.show_on_documents,
    is_active: typeForm.is_active,
    sort_order: typeForm.sort_order,
  }
  const result = typeForm.id === null
    ? await run(() => dimensionsApi.createType({ ...payload, code: typeForm.code.trim(), kind: typeForm.kind, level: typeForm.level }), t('common.saved'))
    : await run(() => dimensionsApi.updateType(typeForm.id as number, payload), t('common.saved'))
  if (!result) return
  typeFormOpen.value = false
  await load()
  selectedTypeId.value = result.id
}

async function removeType(type: DimensionType) {
  if (!confirm(t('dimensions.type_delete_confirm', { name: type.name }))) return
  const result = await run(() => dimensionsApi.deleteType(type.id))
  if (!result) return
  toast.success(result.deleted ? t('common.deleted') : t('dimensions.type_deactivated'))
  await load()
}

// ── hodnoty ──────────────────────────────────────────────────────────────

const valueFormOpen = ref(false)
const valueForm = reactive({
  id: null as number | null,
  code: '',
  name: '',
  parent_id: null as number | null,
  is_active: true,
  responsible_user_id: null as number | null,
  responsible_note: '',
  car_id: null as number | null,
  project_id: null as number | null,
  cost_center_id: null as number | null,
  note: '',
})

const candidates = ref<{ id: number; name: string }[]>([])
const cars = ref<Car[]>([])
const projects = ref<Project[]>([])
const costCenters = ref<CostCenter[]>([])
let linksLoaded = false

async function loadLinks() {
  if (linksLoaded) return
  linksLoaded = true
  const [c, ca, pr, cc] = await Promise.allSettled([
    dimensionsApi.responsibleCandidates(),
    logbookApi.listCars(),
    projectsApi.list({ per_page: 500, sort: 'name' }),
    accountingApi.listCostCenters(true),
  ])
  if (c.status === 'fulfilled') candidates.value = c.value
  if (ca.status === 'fulfilled') cars.value = ca.value
  if (pr.status === 'fulfilled') projects.value = pr.value.data
  if (cc.status === 'fulfilled') costCenters.value = cc.value
}

const tree = computed(() => (selectedTypeId.value ? dims.treeOf(selectedTypeId.value) : []))
const childCount = computed(() => {
  const out = new Map<number, number>()
  for (const { value } of tree.value) {
    if (value.parent_id !== null) out.set(value.parent_id, (out.get(value.parent_id) ?? 0) + 1)
  }
  return out
})
/** Viditelné řádky stromu — podřízené sbalené hodnoty se schovají. */
const visibleTree = computed(() => {
  const hidden = new Set<number>()
  return tree.value.filter(({ value }) => {
    if (value.parent_id !== null && (hidden.has(value.parent_id) || !expanded.value.has(value.parent_id))) {
      hidden.add(value.id)
      return false
    }
    return true
  })
})

function toggle(valueId: number) {
  const next = new Set(expanded.value)
  if (next.has(valueId)) next.delete(valueId)
  else next.add(valueId)
  expanded.value = next
}

/** Hodnoty, které mají podřízené — jen ty jde rozbalit. */
const parentIds = computed(() => [...childCount.value.keys()])
const allExpanded = computed(() => parentIds.value.length > 0 && parentIds.value.every(id => expanded.value.has(id)))

function toggleAll() {
  expanded.value = allExpanded.value ? new Set() : new Set(parentIds.value)
}

/** Možní rodiče: hodnoty typu kromě hodnoty samé a její větve. */
const parentOptions = computed(() => {
  const blocked = new Set<number>()
  if (valueForm.id !== null) {
    blocked.add(valueForm.id)
    let changed = true
    while (changed) {
      changed = false
      for (const { value } of tree.value) {
        if (value.parent_id !== null && blocked.has(value.parent_id) && !blocked.has(value.id)) {
          blocked.add(value.id)
          changed = true
        }
      }
    }
  }
  return tree.value.filter(({ value }) => !blocked.has(value.id))
})

function newValue(parentId: number | null = null) {
  closeForms()
  Object.assign(valueForm, {
    id: null, code: '', name: '', parent_id: parentId, is_active: true, responsible_user_id: null,
    responsible_note: '', car_id: null, project_id: null, cost_center_id: null, note: '',
  })
  if (parentId !== null) expanded.value = new Set([...expanded.value, parentId])
  valueFormOpen.value = true
  void loadLinks()
}

function editValue(value: DimensionValue) {
  closeForms()
  Object.assign(valueForm, {
    id: value.id, code: value.code, name: value.name, parent_id: value.parent_id, is_active: value.is_active,
    responsible_user_id: value.responsible_user_id, responsible_note: value.responsible_note ?? '',
    car_id: value.car_id, project_id: value.project_id, cost_center_id: value.cost_center_id, note: value.note ?? '',
  })
  valueFormOpen.value = true
  void loadLinks()
}

function closeForms() {
  typeFormOpen.value = false
  valueFormOpen.value = false
}

async function saveValue() {
  if (!selectedType.value) return
  const payload = {
    name: valueForm.name.trim(),
    parent_id: valueForm.parent_id,
    is_active: valueForm.is_active,
    responsible_user_id: valueForm.responsible_user_id,
    responsible_note: valueForm.responsible_note.trim() || null,
    note: valueForm.note.trim() || null,
    ...(selectedType.value.level === 'company' ? {
      car_id: valueForm.car_id,
      project_id: valueForm.project_id,
      ...(valueForm.id !== null || valueForm.cost_center_id !== null ? { cost_center_id: valueForm.cost_center_id } : {}),
    } : {}),
  }
  const typeId = selectedType.value.id
  const result = valueForm.id === null
    ? await run(() => dimensionsApi.createValue(typeId, { ...payload, code: valueForm.code.trim() }), t('common.saved'))
    : await run(() => dimensionsApi.updateValue(valueForm.id as number, payload), t('common.saved'))
  if (!result) return
  valueFormOpen.value = false
  await load()
}

async function toggleActive(value: DimensionValue) {
  const result = await run(() => dimensionsApi.updateValue(value.id, { is_active: !value.is_active }),
    value.is_active ? t('dimensions.value_closed_toast') : t('dimensions.value_opened_toast'))
  if (result) await load()
}

async function removeValue(value: DimensionValue) {
  if (!confirm(t('dimensions.value_delete_confirm', { name: value.name }))) return
  const result = await run(() => dimensionsApi.deleteValue(value.id))
  if (!result) return
  toast.success(result.deleted ? t('common.deleted') : t('dimensions.value_closed_due_to_usage'))
  await load()
}

// ── skupina firem ────────────────────────────────────────────────────────

const groupName = ref('')
const joinGroupId = ref<number | null>(null)

async function createGroup() {
  const info = await run(() => dimensionsApi.createGroup(groupName.value), t('dimensions.group_created'))
  if (!info) return
  groupInfo.value = info
  groupName.value = ''
  await load()
}

async function joinGroup() {
  if (!joinGroupId.value) return
  const info = await run(() => dimensionsApi.joinGroup(joinGroupId.value as number), t('dimensions.group_joined'))
  if (!info) return
  groupInfo.value = info
  await load()
}

async function leaveGroup() {
  if (!confirm(t('dimensions.group_leave_confirm'))) return
  const info = await run(() => dimensionsApi.leaveGroup(), t('dimensions.group_left'))
  if (!info) return
  groupInfo.value = info
  await load()
}

function kindLabel(kind: DimensionKind) {
  return t(`dimensions.kind.${kind}`)
}

function valueCount(typeId: number) {
  return dims.values.value.filter(v => v.type_id === typeId).length
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dimensions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('dimensions.subtitle') }}</p>
      </div>
      <div v-if="enabled" class="flex flex-wrap gap-2">
        <RouterLink to="/accounting/dimension-profit" :class="btnOutline('neutral')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('dimensions.profit_link') }}
        </RouterLink>
        <button v-if="canWrite" type="button" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap" @click="newType">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('dimensions.type_new') }}
        </button>
      </div>
    </div>

    <EmptyState v-if="!enabled" boxed icon="tag"
      :title="t('dimensions.disabled_title')"
      :message="t('dimensions.disabled_hint')"
      :cta="canManageCompany ? t('dimensions.enable') : undefined"
      @action="enable" />

    <template v-else>
      <div class="flex flex-wrap gap-2 border-b border-neutral-200 mb-4" role="tablist">
        <button v-for="tab in (['company', 'global'] as const)" :key="tab" type="button" role="tab"
                :aria-selected="level === tab"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
                :class="level === tab ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                :data-test="`dimensions-tab-${tab}`"
                @click="level = tab">
          {{ t(`dimensions.tab_${tab}`) }}
        </button>
      </div>

      <!-- Skupina firem: jen u globálních dimenzí. -->
      <section v-if="level === 'global'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4" data-test="dimensions-group">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('dimensions.group_title') }}</h2>
        <template v-if="group">
          <p class="text-sm"><span class="font-medium">{{ group.name }}</span></p>
          <ul class="mt-2 flex flex-wrap gap-2">
            <li v-for="m in group.members" :key="m.id" class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">
              {{ m.company_name }}<span v-if="m.ic" class="text-neutral-400"> · {{ m.ic }}</span>
            </li>
          </ul>
          <button v-if="canManageCompany" type="button" :disabled="busy" :class="btnOutlineSm('danger')" class="mt-3" @click="leaveGroup">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('dimensions.group_leave') }}
          </button>
        </template>
        <template v-else>
          <p class="text-sm text-neutral-600">{{ t('dimensions.group_none') }}</p>
          <div v-if="canManageCompany" class="mt-3 flex flex-wrap items-end gap-2">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.group_name') }}</label>
              <input v-model="groupName" type="text" maxlength="190" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <button type="button" :disabled="busy || !groupName.trim()" :class="btnFilled('primary')" class="whitespace-nowrap" @click="createGroup">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('dimensions.group_create') }}
            </button>
            <template v-if="(groupInfo?.candidates.length ?? 0) > 0">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.group_join_label') }}</label>
                <select v-model="joinGroupId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option :value="null">—</option>
                  <option v-for="g in groupInfo?.candidates" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
              </div>
              <button type="button" :disabled="busy || !joinGroupId" :class="btnOutline('primary')" class="whitespace-nowrap" @click="joinGroup">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
                {{ t('dimensions.group_join') }}
              </button>
            </template>
          </div>
        </template>
      </section>

      <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
      <EmptyState v-else-if="typesOfLevel.length === 0 && (level === 'company' || group)" boxed icon="tag"
        :title="t('dimensions.types_empty')"
        :message="t('dimensions.types_empty_hint')"
        :cta="canWrite ? t('dimensions.defaults_create') : undefined"
        @action="createDefaults" />

      <div v-else-if="typesOfLevel.length > 0" class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4">
        <!-- Typy -->
        <nav class="bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 self-start" data-test="dimension-types">
          <button v-for="type in typesOfLevel" :key="type.id" type="button"
                  class="w-full text-left px-4 py-3 hover:bg-neutral-50"
                  :class="{ 'bg-primary-50': type.id === selectedTypeId, 'opacity-60': !type.is_active }"
                  @click="selectedTypeId = type.id; closeForms()">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium">{{ type.name }}</span>
              <span class="text-xs text-neutral-400">{{ valueCount(type.id) }}</span>
            </div>
            <div class="text-xs text-neutral-500">
              {{ kindLabel(type.kind) }}<span v-if="!type.show_on_documents"> · {{ t('dimensions.type_hidden_on_documents') }}</span>
            </div>
          </button>
        </nav>

        <!-- Strom hodnot -->
        <section v-if="selectedType" class="bg-surface border border-neutral-200 rounded-lg shadow-sm" data-test="dimension-tree">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
            <div>
              <h2 class="text-lg font-semibold">{{ selectedType.name }}</h2>
              <p class="text-xs text-neutral-500">{{ kindLabel(selectedType.kind) }} · {{ t(`dimensions.level_${selectedType.level}`) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button v-if="parentIds.length > 0" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="dimension-toggle-all" @click="toggleAll">
                <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': allExpanded }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
                {{ allExpanded ? t('dimensions.collapse_all') : t('dimensions.expand_all') }}
              </button>
              <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="editType(selectedType)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('dimensions.type_edit') }}
              </button>
              <button v-if="canWrite" type="button" :class="btnOutlineSm('danger')" class="whitespace-nowrap" @click="removeType(selectedType)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
              <button v-if="canWrite" type="button" :class="btnFilledSm('primary')" class="whitespace-nowrap" data-test="dimension-value-new" @click="newValue(null)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('dimensions.value_new') }}
              </button>
            </div>
          </div>

          <p v-if="tree.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('dimensions.values_empty') }}</p>
          <ul v-else class="divide-y divide-neutral-100">
            <li v-for="{ value, depth } in visibleTree" :key="value.id"
                class="flex flex-wrap items-center gap-2 px-4 py-2"
                :class="{ 'opacity-60': !value.is_active }"
                :data-test="`dimension-value-${value.code}`">
              <div class="flex items-center gap-2 min-w-0 flex-1" :style="{ paddingLeft: `${depth * 1.25}rem` }">
                <button v-if="childCount.get(value.id)" type="button" class="w-5 h-5 inline-flex items-center justify-center text-neutral-500"
                        :aria-expanded="expanded.has(value.id)" :aria-label="t('dimensions.toggle_children')" @click="toggle(value.id)">
                  <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-90': expanded.has(value.id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
                <span v-else class="w-5" />
                <span class="font-mono text-xs text-neutral-500 whitespace-nowrap">{{ value.code }}</span>
                <span class="truncate">{{ value.name }}</span>
                <span v-if="!value.is_active" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ t('dimensions.closed') }}</span>
                <span v-if="value.car_registration" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ value.car_registration }}</span>
                <span v-if="value.cost_center_code" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ t('dimensions.cost_center_short') }} {{ value.cost_center_code }}</span>
                <span v-if="value.responsible_user_name || value.responsible_note" class="text-xs text-neutral-500 truncate">
                  · {{ value.responsible_user_name || value.responsible_note }}
                </span>
              </div>
              <div v-if="canWrite" class="flex flex-wrap justify-end gap-1">
                <button type="button" :class="btnOutlineSm('neutral')" :title="t('dimensions.value_add_child')" @click="newValue(value.id)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  <span class="hidden sm:inline">{{ t('dimensions.value_add_child') }}</span>
                </button>
                <button type="button" :class="btnOutlineSm('neutral')" :title="t('common.edit')" @click="editValue(value)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" :class="btnOutlineSm(value.is_active ? 'warning' : 'success')"
                        :title="value.is_active ? t('dimensions.value_close') : t('dimensions.value_open')" @click="toggleActive(value)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="value.is_active ? ICONS.lock : ICONS.check" /></svg>
                </button>
                <button type="button" :class="btnOutlineSm('danger')" :title="t('common.delete')" @click="removeValue(value)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <!-- Formulář typu -->
      <Modal v-if="typeFormOpen && canWrite" :title="typeForm.id === null ? t('dimensions.type_new') : t('dimensions.type_edit')"
             width-class="max-w-2xl" @close="typeFormOpen = false">
       <div class="space-y-4" data-test="dimension-type-form">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.name') }}</label>
            <input v-model="typeForm.name" type="text" maxlength="100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.code') }}</label>
            <input v-model="typeForm.code" type="text" maxlength="30" :disabled="typeForm.id !== null" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.type_code_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.kind_label') }}</label>
            <select v-model="typeForm.kind" :disabled="typeForm.id !== null" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100">
              <option v-for="k in KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.level_label') }}</label>
            <select v-model="typeForm.level" :disabled="typeForm.id !== null" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100">
              <option value="company">{{ t('dimensions.level_company') }}</option>
              <option value="global" :disabled="!group">{{ t('dimensions.level_global') }}</option>
            </select>
            <p v-if="!group" class="text-xs text-neutral-400 mt-1">{{ t('dimensions.level_global_needs_group') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.sort_order') }}</label>
            <input v-model.number="typeForm.sort_order" type="number" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="typeForm.show_on_documents" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.show_on_documents') }}
        </label>
        <label v-if="typeForm.id !== null" class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="typeForm.is_active" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.type_active') }}
        </label>
       </div>
       <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="typeFormOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || !typeForm.name.trim() || (typeForm.id === null && !typeForm.code.trim())" :class="btnFilled('primary')" class="whitespace-nowrap" @click="saveType">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ busy ? t('common.saving') : t('common.save') }}
          </button>
        </div>
       </template>
      </Modal>

      <!-- Formulář hodnoty -->
      <Modal v-if="valueFormOpen && canWrite && selectedType" :title="valueForm.id === null ? t('dimensions.value_new') : t('dimensions.value_edit')"
             width-class="max-w-2xl" @close="valueFormOpen = false">
       <div class="space-y-4" data-test="dimension-value-form">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.name') }}</label>
            <input v-model="valueForm.name" type="text" maxlength="190" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" data-test="dimension-value-name" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.code') }}</label>
            <input v-model="valueForm.code" type="text" maxlength="50" :disabled="valueForm.id !== null" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" data-test="dimension-value-code" />
            <p v-if="valueForm.id !== null" class="text-xs text-neutral-400 mt-1">{{ t('dimensions.code_immutable') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.parent') }}</label>
            <select v-model="valueForm.parent_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option :value="null">{{ t('dimensions.parent_none') }}</option>
              <option v-for="{ value, depth } in parentOptions" :key="value.id" :value="value.id">
                {{ '  '.repeat(depth) }}{{ value.code }} – {{ value.name }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.responsible') }}</label>
            <select v-model="valueForm.responsible_user_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option :value="null">—</option>
              <option v-for="u in candidates" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.responsible_note') }}</label>
            <input v-model="valueForm.responsible_note" type="text" maxlength="190" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.responsible_hint') }}</p>
          </div>
          <template v-if="selectedType.level === 'company'">
            <div v-if="selectedType.kind === 'vehicle' || valueForm.car_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_car') }}</label>
              <select v-model="valueForm.car_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">—</option>
                <option v-for="car in cars" :key="car.id" :value="car.id">{{ car.registration }}{{ car.name ? ` – ${car.name}` : '' }}</option>
              </select>
            </div>
            <div v-if="selectedType.kind === 'cost_center' || valueForm.cost_center_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_cost_center') }}</label>
              <select v-model="valueForm.cost_center_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">{{ valueForm.id === null ? t('dimensions.cost_center_auto') : '—' }}</option>
                <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.code }} – {{ cc.name }}</option>
              </select>
            </div>
            <div v-if="selectedType.kind === 'project' || valueForm.project_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_project') }}</label>
              <select v-model="valueForm.project_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">—</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
          </template>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.note') }}</label>
            <input v-model="valueForm.note" type="text" maxlength="500" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="valueForm.is_active" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.value_active') }}
        </label>
       </div>
       <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="valueFormOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || !valueForm.name.trim() || (valueForm.id === null && !valueForm.code.trim())" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="dimension-value-save" @click="saveValue">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ busy ? t('common.saving') : t('common.save') }}
          </button>
        </div>
       </template>
      </Modal>
    </template>
  </div>
</template>
