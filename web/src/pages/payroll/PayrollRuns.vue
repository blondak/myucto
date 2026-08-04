<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollRun,
  type PayrollRunCommand,
} from '@/api/payroll'
import PayrollIncomeTaxBreakdown from '@/components/payroll/PayrollIncomeTaxBreakdown.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const period = ref(localPayrollPeriod())
const paymentDate = ref(defaultPaymentDate(period.value))
const runs = ref<PayrollRun[]>([])
const personNames = ref<Record<number, string>>({})
const pendingCommand = ref<{ run: PayrollRun, command: PayrollRunCommand } | null>(null)
const pendingDelete = ref<PayrollRun | null>(null)
const commandReason = ref('')
const commandError = ref('')

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))

function defaultPaymentDate(value: string): string {
  const [year, month] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month, 15))
  return date.toISOString().slice(0, 10)
}

function money(value: number | undefined): string {
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
  }).format((value ?? 0) / 100)
}

function statusClass(status: PayrollRun['status']): string {
  if (status === 'approved' || status === 'closed' || status === 'paid') {
    return 'bg-success-50 text-success-600'
  }
  if (status === 'cancelled' || status === 'correction_pending') {
    return 'bg-warning-50 text-warning-600'
  }
  if (status === 'calculated' || status === 'reviewed') {
    return 'bg-payroll-50 text-payroll-600'
  }
  return 'bg-neutral-100 text-neutral-600'
}

function commandLabel(command: PayrollRunCommand): string {
  return t(`payroll.runs.commands.${command}`)
}

function commandClass(command: PayrollRunCommand): string {
  if (command === 'approve') return btnFilled('success')
  if (command === 'cancel') return btnOutline('danger')
  if (command === 'request_correction' || command === 'reopen') {
    return btnOutline('warning')
  }
  if (command === 'review') return btnOutline('success')
  return btnFilled('primary')
}

function commandIcon(command: PayrollRunCommand): string {
  if (command === 'lock_inputs') return ICONS.lock
  if (command === 'calculate') return ICONS.cycle
  if (command === 'review' || command === 'approve' || command === 'close') {
    return ICONS.check
  }
  if (command === 'cancel') return ICONS.x
  return ICONS.uturn
}

function visibleCommands(run: PayrollRun): PayrollRunCommand[] {
  return run.available_commands.filter(command => {
    if (!['lock_inputs', 'calculate', 'review', 'approve', 'request_correction', 'reopen', 'cancel', 'close']
      .includes(command)) return false
    if (command === 'calculate') return auth.canWrite('payroll.calculate')
    if (command === 'review' || command === 'request_correction') {
      return auth.canWrite('payroll.review')
    }
    if (command === 'approve') return auth.canWrite('payroll.approve')
    if (command === 'reopen') return auth.canWrite('payroll.reopen')
    return canWrite.value
  })
}

async function load() {
  loading.value = true
  try {
    const [loadedRuns, people] = await Promise.all([
      payrollApi.runs(period.value),
      payrollApi.people().catch(() => null),
    ])
    runs.value = loadedRuns
    if (people !== null) {
      personNames.value = Object.fromEntries(
        people.map(person => [person.id, person.full_name]),
      )
    }
  } catch {
    toast.error(t('payroll.runs.load_failed'))
  } finally {
    loading.value = false
  }
}

