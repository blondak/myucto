<script setup lang="ts">
/**
 * Systém → Diagnostika: náprava nálezů šifrování mzdového archivu přímo
 * z aplikace.
 *
 * 1. Nešifrované dokumenty z doby před šifrováním (kontrola
 *    `payroll_archive_encryption`): náhled, potvrzení, pak dávky, dokud
 *    server hlásí zbytek. Každá dávka se na serveru znovu ověřuje hashem,
 *    originál mizí až po ověřené kopii.
 * 2. Rotace master klíče (kontrola `payroll_key_rotation`): přebalení hodnot
 *    pod starým klíčem na nový. Samotnou výměnu klíče dělá správce serveru
 *    v konfiguraci, klíč se do aplikace nikdy nezadává.
 *
 * Dávkování zastaví i dávka, která nic nezměnila, jinak by UI bušilo do
 * souborů, které se opakovaně nedaří zpracovat.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  diagnosticsApi,
  type DiagnosticCheck,
  type PayrollArchiveReencryptResult,
  type PayrollKeyRewrapResult,
} from '@/api/diagnostics'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, ICONS, btnFilled, btnOutline, disabledTitle } from '@/components/ui/buttonStyles'

const props = defineProps<{ checks: DiagnosticCheck[] }>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const { t } = useI18n()

/** Pojistka proti nekonečnému dávkování, kdyby server hlásil pokrok i zbytek donekonečna. */
const MAX_BATCHES = 1000

interface SupplierFiles {
  supplier_id: number
  name: string | null
  files: number
}

const archiveCheck = computed(() => props.checks.find((c) => c.id === 'payroll_archive_encryption'))
const rotationCheck = computed(() => props.checks.find((c) => c.id === 'payroll_key_rotation'))

const legacyFiles = computed(() => Number(archiveCheck.value?.meta?.legacy_files ?? 0))
const legacySuppliers = computed<SupplierFiles[]>(() => {
  const list = archiveCheck.value?.meta?.suppliers
  return Array.isArray(list) ? (list as SupplierFiles[]) : []
})
const staleValues = computed(() => Number(rotationCheck.value?.meta?.stale_total ?? 0))
const unknownValues = computed(() => Number(rotationCheck.value?.meta?.unknown_total ?? 0))

const showArchive = computed(() => legacyFiles.value > 0)
const showRewrap = computed(() => staleValues.value > 0)

// ── Přešifrování archivu ────────────────────────────────────────────────────
const archiveOpen = ref(false)
const includeOrphans = ref(true)
const purgeErased = ref(false)
const purgeAcknowledged = ref(false)
const preview = ref<PayrollArchiveReencryptResult | null>(null)
const previewBusy = ref(false)
const archiveRunning = ref(false)
const archiveTotals = ref<Record<string, number>>({})
const archiveProblems = ref<PayrollArchiveReencryptResult['problems']>([])
const archiveRemaining = ref<number | null>(null)
const archiveDone = ref(false)
const errorMsg = ref<string | null>(null)

const purgeBlocked = computed(() => purgeErased.value && !purgeAcknowledged.value)
const archiveBlockedReason = computed(() =>
  purgeBlocked.value ? t('diagnostics.payroll_archive.purge_ack_required') : null,
)

async function openArchive() {
  archiveOpen.value = true
  archiveDone.value = false
  archiveTotals.value = {}
  archiveProblems.value = []
  archiveRemaining.value = null
  errorMsg.value = null
  await loadPreview()
}

async function loadPreview() {
  previewBusy.value = true
  errorMsg.value = null
  try {
    preview.value = await diagnosticsApi.payrollArchiveReencrypt({
      dry_run: true,
      include_orphans: includeOrphans.value,
      purge_erased: purgeErased.value,
    })
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.payroll_archive.failed')
  } finally {
    previewBusy.value = false
  }
}

