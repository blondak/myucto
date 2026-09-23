<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  companyProfileApi,
  COMPANY_PROFILE_SECTIONS,
  type CompanyProfile,
  type CompanyProfileImportResult,
  type CompanyProfileSection,
} from '@/api/companyProfile'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Profil firmy: stažení a nahrání ručně vybudovaného nastavení (výjimky mapování
 * výkazů, volby výkazů, daňový profil, dimenze, předkontace, pravidla banky).
 * Nahrání vždy nejdřív ukáže náhled (zkouška nanečisto na serveru) a zapíše až
 * po potvrzení. `variant="migration"` je text pro průvodce převodem.
 */
const props = withDefaults(defineProps<{ variant?: 'settings' | 'migration' }>(), { variant: 'settings' })

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const SECTION_LABELS: Record<CompanyProfileSection, string> = {
  company: 'company_profile.section.company',
  tax_profile: 'company_profile.section.tax_profile',
  accounting_settings: 'company_profile.section.accounting_settings',
  statement_overrides: 'company_profile.section.statement_overrides',
  dimensions: 'company_profile.section.dimensions',
  dimension_defaults: 'company_profile.section.dimension_defaults',
  posting_rules: 'company_profile.section.posting_rules',
  bank_rule_templates: 'company_profile.section.bank_rule_templates',
  bank_posting_rules: 'company_profile.section.bank_posting_rules',
}

const canWrite = computed(() => auth.canWrite('settings.company.write'))
const exporting = ref(false)
const busy = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const pending = ref<{ profile: CompanyProfile, fileName: string } | null>(null)
const preview = ref<CompanyProfileImportResult | null>(null)
const applied = ref<CompanyProfileImportResult | null>(null)
const error = ref<string | null>(null)

const report = computed(() => applied.value ?? preview.value)
const reportSections = computed(() => COMPANY_PROFILE_SECTIONS
  .filter(s => report.value?.sections[s] !== undefined)
  .map(s => ({ key: s, label: t(SECTION_LABELS[s]), ...report.value!.sections[s]! })))

function errorMessage(e: any): string {
  const err = e?.response?.data?.error
  if (err?.section && (COMPANY_PROFILE_SECTIONS as readonly string[]).includes(err.section)) {
    return t('company_profile.error_in_section', { section: t(SECTION_LABELS[err.section as CompanyProfileSection]), message: err.message })
  }
  return err?.message || t('common.error')
}

async function download() {
  exporting.value = true
  try {
    const profile = await companyProfileApi.export()
    const ic = (profile.company?.ic ?? '').replace(/[^0-9A-Za-z]/g, '') || 'firma'
    const blob = new Blob([JSON.stringify(profile, null, 2) + '\n'], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `profil-firmy-${ic}-${new Date().toISOString().slice(0, 10)}.json`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    exporting.value = false
  }
}

function pickFile() {
  fileInput.value?.click()
}

async function onFile(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  reset()
  let profile: CompanyProfile
  try {
    profile = JSON.parse(await file.text())
  } catch {
    error.value = t('company_profile.invalid_json')
    return
  }
  busy.value = true
  try {
    preview.value = await companyProfileApi.import(profile, true)
    pending.value = { profile, fileName: file.name }
  } catch (e: any) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function apply() {
  if (!pending.value) return
  busy.value = true
  try {
    applied.value = await companyProfileApi.import(pending.value.profile, false)
    pending.value = null
    preview.value = null
    toast.success(applied.value.changed > 0
      ? t('company_profile.applied', { count: applied.value.changed })
      : t('company_profile.applied_nothing'))
  } catch (e: any) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function reset() {
  pending.value = null
  preview.value = null
  applied.value = null
  error.value = null
}
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm" data-test="company-profile">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('company_profile.title') }}</h2>
    <p class="text-xs text-neutral-500 mb-3">
      {{ props.variant === 'migration' ? t('company_profile.hint_migration') : t('company_profile.hint') }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="exporting"
        data-test="company-profile-export" @click="download">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        {{ t('company_profile.export') }}
      </button>
      <button v-if="canWrite" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="busy"
        data-test="company-profile-import" @click="pickFile">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
        {{ t('company_profile.import') }}
      </button>
      <input ref="fileInput" type="file" accept=".json,application/json" class="hidden" data-test="company-profile-file" @change="onFile" />
      <span v-if="busy" class="text-xs text-neutral-500">{{ t('common.loading') }}</span>
    </div>

    <p v-if="error" class="mt-3 rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-test="company-profile-error">
      {{ error }}
    </p>

    <div v-if="report" class="mt-4 space-y-3" data-test="company-profile-report">
      <p class="text-sm font-medium text-neutral-800">
        <template v-if="pending">{{ t('company_profile.preview_title', { file: pending.fileName }) }}</template>
        <template v-else>{{ t('company_profile.applied_title') }}</template>
      </p>
      <ul v-if="report.warnings.length" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-600 space-y-0.5">
        <li v-for="(w, i) in report.warnings" :key="i">{{ w }}</li>
      </ul>
      <p v-if="pending && report.changed === 0" class="text-sm text-neutral-600">{{ t('company_profile.no_changes') }}</p>

      <div class="divide-y divide-neutral-100 rounded-md border border-neutral-200">
        <details v-for="s in reportSections" :key="s.key" class="px-3 py-2" :open="s.warnings.length > 0">
          <summary class="cursor-pointer text-sm flex flex-wrap items-baseline gap-x-3">
            <span class="font-medium text-neutral-800">{{ s.label }}</span>
            <span class="text-xs text-neutral-500">
              {{ t('company_profile.counts', { created: s.created, updated: s.updated, removed: s.removed, unchanged: s.unchanged }) }}
            </span>
            <span v-if="s.warnings.length" class="text-xs text-warning-600">{{ t('company_profile.warnings', { count: s.warnings.length }) }}</span>
          </summary>
          <ul class="mt-2 space-y-0.5 text-xs font-mono text-neutral-600">
            <li v-for="(line, i) in s.changes" :key="'c' + i">{{ line }}</li>
            <li v-for="(w, i) in s.warnings" :key="'w' + i" class="text-warning-600 font-sans">{{ w }}</li>
          </ul>
        </details>
      </div>

      <div v-if="pending" class="flex flex-wrap items-center gap-2">
        <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="busy || report.changed === 0"
          data-test="company-profile-apply" @click="apply">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('company_profile.apply') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="busy" @click="reset">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </section>
</template>
