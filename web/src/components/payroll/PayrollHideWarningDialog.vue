<script setup lang="ts">
/*
 * Trvalé skrytí mzdového varování. Dva rozsahy:
 * - `people` — jen u vybraných osob (pracovních vztahů); výchozí jsou vybraní
 *   všichni dotčení, takže „Skrýt pro všech N osob" je jedno kliknutí,
 * - `type` — celý typ varování ve firmě.
 * Skrytí platí pro všechny další běhy, obnovit ho jde v Nastavení
 * zaměstnavatele → Skrytá varování.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  code: string
  message: string
  scope: 'people' | 'type'
  subjects: Array<{ id: number, label: string }>
}>()

const emit = defineEmits<{
  close: []
  hidden: [created: number]
}>()

const { t } = useI18n()
const selected = ref<Set<number>>(new Set(props.subjects.map(subject => subject.id)))
const reason = ref('')
const saving = ref(false)
const error = ref('')
const filter = ref('')

const filteredSubjects = computed(() => {
  const needle = filter.value.trim().toLocaleLowerCase('cs')
  return needle === ''
    ? props.subjects
    : props.subjects.filter(subject => subject.label.toLocaleLowerCase('cs').includes(needle))
})
const allSelected = computed(() => selected.value.size === props.subjects.length)

function toggle(id: number): void {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function toggleAll(): void {
  selected.value = allSelected.value
    ? new Set()
    : new Set(props.subjects.map(subject => subject.id))
}

const canSubmit = computed(() => props.scope === 'type' || selected.value.size > 0)

async function submit(): Promise<void> {
  if (!canSubmit.value || saving.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await payrollApi.hideWarning({
      code: props.code,
      ...(props.scope === 'people' ? { subject_ids: [...selected.value] } : {}),
      reason: reason.value.trim() === '' ? null : reason.value.trim(),
    })
    emit('hidden', result.created)
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.warning_suppressions.hide_failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal
    :title="scope === 'type'
      ? t('payroll.warning_suppressions.hide_type')
      : t('payroll.warning_suppressions.hide_people', { count: subjects.length })"
    width-class="max-w-xl"
    @close="emit('close')"
  >
    <form class="space-y-4" data-test="hide-warning-dialog" @submit.prevent="submit">
      <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
        {{ message }}
      </p>
      <p class="text-xs leading-snug text-neutral-600" data-test="hide-warning-scope">
        {{ scope === 'type'
          ? t('payroll.warning_suppressions.type_scope_hint')
          : t('payroll.warning_suppressions.people_scope_hint') }}
      </p>

      <div v-if="scope === 'people'" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <input
            v-if="subjects.length > 8"
            v-model="filter"
            type="search"
            class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm"
            :placeholder="t('payroll.warning_suppressions.filter_people')"
            data-test="hide-warning-filter"
          >
          <button
            type="button"
            :class="[btnOutline('neutral'), 'whitespace-nowrap']"
            data-test="hide-warning-toggle-all"
            @click="toggleAll"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ allSelected ? t('payroll.warning_suppressions.select_none') : t('payroll.warning_suppressions.select_all') }}
          </button>
        </div>
        <ul class="max-h-64 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2" data-test="hide-warning-people">
          <li v-for="subject in filteredSubjects" :key="subject.id">
            <label class="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                :checked="selected.has(subject.id)"
                :data-test="`hide-warning-person-${subject.id}`"
                @change="toggle(subject.id)"
              >
              <span>{{ subject.label }}</span>
            </label>
          </li>
        </ul>
        <p class="text-xs text-neutral-500" data-test="hide-warning-selected">
          {{ t('payroll.warning_suppressions.selected', { count: selected.size, total: subjects.length }) }}
        </p>
      </div>

      <label class="block text-sm font-medium text-neutral-700">
        {{ t('payroll.warning_suppressions.reason_prompt') }}
        <textarea
          v-model="reason"
          maxlength="500"
          class="mt-1 min-h-20 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          data-test="hide-warning-reason"
        />
      </label>
      <p
        v-if="error"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-test="hide-warning-error"
      >
        {{ error }}
      </p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="emit('close')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="submit"
          :class="[btnFilled('warning'), 'whitespace-nowrap']"
          :disabled="saving || !canSubmit"
          data-test="confirm-hide-warning"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
          {{ scope === 'type'
            ? t('payroll.warning_suppressions.hide_type')
            : t('payroll.warning_suppressions.hide_selected', { count: selected.size }) }}
        </button>
      </div>
    </form>
  </Modal>
</template>
