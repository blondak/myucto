<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  autoPostingApi,
  type AutomationPreset,
  type AutoPostingPolicy,
  type PolicyLevel,
  type PolicyRow,
} from '@/api/autoPosting'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const props = defineProps<{
  saveAdditional?: () => Promise<void>
}>()

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const policy = ref<AutoPostingPolicy | null>(null)
const initialPreset = ref<AutomationPreset | null>(null)
const changedRows = new Set<string>()

const presets: AutomationPreset[] = ['off', 'suggest', 'assisted', 'full']
const levels: PolicyLevel[] = ['off', 'suggest', 'auto']

type PolicyGroup = 'payments' | 'remittances' | 'bank' | 'ai'
const groups: PolicyGroup[] = ['payments', 'remittances', 'bank', 'ai']

function groupFor(operationType: string): PolicyGroup {
  if (operationType.startsWith('ai.')) return 'ai'
  if (operationType.startsWith('bank.remittance.') || operationType === 'detector.tax_remittance') return 'remittances'
  if (operationType === 'bank.interest' || operationType === 'bank.fee'
    || operationType === 'bank.rule.custom' || operationType === 'bank.learned') return 'bank'
  return 'payments'
}

const groupedRows = computed(() => {
  const result: Record<PolicyGroup, PolicyRow[]> = { payments: [], remittances: [], bank: [], ai: [] }
  for (const row of policy.value?.rows ?? []) result[groupFor(row.operation_type)].push(row)
  return result
})

function operationLabel(operationType: string): string {
  const key = `settings.automation.operation.${operationType.replaceAll('.', '_')}`
  const translated = t(key)
  return translated === key ? operationType : translated
}

function isAi(operationType: string): boolean {
  return operationType.startsWith('ai.')
}

function markRowChanged(operationType: string) {
  changedRows.add(operationType)
  saved.value = false
}

onMounted(async () => {
  try {
    policy.value = await autoPostingApi.getPolicy()
    initialPreset.value = policy.value.automation_level
  } catch (e) {
    error.value = apiErrorMessage(e, t('settings.automation.load_error'))
  } finally {
    loading.value = false
  }
})

async function save() {
  if (!policy.value || saving.value) return
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    await props.saveAdditional?.()
    const limit = policy.value.automation_daily_limit_czk
    const normalizedLimit = limit == null || String(limit).trim() === '' ? null : Number(limit)
    policy.value = await autoPostingApi.putPolicy({
      automation_level: policy.value.automation_level !== initialPreset.value
        ? policy.value.automation_level
        : undefined,
      automation_daily_limit_czk: normalizedLimit,
      automation_digest_enabled: policy.value.automation_digest_enabled,
      rows: policy.value.rows
        .filter(row => changedRows.has(row.operation_type))
        .map(row => ({ operation_type: row.operation_type, level: row.level })),
    })
    initialPreset.value = policy.value.automation_level
    changedRows.clear()
    saved.value = true
  } catch (e) {
    error.value = apiErrorMessage(e, t('settings.automation.save_error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="border border-neutral-200 rounded-lg p-4 bg-neutral-50/50">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div>
        <h3 class="text-sm font-semibold text-neutral-800">{{ t('settings.automation.title') }}</h3>
        <p class="text-xs text-neutral-500 mt-1">{{ t('settings.automation.hint') }}</p>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-neutral-500 py-5">{{ t('common.loading') }}</div>
    <div v-else-if="policy" class="mt-4 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.automation.preset') }}</label>
          <select v-model="policy.automation_level" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="preset in presets" :key="preset" :value="preset">
              {{ t(`settings.automation.preset_${preset}`) }}
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t(`settings.automation.preset_${policy.automation_level}_hint`) }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.automation.daily_limit') }}</label>
          <input v-model.number="policy.automation_daily_limit_czk" type="number" min="0" step="100"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm text-right" />
          <p class="text-xs text-neutral-500 mt-1">{{ t('settings.automation.daily_limit_hint') }}</p>
        </div>
        <label class="flex items-start gap-2 cursor-pointer md:pt-6">
          <input v-model="policy.automation_digest_enabled" type="checkbox"
            class="mt-0.5 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm text-neutral-800">{{ t('settings.automation.digest') }}</span>
        </label>
      </div>

      <details class="border-t border-neutral-200 pt-3">
        <summary class="cursor-pointer text-sm font-medium text-primary-700">{{ t('settings.automation.details') }}</summary>
        <div class="mt-3 space-y-4">
          <section v-for="group in groups" :key="group" v-show="groupedRows[group].length > 0">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">
              {{ t(`settings.automation.group_${group}`) }}
            </h4>

            <div class="hidden md:block overflow-x-auto border border-neutral-200 rounded-md bg-surface">
              <table class="w-full text-sm">
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in groupedRows[group]" :key="row.operation_type">
                    <td class="px-3 py-2 text-neutral-700">{{ operationLabel(row.operation_type) }}</td>
                    <td class="px-3 py-2 text-right w-48">
                      <select v-model="row.level" class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm"
                        :title="isAi(row.operation_type) ? t('automation.ai_no_bulk') : ''"
                        @change="markRowChanged(row.operation_type)">
                        <option v-for="level in levels" :key="level" :value="level"
                          :disabled="level === 'auto' && isAi(row.operation_type)">
                          {{ t(`settings.automation.level_${level}`) }}
                        </option>
                      </select>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="md:hidden space-y-2">
              <label v-for="row in groupedRows[group]" :key="`m-${row.operation_type}`"
                class="block border border-neutral-200 rounded-md bg-surface p-3">
                <span class="block text-sm text-neutral-700 mb-2">{{ operationLabel(row.operation_type) }}</span>
                <select v-model="row.level" class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm"
                  :title="isAi(row.operation_type) ? t('automation.ai_no_bulk') : ''"
                  @change="markRowChanged(row.operation_type)">
                  <option v-for="level in levels" :key="level" :value="level"
                    :disabled="level === 'auto' && isAi(row.operation_type)">
                    {{ t(`settings.automation.level_${level}`) }}
                  </option>
                </select>
              </label>
            </div>
          </section>
        </div>
      </details>

      <div class="flex items-center gap-3 flex-wrap">
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? '…' : t('common.save') }}
        </button>
        <span v-if="saved" class="text-xs text-success-600">{{ t('common.saved') }}</span>
        <span v-if="error" class="text-xs text-danger-500">{{ error }}</span>
      </div>
    </div>
    <p v-else-if="error" class="text-sm text-danger-500 mt-3">{{ error }}</p>
  </div>
</template>
