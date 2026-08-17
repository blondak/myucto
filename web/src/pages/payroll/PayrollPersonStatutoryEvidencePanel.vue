<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollStatutoryEvidence,
  type PayrollStatutoryEvidenceRow,
  type PayrollStatutoryEvidenceSection,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'

/**
 * Zákonná evidence osoby.
 *
 * Není to „další formulář": bez prohlášení k dani, daňové rezidence,
 * příslušnosti k sociálnímu a zdravotnímu pojištění a evidence slevy
 * pracujícího důchodce shodí mzdový běh celý zákonný výpočet do ručního
 * posouzení. Panel proto neukazuje obecnou nápovědu, ale konkrétní seznam
 * toho, co k danému měsíci chybí — a pojmenovává to stejnými důvody, jaké
 * hlásí výpočet.
 *
 * Hodnoty jako `czech_regime_verified` nebo `insurer_status = verified` jsou
 * PRÁVNÍ SKUTEČNOSTI, ne přepínače. Ke každé se proto zadává kanonická
 * reference na doklad; lidské vysvětlení má vlastní pole. Kdo doklad nemá,
 * zvolí variantu „neověřeno" — ta jde uložit taky, jen zůstane vidět jako
 * blokátor.
 */
const props = defineProps<{
  personId: number
  canWrite: boolean
}>()

type FieldKind = 'enum' | 'reference' | 'country' | 'date' | 'text'

interface FieldSpec {
  key: string
  kind: FieldKind
  options?: readonly string[]
}

interface SectionSpec {
  key: PayrollStatutoryEvidenceSection
  kind: 'interval' | 'month'
  fields: readonly FieldSpec[]
}

/**
 * Popis sekcí zrcadlí `EDITABLE` v `PayrollPersonStatutoryEvidenceRepository`.
 * Výčty se schválně neopisují do vlastních typů — jsou to zákonné hodnoty,
 * jejichž jediným pánem je server; tady slouží jen k vykreslení nabídky.
 */
const SECTIONS: readonly SectionSpec[] = [
  {
    key: 'tax_declarations',
    kind: 'interval',
    fields: [
      { key: 'status', kind: 'enum', options: ['signed', 'not-signed', 'unverified'] },
      { key: 'evidence_reference', kind: 'reference' },
    ],
  },
  {
    key: 'tax_residences',
    kind: 'interval',
    fields: [
      { key: 'residence', kind: 'enum', options: ['czech-resident', 'non-resident', 'unverified'] },
      { key: 'country_code', kind: 'country' },
      { key: 'evidence_reference', kind: 'reference' },
    ],
  },
  {
    key: 'social_jurisdictions',
    kind: 'interval',
    fields: [
      {
        key: 'jurisdiction',
        kind: 'enum',
        options: ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
      },
      { key: 'foreign_country_code', kind: 'country' },
      { key: 'jurisdiction_evidence_reference', kind: 'reference' },
      { key: 'a1_status', kind: 'enum', options: ['verified', 'unverified', 'not_applicable'] },
      { key: 'a1_certificate_reference', kind: 'reference' },
      { key: 'a1_valid_until', kind: 'date' },
    ],
  },
  {
    key: 'social_discount_claims',
    kind: 'interval',
    fields: [
      { key: 'status', kind: 'enum', options: ['not_claimed', 'verified', 'unverified'] },
      { key: 'evidence_reference', kind: 'reference' },
    ],
  },
  {
    key: 'health_coverages',
    kind: 'interval',
    fields: [
      {
        key: 'jurisdiction',
        kind: 'enum',
        options: ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
      },
      { key: 'foreign_country_code', kind: 'country' },
      { key: 'jurisdiction_evidence_reference', kind: 'reference' },
      { key: 'insurer_status', kind: 'enum', options: ['verified', 'unverified', 'not_applicable'] },
      { key: 'insurer_code', kind: 'text' },
      { key: 'insurer_evidence_reference', kind: 'reference' },
    ],
  },
  {
    key: 'health_month_evidence',
    kind: 'month',
    fields: [
      {
        key: 'top_up_responsibility',
        kind: 'enum',
        options: ['employee', 'employer_obstacle_verified', 'unverified'],
      },
      { key: 'top_up_responsibility_evidence_reference', kind: 'reference' },
      { key: 'selected_top_up_employer_reference', kind: 'reference' },
      { key: 'selected_top_up_employer_evidence_reference', kind: 'reference' },
    ],
  },
] as const

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const editing = ref(false)
const loadError = ref('')
const saveError = ref('')
const evidence = ref<PayrollStatutoryEvidence | null>(null)
const drafts = ref<Record<string, PayrollStatutoryEvidenceRow[]>>({})

