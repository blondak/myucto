<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollPerson, type PayrollPersonListItem, type PayrollRelationType } from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const people = ref<PayrollPersonListItem[]>([])
const expandedId = ref<number | null>(null)
const details = ref<Record<number, PayrollPerson>>({})
const loadingDetailId = ref<number | null>(null)

function relationLabel(type: PayrollRelationType): string {
  return t(`payroll.people.relations.${type}`)
}

function statusLabel(isActive: boolean): string {
  return t(isActive ? 'payroll.people.status.active' : 'payroll.people.status.inactive')
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`))
}

async function load() {
  loading.value = true
  try {
    people.value = await payrollApi.people()
  } catch {
    toast.error(t('payroll.people.load_failed'))
  } finally {
    loading.value = false
  }
}

async function toggleDetail(person: PayrollPersonListItem) {
  if (expandedId.value === person.id) {
    expandedId.value = null
    return
  }

  expandedId.value = person.id
  if (details.value[person.id]) return

  loadingDetailId.value = person.id
  try {
    details.value[person.id] = await payrollApi.person(person.id)
  } catch {
    expandedId.value = null
    toast.error(t('payroll.people.detail_load_failed'))
  } finally {
    loadingDetailId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.people.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.people.subtitle') }}</p>
      </div>
    </header>

    <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.people.shared_recap_hint') }}
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 5" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div v-else-if="people.length === 0" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.empty_description') }}</p>
      </div>

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.people.columns.person') }}</th>
                <th class="px-4 py-3">{{ t('payroll.people.columns.status') }}</th>
                <th class="px-4 py-3">{{ t('payroll.people.columns.relations') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.people.columns.count') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ t('payroll.people.columns.detail') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="person in people" :key="person.id">
                <tr class="align-top">
                  <td class="px-4 py-3 font-medium text-neutral-900">{{ person.full_name }}</td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                      <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                      <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-neutral-600">{{ person.relation_types.map(relationLabel).join(', ') }}</td>
                  <td class="px-4 py-3 text-right text-neutral-700">{{ person.employment_count }}</td>
                  <td class="px-4 py-3 text-right">
                    <button :class="btnOutline('neutral')" :aria-expanded="expandedId === person.id" @click="toggleDetail(person)">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.user" />
                      </svg>
                      {{ t(expandedId === person.id ? 'payroll.people.hide_detail' : 'payroll.people.show_detail') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedId === person.id">
                  <td colspan="5" class="bg-neutral-50 px-4 py-4">
                    <div v-if="loadingDetailId === person.id" class="h-24 animate-pulse rounded-lg bg-neutral-100" />
                    <div v-else-if="details[person.id]" class="space-y-3">
                      <p class="text-xs text-neutral-500">{{ t('payroll.people.detail_hint') }}</p>
                      <div v-for="employment in details[person.id].employments" :key="employment.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                          <div>
                            <p class="font-medium text-neutral-900">{{ relationLabel(employment.relation_type) }}</p>
                            <p class="mt-0.5 text-xs text-neutral-500">{{ employment.code }}</p>
                          </div>
                          <span v-if="employment.is_legacy_projection" class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-600">{{ t('payroll.people.legacy_projection') }}</span>
                        </div>
                        <dl class="mt-3 grid grid-cols-1 gap-3 text-xs sm:grid-cols-3">
                          <div><dt class="text-neutral-500">{{ t('payroll.people.start_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.start_date) }}</dd></div>
                          <div><dt class="text-neutral-500">{{ t('payroll.people.end_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.end_date) }}</dd></div>
                          <div><dt class="text-neutral-500">{{ t('payroll.people.accounting') }}</dt><dd class="mt-0.5 text-neutral-800">{{ employment.accounting.gross_debit }}/{{ employment.accounting.gross_credit }} · {{ employment.accounting.employer_insurance_debit }}/{{ employment.accounting.employer_insurance_credit }}</dd></div>
                        </dl>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="space-y-3 p-4 md:hidden">
          <article v-for="person in people" :key="person.id" class="rounded-lg border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <h2 class="font-semibold text-neutral-900">{{ person.full_name }}</h2>
              <div class="flex flex-wrap gap-1.5">
                <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
              </div>
            </div>
            <dl class="mt-3 space-y-2 text-sm">
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.relations') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.relation_types.map(relationLabel).join(', ') }}</dd></div>
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.count') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.employment_count }}</dd></div>
            </dl>
            <button :class="[btnOutline('neutral'), 'mt-4']" :aria-expanded="expandedId === person.id" @click="toggleDetail(person)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.user" />
              </svg>
              {{ t(expandedId === person.id ? 'payroll.people.hide_detail' : 'payroll.people.show_detail') }}
            </button>
            <div v-if="expandedId === person.id" class="mt-4 border-t border-neutral-200 pt-4">
              <div v-if="loadingDetailId === person.id" class="h-24 animate-pulse rounded-lg bg-neutral-100" />
              <div v-else-if="details[person.id]" class="space-y-3">
                <p class="text-xs text-neutral-500">{{ t('payroll.people.detail_hint') }}</p>
                <div v-for="employment in details[person.id].employments" :key="employment.id" class="rounded-lg bg-neutral-50 p-3 text-sm">
                  <p class="font-medium text-neutral-900">{{ relationLabel(employment.relation_type) }}</p>
                  <p class="mt-0.5 text-xs text-neutral-500">{{ employment.code }}</p>
                  <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.people.accounting') }}</p>
                  <p class="text-xs text-neutral-800">{{ employment.accounting.gross_debit }}/{{ employment.accounting.gross_credit }} · {{ employment.accounting.employer_insurance_debit }}/{{ employment.accounting.employer_insurance_credit }}</p>
                </div>
              </div>
            </div>
          </article>
        </div>
      </template>
    </section>
  </div>
</template>
