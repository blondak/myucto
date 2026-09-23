<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemPlansApi, type OtherItemInstallment, type OtherItemSchedule } from '@/api/otherItemPlans'
import type { OtherItem } from '@/api/otherItems'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'

const props = defineProps<{ item: OtherItem }>()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const busy = ref(false)
const loading = ref(false)
const schedule = ref<OtherItemSchedule | null>(null)
const installments = ref<OtherItemInstallment[]>([])
const frequency = ref<OtherItemSchedule['frequency']>('monthly')
const endsOn = ref('')
const through = ref(appIsoDate())
const canWrite = computed(() => auth.canWrite('other_items'))
const canEditInstallments = computed(() => canWrite.value && ['draft', 'posted', 'confirmed'].includes(props.item.status)
  && Number(props.item.paid_amount) === 0)

async function load() {
  loading.value = true
  try {
    const id = Number(props.item.id)
    const [all, rows] = await Promise.all([otherItemPlansApi.schedules(), otherItemPlansApi.installments(id)])
    const found = all.find(row => Number(row.source_item_id) === id)
    schedule.value = found ? await otherItemPlansApi.schedule(found.id) : null
    installments.value = rows
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function createSchedule() {
  if (busy.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.createSchedule(Number(props.item.id), {
      frequency: frequency.value, ends_on: endsOn.value || null,
    })
    toast.success(t('other_items.plans.schedule_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function generate() {
  if (!schedule.value || busy.value) return
  busy.value = true
  try {
    const result = await otherItemPlansApi.generate(schedule.value.id, through.value)
    schedule.value = result.schedule
    toast.success(t('other_items.plans.generated', { count: result.created_ids.length }))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function toggleSchedule() {
  if (!schedule.value || busy.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.setStatus(schedule.value.id,
      schedule.value.status === 'active' ? 'paused' : 'active')
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

function addInstallment() {
  installments.value = [...installments.value, { due_on: props.item.due_on || appIsoDate(), amount: 0 }]
}

async function saveInstallments() {
  if (busy.value) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.setInstallments(Number(props.item.id),
      installments.value.map(row => ({ due_on: row.due_on, amount: Number(row.amount) })))
    toast.success(t('other_items.plans.installments_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function clearInstallments() {
  if (busy.value || !window.confirm(t('other_items.plans.confirm_clear'))) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.setInstallments(Number(props.item.id), [])
    toast.success(t('other_items.plans.installments_cleared'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

watch(() => props.item.id, () => void load(), { immediate: true })
</script>

<template>
  <div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="font-semibold">{{ t('other_items.plans.schedule_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('other_items.plans.schedule_hint') }}</p>
      <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <template v-else-if="schedule">
        <p class="mt-3 text-sm">{{ t(`other_items.plans.${schedule.frequency}`) }} · {{ t(`other_items.plans.${schedule.status}`) }}</p>
        <div v-if="canWrite" class="mt-3 flex flex-wrap items-end gap-2">
          <label class="text-sm font-medium">{{ t('other_items.plans.generate_through') }}
            <DateInput v-model="through" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
          </label>
          <button type="button" :disabled="busy || schedule.status !== 'active'" :class="btnOutline('success')" @click="generate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('other_items.plans.generate') }}
          </button>
          <button type="button" :disabled="busy" :class="btnOutline('warning')" @click="toggleSchedule">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="schedule.status === 'active' ? ICONS.pause : ICONS.play" /></svg>
            {{ t(schedule.status === 'active' ? 'other_items.plans.pause' : 'other_items.plans.resume') }}
          </button>
        </div>
        <ul v-if="schedule.occurrences?.length" class="mt-3 space-y-1 text-sm">
          <li v-for="occurrence in schedule.occurrences" :key="occurrence.occurrence_index">
            <RouterLink :to="`/other-items/${occurrence.item_id}`" class="text-primary-700 hover:underline">
              {{ t('other_items.plans.occurrence', { number: occurrence.occurrence_index + 1 }) }} #{{ occurrence.item_id }}
            </RouterLink>
          </li>
        </ul>
      </template>
      <form v-else-if="canWrite && ['draft', 'posted', 'confirmed'].includes(item.status)" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="createSchedule">
        <label class="text-sm font-medium">{{ t('other_items.plans.frequency') }}
          <select v-model="frequency" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
            <option value="monthly">{{ t('other_items.plans.monthly') }}</option>
            <option value="quarterly">{{ t('other_items.plans.quarterly') }}</option>
            <option value="yearly">{{ t('other_items.plans.yearly') }}</option>
          </select>
        </label>
        <label class="text-sm font-medium">{{ t('other_items.plans.ends_on') }}
          <DateInput v-model="endsOn" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
        </label>
        <button type="submit" :disabled="busy" :class="btnOutline('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
          {{ t('other_items.plans.create_schedule') }}
        </button>
      </form>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="font-semibold">{{ t('other_items.plans.installments_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('other_items.plans.installments_hint') }}</p>
      <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <template v-else>
        <div v-for="(row, index) in installments" :key="row.id || index" class="mt-3 flex flex-wrap items-end gap-2">
          <label class="text-sm font-medium">{{ t('other_items.plans.due_number', { number: index + 1 }) }}
            <DateInput v-model="row.due_on" :disabled="!canEditInstallments" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.amount') }}
            <input v-model.number="row.amount" type="number" min="0.01" step="0.01" :disabled="!canEditInstallments" class="mt-1 block h-9 w-32 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
          </label>
          <button v-if="canEditInstallments" type="button" :class="btnOutline('danger')" @click="installments.splice(index, 1)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
        <p v-if="installments.length" class="mt-3 text-sm font-medium">{{ t('other_items.plans.total') }}: {{ formatMoney(installments.reduce((sum, row) => sum + Number(row.amount || 0), 0), item.currency) }}</p>
        <div v-if="canEditInstallments" class="mt-3 flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="addInstallment">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('other_items.plans.add_installment') }}
          </button>
          <button v-if="installments.length >= 2" type="button" :disabled="busy" :class="btnOutline('success')" @click="saveInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
          <button v-if="installments.some(row => row.id)" type="button" :disabled="busy" :class="btnOutline('danger')" @click="clearInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('other_items.plans.clear_installments') }}
          </button>
        </div>
        <p v-else-if="installments.length" class="mt-3 text-sm text-neutral-500">{{ t('other_items.plans.paid_locked') }}</p>
      </template>
    </section>
  </div>
</template>
