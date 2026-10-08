<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollDimension,
  type PayrollDimensionType,
  type PayrollEmploymentDimension,
  type PayrollEmploymentDimensionPayload,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{
  employmentId: number
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const editorOpen = ref(false)
const editingId = ref<number | null>(null)
const assignments = ref<PayrollEmploymentDimension[]>([])
const dimensions = ref<PayrollDimension[]>([])
const loadError = ref('')
const saveError = ref('')
const conflict = ref(false)
const showValidation = ref(false)
const form = ref<PayrollEmploymentDimensionPayload>(newAssignment())

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function newAssignment(): PayrollEmploymentDimensionPayload {
  return {
    dimension_id: 0,
    valid_from: localDate(),
    valid_to: null,
    row_version: 0,
  }
}

const dimensionOptions = computed(() => dimensions.value
  .filter(dimension => dimension.is_active)
  .map(dimension => ({
    value: dimension.id,
    label: `${dimension.code} - ${dimension.name}`,
    secondary: t(`payroll.employer.dimensions.type_options.${dimension.dimension_type}`),
  })))
const selectedDimensionOption = computed(() => dimensionOptions.value
  .find(option => option.value === form.value.dimension_id) ?? null)

/*
 * Dimenze i datum účinnosti drží NÁŠ kód, ne zákon: jde o vnitřní členění
 * (středisko, zakázka) pro rozúčtování mzdových nákladů. Povinné zůstávají
 * proto, že bez nich přiřazení nic neznamená — ale musí být vidět, které
 * z nich chybí. Dřív se kliknutí na Uložit u špatného data neprojevilo NIJAK.
 */
const dimensionValid = computed(() =>
  Boolean(form.value.dimension_id) && form.value.dimension_id > 0)
const validFromValid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.value.valid_from))
const validToValid = computed(() => {
  const validTo = nullable(form.value.valid_to)
  if (validTo === null) return true
  return /^\d{4}-\d{2}-\d{2}$/.test(validTo) && validTo >= form.value.valid_from
})
const valid = computed(() =>
  dimensionValid.value && validFromValid.value && validToValid.value)

const invalidReason = computed(() => {
  if (!dimensionValid.value) return t('payroll.people.dimensions.dimension_required')
  if (!validFromValid.value) return t('payroll.people.dimensions.valid_from_required')
  if (!validToValid.value) return t('payroll.people.dimensions.valid_to_invalid')
  return ''
})

/*
 * Prázdný číselník je slepá ulička: nabídka nemá co ukázat a z panelu nevede
 * ven žádná akce. Odkaz míří rovnou na záložku, kde se dimenze zakládají.
 */
const noDimensionsAvailable = computed(() =>
  !loading.value && dimensionOptions.value.length === 0)

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function openNew() {
  editingId.value = null
  form.value = newAssignment()
  saveError.value = ''
  conflict.value = false
  showValidation.value = false
  editorOpen.value = true
}

function edit(assignment: PayrollEmploymentDimension) {
  editingId.value = assignment.id
  form.value = {
    dimension_id: assignment.dimension_id,
    valid_from: assignment.valid_from,
    valid_to: assignment.valid_to,
    row_version: assignment.row_version,
  }
  saveError.value = ''
  conflict.value = false
  showValidation.value = false
  editorOpen.value = true
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [assignmentList, dimensionList] = await Promise.all([
      payrollApi.employmentDimensions(props.employmentId),
      payrollApi.payrollDimensions(),
    ])
    assignments.value = assignmentList
    dimensions.value = dimensionList
  } catch (error: unknown) {
    loadError.value = apiMessage(error) || t('payroll.people.dimensions.load_failed')
  } finally {
    loading.value = false
  }
}

async function reloadCurrent() {
  const assignmentId = editingId.value
  if (assignmentId === null) return
  await load()
  const current = assignments.value.find(assignment => assignment.id === assignmentId)
  if (current) {
    edit(current)
    return
  }
  editorOpen.value = false
  editingId.value = null
}