async function createRun() {
  if (!canWrite.value) return
  saving.value = true
  try {
    await payrollApi.createRun({
      period_start: `${period.value}-01`,
      payment_date: paymentDate.value,
      office_id: null,
    })
    toast.success(t('payroll.runs.created'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.runs.save_failed'))
  } finally {
    saving.value = false
  }
}

async function runCommand(run: PayrollRun, command: PayrollRunCommand) {
  if (['request_correction', 'reopen', 'cancel'].includes(command)) {
    pendingCommand.value = { run, command }
    commandReason.value = ''
    commandError.value = ''
    return
  }
  await submitCommand(run, command)
}

async function submitCommand(
  run: PayrollRun,
  command: PayrollRunCommand,
  reason?: string,
) {
  saving.value = true
  try {
    await payrollApi.commandRun(
      run.id,
      command,
      { row_version: run.row_version, ...(reason ? { reason } : {}) },
      crypto.randomUUID(),
    )
    toast.success(t('payroll.runs.command_done'))
    pendingCommand.value = null
    commandReason.value = ''
    commandError.value = ''
    await load()
  } catch (error: any) {
    const message = error?.response?.data?.error?.message || t('payroll.runs.command_failed')
    if (pendingCommand.value) commandError.value = message
    else toast.error(message)
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

async function confirmCommand() {
  if (!pendingCommand.value) return
  const reason = commandReason.value.trim()
  if (!reason) {
    commandError.value = t('payroll.runs.reason_required')
    return
  }
  await submitCommand(
    pendingCommand.value.run,
    pendingCommand.value.command,
    reason,
  )
}

function askDeleteRun(run: PayrollRun) {
  if (!canWrite.value || !run.can_delete) return
  pendingDelete.value = run
}

async function deleteRun() {
  const run = pendingDelete.value
  if (!run) return
  saving.value = true
  try {
    await payrollApi.deleteRun(run.id, run.row_version)
    toast.success(t('payroll.runs.deleted'))
    pendingDelete.value = null
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.runs.delete_failed'))
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

function changePeriod() {
  paymentDate.value = defaultPaymentDate(period.value)
  void load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.runs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.runs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.period') }}</span>
          <input
            v-model="period"
            type="month"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            @change="changePeriod"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.payment_date') }}</span>
          <input
            v-model="paymentDate"
            type="date"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
          >
        </label>
        <RouterLink
          :to="{ name: 'payroll-quick-inputs' }"
          :class="btnOutline('primary')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.coin" />
          </svg>
          {{ t('payroll.runs.quick_inputs') }}
        </RouterLink>
        <button
          v-if="canWrite"
          :class="btnFilled('primary')"
          :disabled="saving || !period || !paymentDate"
          @click="createRun"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.runs.create') }}
        </button>
      </div>
    </header>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 2" :key="index" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <section
      v-else-if="runs.length === 0"
      class="rounded-xl border border-dashed border-neutral-300 bg-surface p-8 text-center"
    >
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.runs.empty') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.runs.empty_hint') }}</p>
      <RouterLink
        :to="{ name: 'payroll-quick-inputs' }"
        :class="[btnOutline('primary'), 'mt-4']"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        {{ t('payroll.runs.quick_inputs') }}
      </RouterLink>
    </section>

    <section v-else class="space-y-4">
      <article
        v-for="run in runs"
        :key="run.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-semibold text-neutral-900">
                {{ t('payroll.runs.run_label', { period: run.period_start.slice(0, 7) }) }}
              </h2>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(run.status)">
                {{ t(`payroll.runs.status.${run.status}`) }}
              </span>
            </div>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.runs.payment_date_value', { date: run.payment_date }) }}
              · {{ t('payroll.runs.revision', { revision: run.revision_no ?? 0 }) }}
            </p>
          </div>
          <div
            v-if="visibleCommands(run).length || (canWrite && run.can_delete)"
            class="flex flex-wrap justify-end gap-2"
          >
            <button
              v-for="command in visibleCommands(run)"
              :key="command"
              :class="commandClass(command)"
              :disabled="saving"
              @click="runCommand(run, command)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="commandIcon(command)" />
              </svg>
              {{ commandLabel(command) }}
            </button>
            <button
              v-if="canWrite && run.can_delete"
              :data-testid="`delete-payroll-run-${run.id}`"
              :class="btnOutline('danger')"
              :disabled="saving"
              @click="askDeleteRun(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.trash" />
              </svg>
              {{ t('payroll.runs.delete') }}
            </button>
          </div>
        </div>

        <dl v-if="run.result_snapshot?.totals" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-lg bg-neutral-50 p-3">
            <dt class="text-xs text-neutral-500">{{ t('payroll.runs.cash_before') }}</dt>
            <dd class="mt-1 font-semibold text-neutral-900">
              {{ money(run.result_snapshot.totals.cash_payable_minor) }}
            </dd>
          </div>
          <div class="rounded-lg bg-payroll-50 p-3">
            <dt class="text-xs text-payroll-700">{{ t('payroll.runs.enforcement_withheld') }}</dt>
            <dd class="mt-1 font-semibold text-payroll-700">
              {{ money(run.result_snapshot.totals.enforcement_withheld_minor) }}
            </dd>
          </div>
          <div class="rounded-lg bg-success-50 p-3">
            <dt class="text-xs text-success-700">{{ t('payroll.runs.payable_after') }}</dt>
            <dd class="mt-1 font-semibold text-success-700">
              {{ money(run.result_snapshot.totals.payable_after_enforcement_minor) }}
            </dd>
          </div>
        </dl>

        <PayrollIncomeTaxBreakdown
          v-if="run.result_snapshot?.people"
          :people="run.result_snapshot.people"
          :person-names="personNames"
        />

        <div v-if="run.validations.length" class="mt-4 space-y-2">
          <p class="text-sm font-medium text-warning-700">{{ t('payroll.runs.validations') }}</p>
          <div
            v-for="validation in run.validations"
            :key="validation.id"
            class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800"
          >
            {{ validation.message }}
          </div>
        </div>
      </article>
    </section>

    <Modal
      v-if="pendingCommand"
      :title="commandLabel(pendingCommand.command)"
      width-class="max-w-lg"
      @close="pendingCommand = null"
    >
      <form class="space-y-4" data-test="run-command-dialog" @submit.prevent="confirmCommand">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.runs.reason_prompt') }}
          <textarea
            v-model="commandReason"
            class="mt-1 min-h-24 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            required
            autofocus
            data-test="run-command-reason"
          />
        </label>
        <p
          v-if="commandError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="run-command-error"
        >
          {{ commandError }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingCommand = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" :class="commandClass(pendingCommand.command)" :disabled="saving">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="commandIcon(pendingCommand.command)" /></svg>
            {{ commandLabel(pendingCommand.command) }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="pendingDelete"
      :title="t('payroll.runs.delete')"
      width-class="max-w-lg"
      @close="pendingDelete = null"
    >
      <p class="text-sm text-neutral-700">{{ t('payroll.runs.delete_confirm') }}</p>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="pendingDelete = null">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          :class="btnFilled('danger')"
          :disabled="saving"
          data-test="confirm-delete-run"
          @click="deleteRun"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          {{ t('payroll.runs.delete') }}
        </button>
      </div>
    </Modal>
  </div>
</template>
