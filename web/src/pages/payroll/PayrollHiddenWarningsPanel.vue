<script setup lang="ts">
/*
 * Přehled trvale skrytých mzdových varování s možností obnovit. Skrytí se
 * řadí po typu varování: u firmy s padesáti osobami bez prohlášení je to
 * jedna skupina s padesáti jmény, ne padesát samostatných řádků bez souvislosti.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollWarningSuppression } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { btnOutlineSm, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import ExpandableList from '@/components/ui/ExpandableList.vue'

const props = defineProps<{ canWrite: boolean }>()

const { t, te } = useI18n()
const toast = useToast()
const items = ref<PayrollWarningSuppression[]>([])
const loading = ref(false)
const loadFailed = ref(false)
const saving = ref(false)

const groups = computed(() => {
  const byCode = new Map<string, PayrollWarningSuppression[]>()
  for (const item of items.value) {
    const list = byCode.get(item.code) ?? []
    list.push(item)
    byCode.set(item.code, list)
  }
  return Array.from(byCode, ([code, rows]) => ({ code, rows }))
})

function codeLabel(code: string): string {
  const key = `payroll.warning_suppressions.codes.${code}`
  return te(key) ? t(key) : code
}

function subjectLabel(item: PayrollWarningSuppression): string {
  if (item.subject_type === 'supplier') return t('payroll.warning_suppressions.scope_company')
  return item.subject_label ?? t('payroll.warning_suppressions.subject_unknown', { id: item.subject_id })
}

function itemKey(item: PayrollWarningSuppression): number {
  return item.id
}

function itemSearchText(item: PayrollWarningSuppression): string {
  return [subjectLabel(item), item.reason ?? '', item.created_by_name ?? ''].join(' ')
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    items.value = (await payrollApi.listWarningSuppressions()).items
  } catch (error) {
    loadFailed.value = true
    toast.error(apiErrorMessage(error, t('payroll.warning_suppressions.load_failed')))
  } finally {
    loading.value = false
  }
}

async function restore(ids: number[]): Promise<void> {
  if (saving.value || ids.length === 0) return
  saving.value = true
  try {
    const result = await payrollApi.restoreWarnings(ids)
    toast.success(t('payroll.warning_suppressions.restored', { count: result.restored }))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.warning_suppressions.restore_failed')))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="hidden-warnings-panel">
    <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.warning_suppressions.title') }}</h2>
    <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.warning_suppressions.intro') }}</p>
    <p v-if="!props.canWrite" :class="[BTN_DISABLED_NOTE, 'mt-2']" data-test="hidden-warnings-no-permission">
      {{ t('payroll.warning_suppressions.no_permission') }}
    </p>

    <p v-if="loading" class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <EmptyState
      v-else-if="!loadFailed && items.length === 0"
      class="mt-4"
      :title="t('payroll.warning_suppressions.empty')"
      data-test="hidden-warnings-empty"
    />
    <div v-else class="mt-4 space-y-4">
      <div
        v-for="group in groups"
        :key="group.code"
        class="rounded-lg border border-neutral-200 p-3"
        :data-test="`hidden-warnings-group-${group.code}`"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-sm font-medium text-neutral-800">
            {{ codeLabel(group.code) }}
            <span class="text-xs font-normal text-neutral-500">({{ group.rows.length }})</span>
          </p>
          <button
            v-if="props.canWrite && group.rows.length > 1"
            type="button"
            :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
            :disabled="saving"
            :data-test="`hidden-warnings-restore-all-${group.code}`"
            @click="restore(group.rows.map(row => row.id))"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
            {{ t('payroll.warning_suppressions.restore_all', { count: group.rows.length }) }}
          </button>
        </div>
        <ExpandableList
          :items="group.rows"
          :item-key="itemKey"
          :search-text="itemSearchText"
          list-class="mt-2 space-y-1"
          :test-id="`hidden-warnings-${group.code}`"
        >
          <template #item="{ item }">
            <div
              class="flex flex-wrap items-center gap-2 rounded-md bg-neutral-50 px-2.5 py-1.5"
              :data-test="`hidden-warning-${item.id}`"
            >
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-neutral-800">{{ subjectLabel(item) }}</p>
                <p class="text-xs text-neutral-500">
                  {{ t('payroll.warning_suppressions.hidden_by', {
                    name: item.created_by_name ?? '—',
                    date: formatDateTime(item.created_at),
                  }) }}
                  <span v-if="item.reason"> · {{ item.reason }}</span>
                </p>
              </div>
              <button
                v-if="props.canWrite"
                type="button"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :disabled="saving"
                :data-test="`hidden-warning-restore-${item.id}`"
                @click="restore([item.id])"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
                {{ t('payroll.warning_suppressions.restore') }}
              </button>
            </div>
          </template>
        </ExpandableList>
      </div>
    </div>
  </section>
</template>