async function save() {
  showValidation.value = true
  saveError.value = ''
  conflict.value = false
  if (!props.canWrite || !valid.value) return

  saving.value = true
  try {
    const payload: PayrollEmploymentDimensionPayload = {
      dimension_id: form.value.dimension_id,
      valid_from: form.value.valid_from,
      valid_to: nullable(form.value.valid_to),
      row_version: form.value.row_version,
    }
    const saved = editingId.value === null
      ? await payrollApi.createEmploymentDimension(props.employmentId, payload)
      : await payrollApi.updateEmploymentDimension(props.employmentId, editingId.value, payload)
    const index = assignments.value.findIndex(assignment => assignment.id === saved.id)
    if (index === -1) assignments.value.unshift(saved)
    else assignments.value.splice(index, 1, saved)
    editorOpen.value = false
    editingId.value = null
    toast.success(t('payroll.people.dimensions.saved'))
  } catch (error: unknown) {
    const code = apiCode(error)
    conflict.value = code === 'row_version_conflict'
    saveError.value = code === 'employment_dimension_interval_overlap'
      ? t('payroll.people.dimensions.overlap_error')
      : (apiMessage(error) || t('payroll.people.dimensions.save_failed'))
  } finally {
    saving.value = false
  }
}

/*
 * Procentní rozpad (např. 70 / 30 na dvě střediska). Ukládá se celý najednou,
 * protože součet 100 % musí platit v každém dni — po řádcích to nejde.
 * Dosavadní přiřazení téhož typu server ukončí den před začátkem rozpadu.
 */
interface SplitRow { dimension_id: number; share_percent: number | null }
const splitOpen = ref(false)
const splitSaving = ref(false)
const splitError = ref('')
const splitShowValidation = ref(false)
const splitType = ref<PayrollDimensionType>('cost_center')
const splitValidFrom = ref(localDate())
const splitValidTo = ref<string | null>(null)
const splitRows = ref<SplitRow[]>([])

const splitTypeOptions = computed(() => (['cost_center', 'project', 'activity'] as PayrollDimensionType[])
  .map(value => ({ value, label: t(`payroll.employer.dimensions.type_options.${value}`) })))
const splitDimensionOptions = computed(() => dimensionOptions.value
  .filter(option => dimensions.value.find(dimension => dimension.id === option.value)?.dimension_type === splitType.value))
const splitTotal = computed(() => Math.round(splitRows.value
  .reduce((sum, row) => sum + (Number(row.share_percent) || 0), 0) * 100) / 100)
const splitValid = computed(() => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(splitValidFrom.value)) return false
  const validTo = nullable(splitValidTo.value)
  if (validTo !== null && validTo < splitValidFrom.value) return false
  if (splitRows.value.length === 0) return false
  const ids = splitRows.value.map(row => row.dimension_id)
  if (ids.some(id => !id) || new Set(ids).size !== ids.length) return false
  if (splitRows.value.some(row => !(Number(row.share_percent) > 0))) return false
  return splitTotal.value === 100
})

function openSplit() {
  editorOpen.value = false
  splitType.value = 'cost_center'
  splitValidFrom.value = localDate()
  splitValidTo.value = null
  splitRows.value = [
    { dimension_id: 0, share_percent: 50 },
    { dimension_id: 0, share_percent: 50 },
  ]
  splitError.value = ''
  splitShowValidation.value = false
  splitOpen.value = true
}

function changeSplitType(type: PayrollDimensionType | null) {
  splitType.value = type ?? 'cost_center'
  splitRows.value.forEach(row => { row.dimension_id = 0 })
}

function addSplitRow() {
  splitRows.value.push({ dimension_id: 0, share_percent: null })
}

function removeSplitRow(index: number) {
  splitRows.value.splice(index, 1)
}

