<script setup lang="ts">
/**
 * Retenční lhůty účetních a daňových záznamů — § 31/§ 32 ZoÚ, § 35a ZDPH.
 *
 * Přehled je čistě informativní: uplynulá lhůta znamená KONEC POVINNOSTI uchovávat,
 * ne pokyn ke skartaci. Zadržení (§ 32) drží záznamy i po uplynutí lhůty — kvůli
 * daňové kontrole, odvolání nebo soudnímu sporu. Nic se tu nemaže.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { retentionApi, type RetentionPeriod, type RetentionHold, type RetentionHoldReason } from '@/api/retention'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const REASONS: RetentionHoldReason[] = ['tax_audit', 'appeal', 'litigation', 'other']

const periods = ref<RetentionPeriod[]>([])
const holds = ref<RetentionHold[]>([])
const includeReleased = ref(false)
const loading = ref(true)
const error = ref('')
const expandedYear = ref<number | null>(null)

const canWrite = computed(() => auth.canWrite('accounting'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [p, h] = await Promise.all([
      retentionApi.overview(),
      retentionApi.holds(includeReleased.value),
    ])
    periods.value = p
    holds.value = h
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function toggleReleased() {
  includeReleased.value = !includeReleased.value
  try {
    holds.value = await retentionApi.holds(includeReleased.value)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── Zadržení ────────────────────────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const form = ref({
  reason: 'tax_audit' as RetentionHoldReason,
  description: '',
  period_year: null as number | null,
})

function openForm(year: number | null = null) {
  form.value = { reason: 'tax_audit', description: '', period_year: year }
  showForm.value = true
}

async function saveHold() {
  if (!form.value.description.trim()) {
    toast.error(t('accounting.retention.description_required'))
    return
  }
  saving.value = true
  try {
    await retentionApi.placeHold({
      reason: form.value.reason,
      description: form.value.description.trim(),
      period_year: form.value.period_year,
    })
    showForm.value = false
    toast.success(t('accounting.retention.hold_placed'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function releaseHold(h: RetentionHold) {
  if (!confirm(t('accounting.retention.release_confirm'))) return
  try {
    await retentionApi.releaseHold(h.id)
    toast.success(t('accounting.retention.hold_released'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('accounting'), disabled: loading.value,
    loading: loading.value, run: load,
  },
  {
    key: 'hold', label: t('accounting.retention.action_hold'), icon: 'plus',
    tier: 'secondary', variant: 'warning',
    show: canWrite.value, disabled: loading.value,
    title: t('accounting.retention.action_hold_hint'), run: () => openForm(),
  },
])

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function statusBadge(p: RetentionPeriod): { label: string; cls: string } {
  if (p.on_hold) return { label: t('accounting.retention.status.on_hold'), cls: 'bg-warning-100 text-warning-700' }
  if (p.expired) return { label: t('accounting.retention.status.expired'), cls: 'bg-neutral-200 text-neutral-600' }
  return { label: t('accounting.retention.status.retained'), cls: 'bg-primary-100 text-primary-700' }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.retention.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.retention.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.retention.explainer_title') }}</p>
      <p>{{ t('accounting.retention.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <template v-else>
      <!-- Přehled období -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
          <h2 class="text-sm font-semibold">{{ t('accounting.retention.periods_title') }}</h2>
        </div>
        <div v-if="periods.length === 0" class="p-6 text-center text-neutral-500 text-sm">
          {{ t('accounting.retention.no_periods') }}
        </div>
        <table v-else class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.retention.col.year') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.retention.col.period_end') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.retention.col.retain_until') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.retention.col.status') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="p in periods" :key="p.year">
              <tr class="hover:bg-neutral-50 cursor-pointer" @click="expandedYear = expandedYear === p.year ? null : p.year">
                <td class="px-3 py-2 font-mono font-semibold">{{ p.year }}</td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(p.period_end) }}</td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(p.retain_until) }}</td>
                <td class="px-3 py-2">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded" :class="statusBadge(p).cls">
                    {{ statusBadge(p).label }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right">
                  <button v-if="canWrite && !p.on_hold" type="button"
                          class="cursor-pointer text-[11px] text-warning-700 hover:underline"
                          @click.stop="openForm(p.year)">
                    {{ t('accounting.retention.hold_this_year') }}
                  </button>
                </td>
              </tr>
              <tr v-if="expandedYear === p.year">
                <td colspan="5" class="px-3 py-2 bg-neutral-50/60">
                  <table class="w-full text-[11px]">
                    <tbody>
                      <tr v-for="s in p.schedule" :key="s.category" class="text-neutral-600">
                        <td class="py-0.5 pr-4">{{ s.label }}</td>
                        <td class="py-0.5 pr-4 whitespace-nowrap">{{ t('accounting.retention.years_count', { years: s.years }) }}</td>
                        <td class="py-0.5 font-mono whitespace-nowrap"
                            :class="s.expired ? 'text-neutral-400 line-through' : ''">
                          {{ fmtDate(s.retain_until) }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Zadržení § 32 -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
          <h2 class="text-sm font-semibold">{{ t('accounting.retention.holds_title') }}</h2>
          <label class="flex items-center gap-1.5 text-xs text-neutral-500 cursor-pointer">
            <input type="checkbox" :checked="includeReleased" @change="toggleReleased"
                   class="h-3.5 w-3.5 rounded border-neutral-300 text-primary-600" />
            {{ t('accounting.retention.show_released') }}
          </label>
        </div>
        <div v-if="holds.length === 0" class="p-6 text-center text-neutral-500 text-sm">
          {{ t('accounting.retention.no_holds') }}
        </div>
        <table v-else class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.retention.col.year') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.retention.col.reason') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.retention.col.description') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.retention.col.placed_on') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.retention.col.released_on') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="h in holds" :key="h.id" :class="{ 'opacity-60': h.released_on }">
              <td class="px-3 py-2 font-mono">{{ h.period_year ?? t('accounting.retention.all_years') }}</td>
              <td class="px-3 py-2">
                <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded bg-warning-100 text-warning-700">
                  {{ t(`accounting.retention.reasons.${h.reason}`) }}
                </span>
              </td>
              <td class="px-3 py-2">{{ h.description }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(h.placed_on) }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(h.released_on) }}</td>
              <td class="px-3 py-2 text-right">
                <button v-if="canWrite && !h.released_on" type="button"
                        class="cursor-pointer text-[11px] text-danger-600 hover:underline"
                        @click="releaseHold(h)">
                  {{ t('accounting.retention.release') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Formulář zadržení -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
         @click.self="showForm = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-5 space-y-3">
        <h3 class="text-lg font-semibold">{{ t('accounting.retention.form_title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('accounting.retention.form_hint') }}</p>

        <div>
          <label class="block text-sm mb-1">{{ t('accounting.retention.col.year') }}</label>
          <select v-model="form.period_year" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option :value="null">{{ t('accounting.retention.all_years') }}</option>
            <option v-for="p in periods" :key="p.year" :value="p.year">{{ p.year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('accounting.retention.col.reason') }}</label>
          <select v-model="form.reason" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="r in REASONS" :key="r" :value="r">{{ t(`accounting.retention.reasons.${r}`) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('accounting.retention.col.description') }}</label>
          <textarea v-model="form.description" rows="2" maxlength="255"
                    :placeholder="t('accounting.retention.description_placeholder')"
                    class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm"
                  @click="showForm = false">{{ t('common.cancel') }}</button>
          <button type="button" :disabled="saving"
                  class="cursor-pointer h-9 px-4 bg-warning-500 hover:bg-warning-600 text-white rounded-md text-sm disabled:opacity-50"
                  @click="saveHold">{{ saving ? t('common.saving') : t('accounting.retention.action_hold') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
