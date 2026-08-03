<script setup lang="ts">
/**
 * Vlastní bankovní účty firmy — metadata pro kontaci: druh účtu (běžný / spořicí /
 * termínovaný vklad), label a analytika 221.xxx (analytic_suffix). Účty detekuje
 * systém z výpisů/nastavení; tady se jen pojmenují a zaškatulkují, aby deník
 * a výkazy členily účet 221 po analytikách.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { bankPostingApi, type SupplierBankAccount, type BankAccountKind } from '@/api/bankPosting'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const KINDS: BankAccountKind[] = ['current', 'savings', 'term_deposit']

const accounts = ref<SupplierBankAccount[]>([])
const loading = ref(true)
const error = ref('')
const saving = ref(false)

// Drafty per účet — uložené hodnoty se drží zvlášť, ať jde poznat „změněno".
interface Draft { kind: BankAccountKind; label: string; analytic_suffix: string; is_active: boolean }
const drafts = ref<Record<number, Draft>>({})

const canWrite = computed(() => auth.canWrite('accounting'))

function draftFrom(a: SupplierBankAccount): Draft {
  return {
    kind: a.kind,
    label: a.label ?? '',
    analytic_suffix: a.analytic_suffix ?? '',
    is_active: Boolean(a.is_active),
  }
}

function isDirty(a: SupplierBankAccount): boolean {
  const d = drafts.value[a.id]
  if (!d) return false
  return d.kind !== a.kind
    || d.label !== (a.label ?? '')
    || d.analytic_suffix !== (a.analytic_suffix ?? '')
    || d.is_active !== Boolean(a.is_active)
}

const dirtyCount = computed(() => accounts.value.filter(isDirty).length)

async function load() {
  loading.value = true
  error.value = ''
  try {
    accounts.value = await bankPostingApi.listAccounts()
    const d: Record<number, Draft> = {}
    for (const a of accounts.value) d[a.id] = draftFrom(a)
    drafts.value = d
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function saveAll() {
  if (!canWrite.value || saving.value || dirtyCount.value === 0) return
  saving.value = true
  try {
    for (const a of accounts.value.filter(isDirty)) {
      const d = drafts.value[a.id]
      if (d.analytic_suffix !== '' && !/^[0-9]{1,6}$/.test(d.analytic_suffix)) {
        toast.error(t('bank.analytics.suffix_invalid', { account: a.account_number ?? a.iban ?? a.id }))
        return
      }
      await bankPostingApi.updateAccount(a.id, {
        kind: d.kind,
        label: d.label.trim() || null,
        analytic_suffix: d.analytic_suffix.trim() || null,
        is_active: d.is_active,
      })
    }
    toast.success(t('bank.analytics.saved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load()
  } finally {
    saving.value = false
  }
}

function accountLabel(a: SupplierBankAccount): string {
  if (a.account_number) return a.account_number + (a.bank_code ? '/' + a.bank_code : '')
  return a.iban ?? String(a.id)
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('bank.analytics.explainer_title') }}</p>
      <p>{{ t('bank.analytics.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <EmptyState v-else-if="accounts.length === 0" boxed icon="coin" :title="t('bank.analytics.empty')" />

    <template v-else>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.currency') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.kind') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('bank.analytics.col.analytic') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank.analytics.col.active') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in accounts" :key="a.id" :class="{ 'bg-primary-50/40': isDirty(a) }">
                <td class="px-3 py-2 font-mono whitespace-nowrap">
                  {{ accountLabel(a) }}
                  <div v-if="a.iban && a.account_number" class="text-[10px] text-neutral-400">{{ a.iban }}</div>
                </td>
                <td class="px-3 py-2 font-mono">{{ a.currency ?? '—' }}</td>
                <td class="px-3 py-2">
                  <input v-model="drafts[a.id].label" type="text" maxlength="120" :disabled="!canWrite"
                         :placeholder="t('bank.analytics.label_placeholder')"
                         class="w-44 h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface disabled:bg-neutral-50" />
                </td>
                <td class="px-3 py-2">
                  <select v-model="drafts[a.id].kind" :disabled="!canWrite"
                          class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface disabled:bg-neutral-50">
                    <option v-for="k in KINDS" :key="k" :value="k">{{ t(`bank.analytics.kinds.${k}`) }}</option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <div class="flex items-center gap-1 font-mono">
                    <span class="text-neutral-400">221.</span>
                    <input v-model="drafts[a.id].analytic_suffix" type="text" maxlength="6" :disabled="!canWrite"
                           placeholder="—"
                           class="w-20 h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface font-mono disabled:bg-neutral-50" />
                  </div>
                </td>
                <td class="px-3 py-2 text-center">
                  <input v-model="drafts[a.id].is_active" type="checkbox" :disabled="!canWrite"
                         class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="canWrite" class="sticky bottom-4 mt-4 flex items-center justify-end gap-3 bg-surface/95 backdrop-blur border border-neutral-200 rounded-lg px-4 py-3 shadow-md">
        <span v-if="dirtyCount" class="text-xs text-neutral-500">
          {{ t('bank.analytics.unsaved', { count: dirtyCount }) }}
        </span>
        <button type="button" :disabled="dirtyCount === 0 || saving"
                class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm disabled:opacity-50"
                @click="saveAll">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </template>
  </div>
</template>