/** Evidence se vyhodnocuje k měsíci; výchozí je ten, ve kterém uživatel je. */
const effectiveOn = ref(monthEnd(new Date()))

function monthEnd(date: Date): string {
  const end = new Date(date.getFullYear(), date.getMonth() + 1, 0)
  const month = String(end.getMonth() + 1).padStart(2, '0')
  return `${end.getFullYear()}-${month}-${String(end.getDate()).padStart(2, '0')}`
}

function monthStart(iso: string): string {
  return `${iso.slice(0, 7)}-01`
}

const blockers = computed(() => evidence.value?.blockers ?? [])
const frozenThrough = computed(() => evidence.value?.frozen_through ?? null)

/** Reference na základy u jiného zaměstnavatele — volba plátce doplatku minima. */
const employerReferences = computed(
  () => (evidence.value?.other_employer_bases ?? [])
    .map(row => String(row.employer_reference ?? ''))
    .filter(value => value !== ''),
)

function isFrozen(section: SectionSpec, row: PayrollStatutoryEvidenceRow): boolean {
  const start = section.kind === 'month' ? row.period_start : row.effective_from
  return typeof start === 'string'
    && frozenThrough.value !== null
    && start <= frozenThrough.value
}

function emptyRow(section: SectionSpec): PayrollStatutoryEvidenceRow {
  const row: PayrollStatutoryEvidenceRow = {}
  for (const field of section.fields) {
    row[field.key] = field.kind === 'enum' ? (field.options?.[0] ?? null) : null
  }
  row.evidence_note = null
  if (section.kind === 'month') {
    row.period_start = monthStart(effectiveOn.value)
  } else {
    row.effective_from = monthStart(effectiveOn.value)
    row.effective_to = null
  }
  return row
}

function hydrate(value: PayrollStatutoryEvidence) {
  evidence.value = value
  const next: Record<string, PayrollStatutoryEvidenceRow[]> = {}
  for (const section of SECTIONS) {
    next[section.key] = (value.sections[section.key] ?? []).map(row => ({ ...row }))
  }
  drafts.value = next
}

function addRow(section: SectionSpec) {
  drafts.value[section.key] = [...(drafts.value[section.key] ?? []), emptyRow(section)]
}

function removeRow(section: SectionSpec, index: number) {
  const rows = [...(drafts.value[section.key] ?? [])]
  rows.splice(index, 1)
  drafts.value[section.key] = rows
}