async function runArchive() {
  if (purgeBlocked.value) return
  archiveRunning.value = true
  errorMsg.value = null
  try {
    for (let round = 0; round < MAX_BATCHES; round++) {
      const batch = await diagnosticsApi.payrollArchiveReencrypt({
        include_orphans: includeOrphans.value,
        purge_erased: purgeErased.value,
        confirm: true,
        confirm_purge: purgeErased.value && purgeAcknowledged.value,
      })
      for (const [status, count] of Object.entries(batch.counts)) {
        archiveTotals.value[status] = (archiveTotals.value[status] ?? 0) + count
      }
      archiveProblems.value = [...archiveProblems.value, ...batch.problems].slice(0, 20)
      archiveRemaining.value = batch.remaining
      const progressed = (batch.counts.encrypted ?? 0) + (batch.counts.erased_purged ?? 0)
      if (batch.remaining === 0 || progressed === 0) break
    }
    archiveDone.value = true
    emit('changed')
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.payroll_archive.failed')
  } finally {
    archiveRunning.value = false
  }
}

function closeArchive() {
  if (archiveRunning.value) return
  archiveOpen.value = false
}

const SUMMARY_KEYS = [
  'encrypted',
  'would_encrypt',
  'orphan_skipped',
  'erased_skipped',
  'would_purge',
  'erased_purged',
  'integrity_mismatch',
  'failed',
] as const

function summaryOf(counts: Record<string, number>): { key: string; count: number }[] {
  return SUMMARY_KEYS.map((key) => ({ key, count: counts[key] ?? 0 })).filter((row) => row.count > 0)
}

// ── Přebalení na nový klíč ──────────────────────────────────────────────────
const rewrapOpen = ref(false)
const rewrapRunning = ref(false)
const rewrapResult = ref<PayrollKeyRewrapResult | null>(null)
const rewrapTotal = ref(0)
const rewrapFailed = ref(0)

function openRewrap() {
  rewrapOpen.value = true
  rewrapResult.value = null
  rewrapTotal.value = 0
  rewrapFailed.value = 0
  errorMsg.value = null
}

async function runRewrap() {
  rewrapRunning.value = true
  errorMsg.value = null
  try {
    for (let round = 0; round < MAX_BATCHES; round++) {
      const batch = await diagnosticsApi.payrollKeyRewrap({ confirm: true })
      rewrapResult.value = batch
      rewrapTotal.value += batch.rewrapped
      rewrapFailed.value += batch.failed
      if (batch.remaining === 0 || batch.rewrapped === 0) break
    }
    emit('changed')
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.payroll_archive.failed')
  } finally {
    rewrapRunning.value = false
  }
}

function closeRewrap() {
  if (rewrapRunning.value) return
  rewrapOpen.value = false
}

function supplierLabel(row: SupplierFiles): string {
  return row.name ? `${row.name} (#${row.supplier_id})` : `#${row.supplier_id}`
}
</script>