async function saveSplit() {
  splitShowValidation.value = true
  splitError.value = ''
  if (!props.canWrite || !splitValid.value) return
  splitSaving.value = true
  try {
    await payrollApi.splitEmploymentDimension(props.employmentId, {
      dimension_type: splitType.value,
      valid_from: splitValidFrom.value,
      valid_to: nullable(splitValidTo.value),
      shares: splitRows.value.map(row => ({
        dimension_id: row.dimension_id,
        share_percent: Number(row.share_percent),
      })),
    })
    splitOpen.value = false
    toast.success(t('payroll.people.dimensions.split_saved'))
    await load()
  } catch (error: unknown) {
    splitError.value = apiCode(error) === 'employment_dimension_interval_overlap'
      ? (apiMessage(error) || t('payroll.people.dimensions.overlap_error'))
      : (apiMessage(error) || t('payroll.people.dimensions.save_failed'))
  } finally {
    splitSaving.value = false
  }
}

function apiMessage(error: unknown): string {
  if (!isAxiosError<{ error?: { message?: string } }>(error)) return ''
  return error.response?.data?.error?.message ?? ''
}

function apiCode(error: unknown): string {
  if (!isAxiosError<{ error?: { code?: string } }>(error)) return ''
  return error.response?.data?.error?.code ?? ''
}

onMounted(load)
</script>

