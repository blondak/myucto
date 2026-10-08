<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PermissionKey } from '@/security/permissions'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { myuctoImportApi, MYUCTO_IMPORT_MAX_BYTES, type MyuctoImportResult } from '@/api/myuctoImport'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import CompanyProfileBox from '@/components/settings/CompanyProfileBox.vue'

const { t, te } = useI18n()
const auth = useAuthStore()
const suppliers = useSupplierStore()
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const source = ref('puvodni-instance')
const password = ref('')
const token = ref('')
const result = ref<MyuctoImportResult | null>(null)
const confirmed = ref(false)
const busy = ref(false)
const uploading = ref(false)
const applying = ref(false)
const progress = ref(0)
const error = ref('')
let revision = 0
const canWrite = computed(() => (['utilities.import', 'accounting.journal.write', 'settings.company.write'] as PermissionKey[]).every(key => auth.canWrite(key)))
const passed = computed(() => !!result.value?.report.dry_run)
const done = computed(() => result.value?.report.dry_run === false)
const validSource = computed(() => /^[a-zA-Z0-9._-]{1,80}$/.test(source.value))
const rows = computed(() => Object.entries(result.value?.report.reconciliation ?? {}).map(([table, count]) => ({
  table, count,
  created: result.value?.report.created[table] ?? 0,
  reused: result.value?.report.reused[table] ?? 0,
  existing: result.value?.report.existing[table] ?? 0,
})))
function label(table: string): string {
  const key = `myucto_import.tables.${table}`
  return te(key) ? t(key) : t('myucto_import.other_area')
}
function reset(): void {
  revision++
  token.value = ''; result.value = null; confirmed.value = false; error.value = ''
  progress.value = 0
}
watch(source, () => { result.value = null; confirmed.value = false; revision++ })
watch(password, () => { if (!done.value) { result.value = null; confirmed.value = false; revision++ } })
watch(() => suppliers.currentSupplierId, () => {
  reset(); file.value = null; password.value = ''
  if (fileInput.value) fileInput.value.value = ''
})
function onFile(event: Event): void {
  reset()
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  if (file.value && (!file.value.name.toLowerCase().endsWith('.zip') || file.value.size > MYUCTO_IMPORT_MAX_BYTES || file.value.size === 0)) {
    error.value = t('myucto_import.invalid_file'); file.value = null
  }
}
async function run(apply: boolean): Promise<void> {
  if (busy.value || !canWrite.value || !validSource.value || (!token.value && !file.value) || (apply && (!passed.value || !confirmed.value))) return
  const currentRevision = revision
  const supplierId = suppliers.currentSupplierId
  busy.value = true; applying.value = apply; error.value = ''
  try {
    if (!token.value && file.value) {
      uploading.value = true
      const uploaded = await myuctoImportApi.upload(file.value, (sent, total) => { progress.value = Math.round(sent / total * 100) })
      if (revision !== currentRevision || suppliers.currentSupplierId !== supplierId) return
      token.value = uploaded.token
      uploading.value = false
    }
    const response = await myuctoImportApi.run(token.value, source.value, password.value, apply)
    if (revision !== currentRevision || suppliers.currentSupplierId !== supplierId) return
    result.value = response
    confirmed.value = false
    if (apply) password.value = ''
  } catch (e: unknown) {
    if (revision !== currentRevision) return
    const response = e as { response?: { data?: { error?: { message?: string } } } }
    error.value = response.response?.data?.error?.message ?? t('myucto_import.failed')
    if (!apply) result.value = null
  } finally {
    busy.value = false; uploading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <RouterLink to="/imports" class="text-sm text-primary-700 hover:underline">{{ t('myucto_import.back') }}</RouterLink>
      <h1 class="mt-2 text-2xl font-semibold text-neutral-900">{{ t('myucto_import.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('myucto_import.intro') }}</p>
    </div>
    <div class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700">
      {{ t('myucto_import.requirements') }}
    </div>
    <div v-if="!canWrite" class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700">{{ t('myucto_import.rights') }}</div>
    <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm space-y-4">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('myucto_import.input') }}</h2>
      <div>
        <label for="myucto-backup" class="block text-sm font-medium text-neutral-700">{{ t('myucto_import.file') }}</label>
        <input id="myucto-backup" ref="fileInput" type="file" accept=".zip,application/zip" :disabled="busy || done || !canWrite" class="form-input mt-1 block w-full" @change="onFile">
        <p class="mt-1 text-xs text-neutral-500">{{ t('myucto_import.file_hint') }}</p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="myucto-source" class="block text-sm font-medium text-neutral-700">{{ t('myucto_import.source') }}</label>
          <input id="myucto-source" v-model="source" maxlength="80" :disabled="busy || done" class="form-input mt-1 block w-full" autocomplete="off">
          <p class="mt-1 text-xs text-neutral-500">{{ t('myucto_import.source_hint') }}</p>
        </div>
        <div>
          <label for="myucto-password" class="block text-sm font-medium text-neutral-700">{{ t('myucto_import.password') }}</label>
          <input id="myucto-password" v-model="password" type="password" maxlength="1024" :disabled="busy || done" class="form-input mt-1 block w-full" autocomplete="new-password">
          <p class="mt-1 text-xs text-neutral-500">{{ t('myucto_import.password_hint') }}</p>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button v-if="!done" type="button" :class="passed ? btnOutline('neutral') : btnFilled('primary')" :disabled="busy || !canWrite || !validSource || (!file && !token)" @click="run(false)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('myucto_import.check') }}
        </button>
      </div>
      <div v-if="busy" role="status" class="space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3">
        <p class="text-sm font-medium text-primary-700">{{ uploading ? t('myucto_import.uploading', { percent: progress }) : t(applying ? 'myucto_import.applying' : 'myucto_import.processing') }}</p>
        <div class="h-2 overflow-hidden rounded-full bg-primary-100" role="progressbar" :aria-label="t('myucto_import.progress')" :aria-valuenow="uploading ? progress : undefined" :aria-valuemin="0" :aria-valuemax="100">
          <div class="h-full bg-primary-500 transition-all duration-300" :class="uploading ? '' : 'w-1/3 animate-pulse'" :style="uploading ? { width: progress + '%' } : undefined" />
        </div>
      </div>
    </section>
    <div v-if="error" role="alert" class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700">{{ error }}</div>
    <section v-if="result" class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm space-y-4">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t(done ? 'myucto_import.completed' : 'myucto_import.checked') }}</h2>
          <span class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-600">{{ t(done ? 'myucto_import.status_completed' : 'myucto_import.status_checked') }}</span>
        </div>
        <p class="mt-1 text-sm text-neutral-600">{{ t('myucto_import.company', { name: result.company_name, ic: result.ic }) }}</p>
      </div>
      <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 sm:block">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-600"><tr><th scope="col" class="px-3 py-2 text-left">{{ t('myucto_import.area') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('myucto_import.verified') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('myucto_import.created') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('myucto_import.reused') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('myucto_import.existing') }}</th></tr></thead>
          <tbody><tr v-for="row in rows" :key="row.table" class="border-t border-neutral-100"><td class="px-3 py-2 text-neutral-900">{{ label(row.table) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ row.count }}</td><td class="px-3 py-2 text-right tabular-nums">{{ row.created }}</td><td class="px-3 py-2 text-right tabular-nums">{{ row.reused }}</td><td class="px-3 py-2 text-right tabular-nums">{{ row.existing }}</td></tr></tbody>
        </table>
      </div>
      <div class="space-y-3 sm:hidden" data-testid="myucto-mobile-results">
        <div v-for="row in rows" :key="row.table" class="rounded-lg border border-neutral-200 p-3">
          <h3 class="text-sm font-semibold text-neutral-900">{{ label(row.table) }}</h3>
          <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div><dt class="text-xs text-neutral-500">{{ t('myucto_import.verified') }}</dt><dd class="font-medium tabular-nums text-neutral-900">{{ row.count }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('myucto_import.created') }}</dt><dd class="font-medium tabular-nums text-neutral-900">{{ row.created }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('myucto_import.reused') }}</dt><dd class="font-medium tabular-nums text-neutral-900">{{ row.reused }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('myucto_import.existing') }}</dt><dd class="font-medium tabular-nums text-neutral-900">{{ row.existing }}</dd></div>
          </dl>
        </div>
      </div>
      <p class="text-sm text-neutral-600">{{ t('myucto_import.files', { count: result.report.files }) }}</p>
      <p class="text-sm text-neutral-600">{{ t('myucto_import.recurring_hint') }}</p>
      <div v-if="Object.keys(result.report.outside_scope).length" class="rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700">
        <h3 class="font-semibold">{{ t('myucto_import.outside_scope') }}</h3>
        <ul class="mt-2 list-disc pl-5"><li v-for="(count, table) in result.report.outside_scope" :key="table">{{ label(String(table)) }}: {{ count }}</li></ul>
      </div>
      <template v-if="passed">
        <label class="flex items-start gap-2 text-sm text-neutral-700"><input v-model="confirmed" type="checkbox" :disabled="busy" class="mt-0.5"><span>{{ t('myucto_import.confirm', { name: suppliers.currentSupplier?.company_name ?? '' }) }}</span></label>
        <div class="flex flex-wrap gap-3">
          <button type="button" :class="btnFilled('success')" :disabled="busy || !confirmed || !canWrite" @click="run(true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('myucto_import.apply') }}</button>
        </div>
      </template>
      <RouterLink v-if="done" to="/invoices" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('myucto_import.open_invoices') }}</RouterLink>
    </section>
    <CompanyProfileBox variant="migration" />
  </div>
</template>