<template>
  <section
    v-if="showArchive || showRewrap"
    class="rounded-lg border border-warning-300 bg-warning-50/40 p-5"
    data-testid="payroll-archive-panel"
  >
    <h2 class="text-lg font-semibold text-neutral-900">{{ t('diagnostics.payroll_archive.title') }}</h2>

    <!-- Nešifrované dokumenty -->
    <div v-if="showArchive" class="mt-3" data-testid="payroll-archive-legacy">
      <p class="text-sm text-neutral-700">
        {{ t('diagnostics.payroll_archive.legacy_intro', { count: legacyFiles }) }}
      </p>
      <ul class="mt-2 space-y-0.5 text-sm text-neutral-700">
        <li v-for="row in legacySuppliers" :key="row.supplier_id" class="flex flex-wrap gap-x-2">
          <span class="font-medium">{{ supplierLabel(row) }}</span>
          <span class="tabular-nums">{{ t('diagnostics.payroll_archive.files', { count: row.files }) }}</span>
        </li>
      </ul>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnFilled('primary')"
          data-testid="payroll-archive-open"
          @click="openArchive"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
          </svg>
          {{ t('diagnostics.payroll_archive.encrypt') }}
        </button>
      </div>
      <p class="mt-2 text-xs text-neutral-500">{{ t('diagnostics.payroll_archive.cli_hint') }}</p>
    </div>

    <!-- Rotace klíče -->
    <div v-if="showRewrap" class="mt-4" data-testid="payroll-archive-rotation">
      <p class="text-sm text-neutral-700">
        {{ t('diagnostics.payroll_archive.rewrap_intro', { count: staleValues }) }}
      </p>
      <p v-if="unknownValues > 0" class="mt-1 text-sm text-danger-600">
        {{ t('diagnostics.payroll_archive.unknown_key', { count: unknownValues }) }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnFilled('primary')"
          data-testid="payroll-rewrap-open"
          @click="openRewrap"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
          </svg>
          {{ t('diagnostics.payroll_archive.rewrap') }}
        </button>
      </div>
    </div>

    <p v-if="errorMsg && !archiveOpen && !rewrapOpen" class="mt-3 text-sm text-danger-600">{{ errorMsg }}</p>

    <!-- Dialog přešifrování -->
    <Modal
      v-if="archiveOpen"
      :title="t('diagnostics.payroll_archive.dialog_title')"
      width-class="max-w-xl"
      @close="closeArchive"
    >
      <p class="text-sm text-neutral-700">{{ t('diagnostics.payroll_archive.dialog_intro') }}</p>

      <label class="mt-4 flex items-start gap-2 text-sm text-neutral-800">
        <input
          v-model="includeOrphans"
          type="checkbox"
          class="mt-0.5"
          :disabled="archiveRunning || archiveDone"
          data-testid="payroll-archive-orphans"
          @change="loadPreview"
        />
        <span>
          <span class="font-medium">{{ t('diagnostics.payroll_archive.orphans_label') }}</span>
          <span class="block text-xs text-neutral-500">{{ t('diagnostics.payroll_archive.orphans_hint') }}</span>
        </span>
      </label>

      <div class="mt-3 rounded-md border border-danger-500/40 bg-danger-50/40 p-3">
        <label class="flex items-start gap-2 text-sm text-neutral-800">
          <input
            v-model="purgeErased"
            type="checkbox"
            class="mt-0.5"
            :disabled="archiveRunning || archiveDone"
            data-testid="payroll-archive-purge"
            @change="loadPreview"
          />
          <span>
            <span class="font-medium">{{ t('diagnostics.payroll_archive.purge_label') }}</span>
            <span class="block text-xs text-neutral-600">{{ t('diagnostics.payroll_archive.purge_hint') }}</span>
          </span>
        </label>
        <label v-if="purgeErased" class="mt-2 flex items-start gap-2 text-sm text-danger-600">
          <input
            v-model="purgeAcknowledged"
            type="checkbox"
            class="mt-0.5"
            :disabled="archiveRunning || archiveDone"
            data-testid="payroll-archive-purge-ack"
          />
          <span>{{ t('diagnostics.payroll_archive.purge_ack') }}</span>
        </label>
      </div>

      <div class="mt-4">
        <h3 class="text-sm font-semibold text-neutral-800">
          {{ archiveDone || archiveRunning ? t('diagnostics.payroll_archive.result_title') : t('diagnostics.payroll_archive.preview_title') }}
        </h3>
        <p v-if="previewBusy" class="mt-1 text-sm text-neutral-500">{{ t('diagnostics.payroll_archive.loading') }}</p>
        <ul
          v-else
          class="mt-1 divide-y divide-neutral-100 rounded-md border border-neutral-200 text-sm"
          data-testid="payroll-archive-summary"
        >
          <li
            v-for="row in summaryOf(archiveDone || archiveRunning ? archiveTotals : (preview?.counts ?? {}))"
            :key="row.key"
            class="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5"
          >
            <span>{{ t(`diagnostics.payroll_archive.status.${row.key}`) }}</span>
            <span class="tabular-nums font-medium">{{ row.count }}</span>
          </li>
        </ul>
        <p v-if="!archiveDone && !archiveRunning && preview && preview.remaining > 0" class="mt-1 text-xs text-neutral-500">
          {{ t('diagnostics.payroll_archive.preview_partial', { count: preview.remaining }) }}
        </p>
        <p v-if="archiveRemaining !== null && archiveRemaining > 0 && archiveDone" class="mt-1 text-xs text-warning-800">
          {{ t('diagnostics.payroll_archive.stopped', { count: archiveRemaining }) }}
        </p>
        <ul v-if="archiveProblems.length" class="mt-2 space-y-0.5 text-xs text-danger-600">
          <li v-for="problem in archiveProblems" :key="problem.storage_key">
            #{{ problem.supplier_id }} · <span class="font-mono break-all">{{ problem.storage_key }}</span>
            · {{ t(`diagnostics.payroll_archive.status.${problem.status}`) }}
          </li>
        </ul>
        <p v-if="archiveDone" class="mt-2 text-sm text-success-700" data-testid="payroll-archive-done">
          {{ t('diagnostics.payroll_archive.done') }}
        </p>
      </div>

      <p v-if="errorMsg" class="mt-3 text-sm text-danger-600">{{ errorMsg }}</p>

      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="archiveRunning" @click="closeArchive">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
            {{ archiveDone ? t('diagnostics.payroll_archive.close') : t('diagnostics.payroll_archive.cancel') }}
          </button>
          <button
            v-if="!archiveDone"
            type="button"
            :class="btnFilled('primary')"
            :disabled="archiveRunning || previewBusy || purgeBlocked"
            :title="disabledTitle(purgeBlocked, archiveBlockedReason)"
            data-testid="payroll-archive-confirm"
            @click="runArchive"
          >
            <svg class="w-4 h-4" :class="{ 'animate-spin': archiveRunning }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="archiveRunning ? ICONS.cycle : ICONS.lock" />
            </svg>
            {{ archiveRunning ? t('diagnostics.payroll_archive.running') : t('diagnostics.payroll_archive.confirm') }}
          </button>
        </div>
        <p v-if="archiveBlockedReason" :class="[BTN_DISABLED_NOTE, 'mt-2 text-right']">{{ archiveBlockedReason }}</p>
      </template>
    </Modal>

    <!-- Dialog přebalení -->
    <Modal
      v-if="rewrapOpen"
      :title="t('diagnostics.payroll_archive.rewrap_title')"
      width-class="max-w-xl"
      @close="closeRewrap"
    >
      <p class="text-sm text-neutral-700">{{ t('diagnostics.payroll_archive.rewrap_dialog', { count: staleValues }) }}</p>
      <p v-if="unknownValues > 0" class="mt-2 text-sm text-danger-600">
        {{ t('diagnostics.payroll_archive.unknown_key', { count: unknownValues }) }}
      </p>
      <dl v-if="rewrapResult" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm" data-testid="payroll-rewrap-result">
        <dt class="text-neutral-500">{{ t('diagnostics.payroll_archive.rewrapped') }}</dt>
        <dd class="tabular-nums font-medium">{{ rewrapTotal }}</dd>
        <dt class="text-neutral-500">{{ t('diagnostics.payroll_archive.rewrap_failed') }}</dt>
        <dd class="tabular-nums font-medium" :class="rewrapFailed > 0 ? 'text-danger-600' : ''">{{ rewrapFailed }}</dd>
        <dt class="text-neutral-500">{{ t('diagnostics.payroll_archive.rewrap_remaining') }}</dt>
        <dd class="tabular-nums font-medium">{{ rewrapResult.remaining }}</dd>
      </dl>
      <p v-if="rewrapResult && rewrapResult.remaining === 0" class="mt-2 text-sm text-success-700">
        {{ t('diagnostics.payroll_archive.rewrap_done') }}
      </p>
      <p v-if="errorMsg" class="mt-3 text-sm text-danger-600">{{ errorMsg }}</p>

      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="rewrapRunning" @click="closeRewrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
            {{ rewrapResult ? t('diagnostics.payroll_archive.close') : t('diagnostics.payroll_archive.cancel') }}
          </button>
          <button
            v-if="!rewrapResult"
            type="button"
            :class="btnFilled('primary')"
            :disabled="rewrapRunning"
            data-testid="payroll-rewrap-confirm"
            @click="runRewrap"
          >
            <svg class="w-4 h-4" :class="{ 'animate-spin': rewrapRunning }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
            </svg>
            {{ rewrapRunning ? t('diagnostics.payroll_archive.running') : t('diagnostics.payroll_archive.rewrap_confirm') }}
          </button>
        </div>
      </template>
    </Modal>
  </section>
</template>
