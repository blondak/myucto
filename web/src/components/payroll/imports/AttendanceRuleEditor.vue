<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AttendanceMeaning, AttendanceUnit } from '@/api/payrollImports'
import { btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import {
  ATTENDANCE_MEANING_GROUPS,
  ATTENDANCE_UNITS,
  AUTO_COMPONENT_CODE,
  PROFILE_COMPONENT_KINDS,
  emptyComponentDraft,
  emptyRuleDraft,
  moveItem,
  ruleUnitForMeaning,
  type ProfileDraft,
  type ProfileDraftIssue,
  type RuleDraft,
} from './importHelpers'

const draft = defineModel<ProfileDraft>('draft', { required: true })

const props = withDefaults(defineProps<{
  readonly?: boolean
  issues?: ProfileDraftIssue[]
}>(), {
  readonly: false,
  issues: () => [],
})

const { t, te } = useI18n()
const codesListId = `attendance-component-codes-${useId()}`

function meaningLabel(meaning: string): string {
  const key = `payroll_imports.meanings.${meaning}`
  return te(key) ? t(key) : meaning
}

function hasIssue(row: number, kinds: ProfileDraftIssue['kind'][]): boolean {
  return props.issues.some(issue => 'row' in issue && issue.row === row && kinds.includes(issue.kind))
}

function setMeaning(rule: RuleDraft, value: string) {
  const meaning = value as AttendanceMeaning
  rule.meaning = meaning
  rule.unit = ruleUnitForMeaning(meaning, rule.unit)
}

function setUnit(rule: RuleDraft, value: string) {
  rule.unit = value === '' ? null : value as AttendanceUnit
}

function unitLocked(rule: RuleDraft): boolean {
  return rule.meaning === 'ignore'
}

function moveRule(index: number, delta: number) {
  draft.value.rules = moveItem(draft.value.rules, index, delta)
}

function removeRule(index: number) {
  draft.value.rules.splice(index, 1)
}

function addRule() {
  draft.value.rules.push(emptyRuleDraft())
}

function addComponent() {
  draft.value.components.push(emptyComponentDraft())
}

function removeComponent(index: number) {
  draft.value.components.splice(index, 1)
}

const INPUT = 'h-8 w-full rounded-md border bg-surface px-2 text-sm disabled:bg-neutral-100 disabled:text-neutral-500'
function inputClass(invalid: boolean): string {
  return `${INPUT} ${invalid ? 'border-danger-500' : 'border-neutral-300'}`
}
</script>

<template>
  <div class="space-y-4">
    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-testid="attendance-rule-editor">
      <header class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-100 px-4 py-3">
        <div class="min-w-0">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.mapping.rules.title') }}</h3>
          <p class="max-w-3xl text-xs text-neutral-500">{{ t('payroll_imports.mapping.rules.hint') }}</p>
        </div>
        <button v-if="!readonly" type="button" data-testid="attendance-rule-add" :class="btnOutlineSm('primary')" @click="addRule">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('payroll_imports.mapping.rules.add') }}
        </button>
      </header>

      <p v-if="draft.rules.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.mapping.rules.empty') }}</p>

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="w-10 px-3 py-2">#</th>
                <th class="min-w-32 px-2 py-2">{{ t('payroll_imports.mapping.rules.columns.sheet') }}</th>
                <th class="min-w-44 px-2 py-2">{{ t('payroll_imports.mapping.rules.columns.header') }}</th>
                <th class="min-w-52 px-2 py-2">{{ t('payroll_imports.mapping.rules.columns.meaning') }}</th>
                <th class="min-w-36 px-2 py-2">{{ t('payroll_imports.mapping.rules.columns.unit') }}</th>
                <th class="min-w-32 px-2 py-2">{{ t('payroll_imports.mapping.rules.columns.component') }}</th>
                <th v-if="!readonly" class="w-28 px-3 py-2"><span class="sr-only">{{ t('payroll_imports.mapping.rules.columns.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(rule, index) in draft.rules" :key="rule.uid" :class="rule.meaning === 'ignore' ? 'text-neutral-500' : ''">
                <td class="px-3 py-1.5 text-xs tabular-nums text-neutral-500">{{ index + 1 }}</td>
                <td class="px-2 py-1.5">
                  <input v-model="rule.sheet" :class="inputClass(false)" maxlength="120" :disabled="readonly"
                    :placeholder="t('payroll_imports.mapping.rules.sheet_placeholder')"
                    :aria-label="t('payroll_imports.mapping.rules.sheet_for', { row: index + 1 })">
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="rule.header" :class="inputClass(hasIssue(index + 1, ['rule_header_missing']))" maxlength="191" :disabled="readonly"
                    :placeholder="t('payroll_imports.mapping.rules.header_placeholder')"
                    :aria-label="t('payroll_imports.mapping.rules.header_for', { row: index + 1 })">
                </td>
                <td class="px-2 py-1.5">
                  <select :class="inputClass(false)" :value="rule.meaning" :disabled="readonly"
                    :aria-label="t('payroll_imports.mapping.rules.meaning_for', { row: index + 1 })"
                    @change="setMeaning(rule, ($event.target as HTMLSelectElement).value)">
                    <optgroup v-for="group in ATTENDANCE_MEANING_GROUPS" :key="group.key" :label="t(`payroll_imports.meaning_groups.${group.key}`)">
                      <option v-for="meaning in group.meanings" :key="meaning" :value="meaning">{{ meaningLabel(meaning) }}</option>
                    </optgroup>
                  </select>
                </td>
                <td class="px-2 py-1.5">
                  <select :class="inputClass(false)" :value="rule.unit ?? ''" :disabled="readonly || unitLocked(rule)"
                    :aria-label="t('payroll_imports.mapping.rules.unit_for', { row: index + 1 })"
                    @change="setUnit(rule, ($event.target as HTMLSelectElement).value)">
                    <option value="">{{ unitLocked(rule) ? '—' : t('payroll_imports.mapping.rules.unit_auto') }}</option>
                    <option v-for="unit in ATTENDANCE_UNITS" :key="unit" :value="unit">{{ t(`payroll_imports.units.${unit}`) }}</option>
                  </select>
                </td>
                <td class="px-2 py-1.5">
                  <input v-if="rule.meaning === 'component'" v-model="rule.component_code" :list="codesListId" maxlength="64" :disabled="readonly"
                    :class="`${inputClass(hasIssue(index + 1, ['rule_component_missing']))} font-mono uppercase`"
                    :placeholder="t('payroll_imports.mapping.rules.component_placeholder')"
                    :aria-label="t('payroll_imports.mapping.rules.component_for', { row: index + 1 })">
                  <span v-else class="text-xs text-neutral-300">—</span>
                  <p v-if="rule.meaning === 'component' && rule.component_code.trim() === AUTO_COMPONENT_CODE" class="mt-0.5 text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.auto_code') }}</p>
                </td>
                <td v-if="!readonly" class="px-3 py-1.5">
                  <div class="flex items-center gap-1">
                    <button type="button" :class="btnIconSm('neutral')" :disabled="index === 0"
                      :title="t('payroll_imports.mapping.rules.move_up')" :aria-label="t('payroll_imports.mapping.rules.move_up')" @click="moveRule(index, -1)">
                      <svg class="h-3.5 w-3.5 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
                    </button>
                    <button type="button" :class="btnIconSm('neutral')" :disabled="index === draft.rules.length - 1"
                      :title="t('payroll_imports.mapping.rules.move_down')" :aria-label="t('payroll_imports.mapping.rules.move_down')" @click="moveRule(index, 1)">
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
                    </button>
                    <button type="button" :class="btnIconSm('danger')"
                      :title="t('payroll_imports.mapping.rules.remove')" :aria-label="t('payroll_imports.mapping.rules.remove')" @click="removeRule(index)">
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <ol class="space-y-2 p-3 md:hidden">
          <li v-for="(rule, index) in draft.rules" :key="rule.uid" class="rounded-lg bg-neutral-50 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs font-medium text-neutral-500">{{ t('payroll_imports.mapping.rules.row', { row: index + 1 }) }}</span>
              <div v-if="!readonly" class="flex items-center gap-1">
                <button type="button" :class="btnIconSm('neutral')" :disabled="index === 0"
                  :title="t('payroll_imports.mapping.rules.move_up')" :aria-label="t('payroll_imports.mapping.rules.move_up')" @click="moveRule(index, -1)">
                  <svg class="h-3.5 w-3.5 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
                </button>
                <button type="button" :class="btnIconSm('neutral')" :disabled="index === draft.rules.length - 1"
                  :title="t('payroll_imports.mapping.rules.move_down')" :aria-label="t('payroll_imports.mapping.rules.move_down')" @click="moveRule(index, 1)">
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
                </button>
                <button type="button" :class="btnIconSm('danger')"
                  :title="t('payroll_imports.mapping.rules.remove')" :aria-label="t('payroll_imports.mapping.rules.remove')" @click="removeRule(index)">
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                </button>
              </div>
            </div>
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label class="block">
                <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.columns.sheet') }}</span>
                <input v-model="rule.sheet" :class="inputClass(false)" maxlength="120" :disabled="readonly" :placeholder="t('payroll_imports.mapping.rules.sheet_placeholder')">
              </label>
              <label class="block">
                <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.columns.header') }}</span>
                <input v-model="rule.header" :class="inputClass(hasIssue(index + 1, ['rule_header_missing']))" maxlength="191" :disabled="readonly" :placeholder="t('payroll_imports.mapping.rules.header_placeholder')">
              </label>
              <label class="block">
                <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.columns.meaning') }}</span>
                <select :class="inputClass(false)" :value="rule.meaning" :disabled="readonly" @change="setMeaning(rule, ($event.target as HTMLSelectElement).value)">
                  <optgroup v-for="group in ATTENDANCE_MEANING_GROUPS" :key="group.key" :label="t(`payroll_imports.meaning_groups.${group.key}`)">
                    <option v-for="meaning in group.meanings" :key="meaning" :value="meaning">{{ meaningLabel(meaning) }}</option>
                  </optgroup>
                </select>
              </label>
              <label class="block">
                <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.columns.unit') }}</span>
                <select :class="inputClass(false)" :value="rule.unit ?? ''" :disabled="readonly || unitLocked(rule)" @change="setUnit(rule, ($event.target as HTMLSelectElement).value)">
                  <option value="">{{ unitLocked(rule) ? '—' : t('payroll_imports.mapping.rules.unit_auto') }}</option>
                  <option v-for="unit in ATTENDANCE_UNITS" :key="unit" :value="unit">{{ t(`payroll_imports.units.${unit}`) }}</option>
                </select>
              </label>
              <label v-if="rule.meaning === 'component'" class="block sm:col-span-2">
                <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.rules.columns.component') }}</span>
                <input v-model="rule.component_code" :list="codesListId" maxlength="64" :disabled="readonly"
                  :class="`${inputClass(hasIssue(index + 1, ['rule_component_missing']))} font-mono uppercase`"
                  :placeholder="t('payroll_imports.mapping.rules.component_placeholder')">
              </label>
            </div>
          </li>
        </ol>
      </template>

      <datalist :id="codesListId">
        <option :value="AUTO_COMPONENT_CODE">{{ t('payroll_imports.mapping.rules.auto_code') }}</option>
        <option v-for="component in draft.components" :key="component.uid" :value="component.code.trim().toUpperCase()">{{ component.name }}</option>
      </datalist>
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-testid="attendance-profile-components">
      <header class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-100 px-4 py-3">
        <div class="min-w-0">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.mapping.components.title') }}</h3>
          <p class="max-w-3xl text-xs text-neutral-500">{{ t('payroll_imports.mapping.components.hint') }}</p>
        </div>
        <button v-if="!readonly" type="button" :class="btnOutlineSm('primary')" @click="addComponent">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('payroll_imports.mapping.components.add') }}
        </button>
      </header>

      <p v-if="draft.components.length === 0" class="px-4 py-5 text-sm text-neutral-500">{{ t('payroll_imports.mapping.components.empty') }}</p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="(component, index) in draft.components" :key="component.uid" class="flex flex-wrap items-end gap-2 px-4 py-2">
          <label class="block w-full sm:w-40">
            <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.components.code') }}</span>
            <input v-model="component.code" maxlength="64" :disabled="readonly"
              :class="`${inputClass(hasIssue(index + 1, ['component_code_missing', 'component_code_duplicate', 'component_code_auto']))} font-mono uppercase`"
              :placeholder="t('payroll_imports.mapping.components.code_placeholder')">
          </label>
          <label class="block min-w-48 flex-1">
            <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.components.name') }}</span>
            <input v-model="component.name" maxlength="120" :disabled="readonly" :class="inputClass(hasIssue(index + 1, ['component_name_missing']))">
          </label>
          <label class="block w-full sm:w-48">
            <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.mapping.components.kind') }}</span>
            <select v-model="component.kind" :disabled="readonly" :class="inputClass(false)">
              <option v-for="kind in PROFILE_COMPONENT_KINDS" :key="kind" :value="kind">{{ t(`payroll_imports.component_kinds.${kind}`) }}</option>
            </select>
          </label>
          <button v-if="!readonly" type="button" :class="btnIconSm('danger')"
            :title="t('payroll_imports.mapping.components.remove')" :aria-label="t('payroll_imports.mapping.components.remove')" @click="removeComponent(index)">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