<template>
  <section class="mt-4 border-t border-neutral-200 pt-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.dimensions.title') }}</h4>
      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="dimensions-split-open" @click="openSplit">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.edit" />
          </svg>
          {{ t('payroll.people.dimensions.split') }}
        </button>
        <button type="button" :class="[btnOutlineSm('accent'), 'whitespace-nowrap']" data-test="dimensions-add" @click="openNew">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.people.dimensions.add') }}
        </button>
      </div>
    </div>
    <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.dimensions.hint') }}</p>

    <div v-if="loadError" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
      {{ loadError }}
    </div>

    <p
      v-if="noDimensionsAvailable"
      class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
      data-test="dimensions-none-available"
    >
      {{ t('payroll.people.dimensions.none_available') }}
      <RouterLink
        :to="{ name: 'payroll-settings', query: { tab: 'dimensions' } }"
        class="font-medium text-payroll-700 underline"
      >{{ t('payroll.people.dimensions.open_settings') }}</RouterLink>
    </p>

    <p v-if="!loading && assignments.length === 0" class="mt-2 text-xs text-neutral-500">
      {{ t('payroll.people.dimensions.empty') }}
    </p>

    <ul v-else class="mt-2 space-y-2">
      <li
        v-for="assignment in assignments"
        :key="assignment.id"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 px-3 py-2 text-xs"
      >
        <div>
          <p class="font-medium text-neutral-800">
            {{ t(`payroll.employer.dimensions.type_options.${assignment.dimension_type}`) }}
            · {{ assignment.dimension_code }} - {{ assignment.dimension_name }}
            <span
              v-if="(assignment.share_percent ?? 100) < 100"
              class="ml-1 inline-flex rounded-full bg-payroll-50 px-2 py-0.5 font-semibold text-payroll-700"
            >{{ t('payroll.people.dimensions.share_badge', { share: assignment.share_percent }) }}</span>
          </p>
          <p class="text-neutral-500">{{ assignment.valid_from }} – {{ assignment.valid_to ?? '∞' }}</p>
        </div>
        <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" data-test="dimensions-edit" @click="edit(assignment)">
          {{ t('common.edit') }}
        </button>
      </li>
    </ul>

    <form
      v-if="editorOpen"
      class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
      @submit.prevent="save"
    >
      <h5 class="text-xs font-semibold text-neutral-900">
        {{ t(editingId === null ? 'payroll.people.dimensions.new_title' : 'payroll.people.dimensions.edit_title') }}
      </h5>

      <div v-if="saveError" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
        <p>{{ saveError }}</p>
        <button v-if="conflict" type="button" :class="[btnOutlineSm('warning'), 'mt-2']" @click="reloadCurrent">
          {{ t('payroll.people.dimensions.reload_current') }}
        </button>
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="sm:col-span-1">
          <span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.people.dimensions.dimension') }}</span>
          <SearchableSelect
            :model-value="form.dimension_id || null"
            :options="dimensionOptions"
            :selected-option="selectedDimensionOption"
            :placeholder="t('payroll.people.dimensions.select_dimension')"
            :no-results-label="t('payroll.people.dimensions.no_results')"
            :clearable="false"
            :disabled="!canWrite"
            :invalid="showValidation && !form.dimension_id"
            accent="payroll"
            @update:model-value="form.dimension_id = Number($event ?? 0)"
          />
        </div>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.dimensions.valid_from') }}
          <DateInput v-model="form.valid_from" :disabled="!canWrite" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.dimensions.valid_to') }}
          <DateInput v-model="form.valid_to" :disabled="!canWrite" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
      </div>

      <!--
        Bez téhle věty kliknutí na Uložit u prázdné dimenze nebo obráceného
        intervalu neudělalo nic a nic neřeklo.
      -->
      <p
        v-if="showValidation && invalidReason"
        class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
        role="alert"
        data-test="dimensions-invalid-reason"
      >
        {{ invalidReason }}
      </p>

      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutlineSm('neutral')" @click="editorOpen = false">
          {{ t('common.cancel') }}
        </button>
        <button v-if="canWrite" type="submit" :class="btnFilled('primary')" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>

    <form
      v-if="splitOpen"
      class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
      data-test="dimensions-split-form"
      @submit.prevent="saveSplit"
    >
      <h5 class="text-xs font-semibold text-neutral-900">{{ t('payroll.people.dimensions.split_title') }}</h5>
      <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.people.dimensions.split_hint') }}</p>

      <div v-if="splitError" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
        {{ splitError }}
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
          <span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.employer.dimensions.type') }}</span>
          <SearchableSelect
            :model-value="splitType"
            :options="splitTypeOptions"
            :clearable="false"
            accent="payroll"
            @update:model-value="changeSplitType($event as PayrollDimensionType | null)"
          />
        </div>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.dimensions.valid_from') }}
          <DateInput v-model="splitValidFrom" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.dimensions.valid_to') }}
          <DateInput v-model="splitValidTo" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
      </div>

      <div class="mt-3 space-y-2">
        <div
          v-for="(row, index) in splitRows"
          :key="index"
          class="flex flex-wrap items-end gap-2"
        >
          <div class="min-w-[12rem] flex-1">
            <SearchableSelect
              :model-value="row.dimension_id || null"
              :options="splitDimensionOptions"
              :placeholder="t('payroll.people.dimensions.select_dimension')"
              :no-results-label="t('payroll.people.dimensions.no_results')"
              :clearable="false"
              :invalid="splitShowValidation && !row.dimension_id"
              :data-test="`dimensions-split-dimension-${index}`"
              accent="payroll"
              @update:model-value="row.dimension_id = Number($event ?? 0)"
            />
          </div>
          <label class="w-28 text-xs text-neutral-600">
            {{ t('payroll.people.dimensions.share_percent') }}
            <input
              v-model.number="row.share_percent"
              type="number"
              min="0.01"
              max="100"
              step="0.01"
              :data-test="`dimensions-split-share-${index}`"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            >
          </label>
          <button
            v-if="splitRows.length > 1"
            type="button"
            :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
            @click="removeSplitRow(index)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.trash" />
            </svg>
            {{ t('payroll.people.dimensions.split_remove') }}
          </button>
        </div>
      </div>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="addSplitRow">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.people.dimensions.split_add_row') }}
        </button>
        <span
          :class="['text-xs font-semibold', splitTotal === 100 ? 'text-success-700' : 'text-warning-700']"
          data-test="dimensions-split-total"
        >{{ t('payroll.people.dimensions.split_total', { total: splitTotal }) }}</span>
      </div>

      <p
        v-if="splitShowValidation && !splitValid"
        class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
        role="alert"
      >
        {{ t('payroll.people.dimensions.split_invalid') }}
      </p>

      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="splitOpen = false">
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="splitSaving">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ splitSaving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>