async function load() {
  loading.value = true
  loadError.value = ''
  saveError.value = ''
  try {
    hydrate(await payrollApi.statutoryEvidence(props.personId, effectiveOn.value))
  } catch (exception) {
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

function cancel() {
  editing.value = false
  saveError.value = ''
  if (evidence.value) hydrate(evidence.value)
}

async function save() {
  if (saving.value) return
  saveError.value = ''
  saving.value = true
  try {
    const sections = {} as Record<PayrollStatutoryEvidenceSection, PayrollStatutoryEvidenceRow[]>
    for (const section of SECTIONS) {
      sections[section.key] = (drafts.value[section.key] ?? []).map(row => ({ ...row }))
    }
    hydrate(await payrollApi.saveStatutoryEvidence(props.personId, {
      effective_on: effectiveOn.value,
      sections,
    }))
    editing.value = false
    toast.success(t('payroll.people.statutory_evidence.saved'))
  } catch (exception) {
    // Server jmenuje konkrétní důvod (překryv, díra v řadě, chybějící doklad,
    // uzavřené období) — obecná hláška by ho jen zakryla.
    saveError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.save_failed'),
    )
  } finally {
    saving.value = false
  }
}

watch(() => props.personId, () => { editing.value = false; void load() })
watch(effectiveOn, () => { editing.value = false; void load() })
onMounted(load)
</script>

<template>
  <details
    class="group rounded-lg border border-payroll-500/30 bg-surface"
    data-test="statutory-evidence"
    :open="blockers.length > 0"
  >
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.statutory_evidence.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.statutory_evidence.subtitle') }}
        </span>
      </span>
      <span
        v-if="blockers.length > 0"
        class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800"
        data-test="statutory-evidence-badge"
      >{{ t('payroll.people.statutory_evidence.blocked_badge', { count: blockers.length }, blockers.length) }}</span>
    </summary>

    <div class="border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

      <p
        v-else-if="loadError"
        class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
        role="alert"
        data-test="statutory-evidence-load-error"
      >{{ loadError }}</p>

      <template v-else>
        <label class="mb-3 block text-xs text-neutral-600">
          {{ t('payroll.people.statutory_evidence.effective_on') }}
          <input
            v-model="effectiveOn"
            type="date"
            class="mt-1 block rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm"
            data-test="statutory-evidence-effective-on"
          >
          <span class="mt-1 block text-neutral-500">
            {{ t('payroll.people.statutory_evidence.effective_on_hint') }}
          </span>
        </label>

        <div
          v-if="blockers.length > 0"
          class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 p-2 text-xs text-warning-800"
          data-test="statutory-evidence-blockers"
        >
          <p class="font-medium">{{ t('payroll.people.statutory_evidence.blockers_title') }}</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-4">
            <li v-for="blocker in blockers" :key="blocker">
              {{ t(`payroll.people.statutory_evidence.blocker.${blocker}`) }}
            </li>
          </ul>
          <p class="mt-1">{{ t('payroll.people.statutory_evidence.blockers_consequence') }}</p>
        </div>
        <p
          v-else
          class="mb-3 rounded-md bg-success-50 px-3 py-2 text-xs text-success-800"
          data-test="statutory-evidence-complete"
        >{{ t('payroll.people.statutory_evidence.complete') }}</p>

        <p
          v-if="frozenThrough"
          class="mb-3 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
          data-test="statutory-evidence-frozen"
        >{{ t('payroll.people.statutory_evidence.frozen_hint', { day: frozenThrough }) }}</p>

        <div class="space-y-4">
          <section v-for="section in SECTIONS" :key="section.key" :data-test="`section-${section.key}`">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
              {{ t(`payroll.people.statutory_evidence.section.${section.key}`) }}
            </h4>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t(`payroll.people.statutory_evidence.section_hint.${section.key}`) }}
            </p>

            <p
              v-if="(drafts[section.key] ?? []).length === 0"
              class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
            >{{ t('payroll.people.statutory_evidence.empty') }}</p>

            <div
              v-for="(row, index) in drafts[section.key] ?? []"
              :key="`${section.key}-${row.id ?? `new-${index}`}`"
              class="mt-2 rounded-md border border-neutral-200 p-2"
              :data-test="`row-${section.key}-${index}`"
            >
              <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <label v-if="section.kind === 'month'" class="block text-xs text-neutral-600">
                  {{ t('payroll.people.statutory_evidence.period_start') }}
                  <input
                    v-model="row.period_start"
                    type="date"
                    :disabled="!editing || saving || isFrozen(section, row)"
                    :data-test="`${section.key}-${index}-period_start`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                </label>
                <template v-else>
                  <label class="block text-xs text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.effective_from') }}
                    <input
                      v-model="row.effective_from"
                      type="date"
                      :disabled="!editing || saving || isFrozen(section, row)"
                      :data-test="`${section.key}-${index}-effective_from`"
                      class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    >
                  </label>
                  <label class="block text-xs text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.effective_to') }}
                    <input
                      v-model="row.effective_to"
                      type="date"
                      :disabled="!editing || saving"
                      :data-test="`${section.key}-${index}-effective_to`"
                      class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    >
                  </label>
                </template>

                <label
                  v-for="field in section.fields"
                  :key="field.key"
                  class="block text-xs text-neutral-600"
                >
                  {{ t(`payroll.people.statutory_evidence.field.${field.key}`) }}
                  <select
                    v-if="field.kind === 'enum'"
                    v-model="row[field.key]"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                    <option v-for="option in field.options" :key="option" :value="option">
                      {{ t(`payroll.people.statutory_evidence.option.${field.key}.${option}`) }}
                    </option>
                  </select>
                  <input
                    v-else-if="field.kind === 'date'"
                    v-model="row[field.key]"
                    type="date"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                  <input
                    v-else
                    v-model="row[field.key]"
                    type="text"
                    :list="field.key === 'selected_top_up_employer_reference'
                      ? 'statutory-evidence-employers'
                      : undefined"
                    :disabled="!editing || saving"
                    :placeholder="field.kind === 'reference'
                      ? t('payroll.people.statutory_evidence.reference_placeholder')
                      : ''"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                  <span
                    v-if="field.kind === 'reference'"
                    class="mt-0.5 block text-neutral-400"
                  >{{ t('payroll.people.statutory_evidence.reference_hint') }}</span>
                </label>

                <label class="block text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">
                  {{ t('payroll.people.statutory_evidence.evidence_note') }}
                  <input
                    v-model="row.evidence_note"
                    type="text"
                    :disabled="!editing || saving"
                    :placeholder="t('payroll.people.statutory_evidence.evidence_note_placeholder')"
                    :data-test="`${section.key}-${index}-evidence_note`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                </label>
              </div>

              <div class="mt-2 flex items-center justify-between">
                <span v-if="isFrozen(section, row)" class="text-xs text-neutral-500">
                  {{ t('payroll.people.statutory_evidence.row_frozen') }}
                </span>
                <span v-else />
                <button
                  v-if="editing && !isFrozen(section, row)"
                  type="button"
                  :class="btnOutline('danger')"
                  :disabled="saving"
                  :data-test="`remove-${section.key}-${index}`"
                  @click="removeRow(section, index)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                  {{ t('payroll.people.statutory_evidence.remove_row') }}
                </button>
              </div>
            </div>

            <button
              v-if="editing"
              type="button"
              :class="`mt-2 ${btnOutline('neutral')}`"
              :disabled="saving"
              :data-test="`add-${section.key}`"
              @click="addRow(section)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.statutory_evidence.add_row') }}
            </button>
          </section>
        </div>

        <datalist id="statutory-evidence-employers">
          <option v-for="reference in employerReferences" :key="reference" :value="reference" />
        </datalist>

        <p
          v-if="saveError"
          class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
          role="alert"
          data-test="statutory-evidence-error"
        >{{ saveError }}</p>

        <div v-if="canWrite" class="mt-3 flex justify-end gap-2">
          <button
            v-if="!editing"
            type="button"
            :class="btnOutline('primary')"
            data-test="start-statutory-evidence"
            @click="editing = true"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
            {{ t('payroll.people.statutory_evidence.edit') }}
          </button>
          <template v-else>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="saving"
              @click="cancel"
            >{{ t('common.cancel') }}</button>
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="saving"
              data-test="statutory-evidence-save"
              @click="save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </template>
        </div>
      </template>
    </div>
  </details>
</template>
