<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useRowLink } from '@/composables/useRowLink'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { useHotkey } from '@/composables/useHotkey'
import CodebookImportDialog from '@/components/accounting/CodebookImportDialog.vue'
import { codebookTransferApi } from '@/api/codebookTransfer'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

// V ukázce je zakládání analytiky vidět a formulář jde projít celý, jen se neuloží —
// demo role má na `accounting` jen čtení, takže canWrite() by tlačítko schoval.
const canCreateAccount = computed(() => auth.canWrite('accounting') || auth.isDemo)

const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const error = ref('')
const showInactive = ref(false)
const importOpen = ref(false)

// Účtové třídy 0–7 (směrná účtová osnova), + mimobilanční / závěrkové.
const CLASS_KEYS = ['0', '1', '2', '3', '4', '5', '6', '7'] as const

function accountClass(a: ChartAccount): string {
  const c = (a.account_code || '').charAt(0)
  if (CLASS_KEYS.includes(c as (typeof CLASS_KEYS)[number])) return c
  if (a.account_type === 'offbalance') return 'offbalance'
  if (a.account_type === 'closing') return 'closing'
  return 'other'
}

interface AccountGroup {
  key: string
  label: string
  rows: ChartAccount[]
}

const groups = computed<AccountGroup[]>(() => {
  const buckets: Record<string, ChartAccount[]> = {}
  for (const a of accounts.value) {
    const k = accountClass(a)
    ;(buckets[k] ||= []).push(a)
  }
  const order = [...CLASS_KEYS, 'offbalance', 'closing', 'other']
  return order
    .filter(k => buckets[k]?.length)
    .map(k => ({
      key: k,
      label: t(`accounting.accounts.class.${k}`),
      rows: buckets[k].sort((a, b) => a.account_code.localeCompare(b.account_code)),
    }))
})

// Syntetické účty = možní rodiče pro novou analytiku.
const syntheticAccounts = computed(() =>
  accounts.value.filter(a => a.is_synthetic && a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

async function load() {
  loading.value = true
  try {
    accounts.value = await accountingApi.listAccounts({ includeInactive: showInactive.value })
  } finally {
    loading.value = false
  }
}
onMounted(load)

const showForm = ref(false)
useHotkey('escape', () => { if (showForm.value) showForm.value = false })

const form = reactive({
  parent_id: null as number | null,
  account_code: '',
  name: '',
})

function openCreate() {
  error.value = ''
  Object.assign(form, { parent_id: syntheticAccounts.value[0]?.id ?? null, account_code: '', name: '' })
  showForm.value = true
}

async function save() {
  error.value = ''
  // Až po otevření formuláře: v ukázce má jít vyplnit a odeslat, jen se neuloží.
  if (blockDemoMutation()) { showForm.value = false; return }
  if (!form.parent_id) { error.value = t('accounting.accounts.parent_required'); return }
  if (!form.account_code.trim() || !form.name.trim()) { error.value = t('accounting.accounts.code_name_required'); return }
  try {
    await accountingApi.createAccount({
      parent_id: form.parent_id,
      account_code: form.account_code.trim(),
      name: form.name.trim(),
    })
    showForm.value = false
    toast.success(t('common.created'))
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

async function toggleActive(a: ChartAccount) {
  const next = !a.is_active
  if (next === false && !confirm(t('accounting.accounts.deactivate_confirm', { code: a.account_code }))) return
  try {
    await accountingApi.updateAccount(a.id, { is_active: next })
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

/** Proklik na kartu účtu (kmen + analytiky + zůstatky) — odtud dál na opis/knihu/deník. */
function detailLink(a: ChartAccount) {
  return { name: 'accounting-account-detail', params: { accountId: a.id } }
}

const navigateRow = useRowLink()
function openDetail(a: ChartAccount, e: MouseEvent) {
  navigateRow(detailLink(a), e)
}

function typeLabel(type: string): string {
  return t(`accounting.accounts.type.${type}`)
}
function sideLabel(side: string | null): string {
  if (!side) return '—'
  return t(`accounting.journal.side.${side}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.accounts.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.accounts.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <label class="flex items-center gap-1.5 text-sm text-neutral-600 mr-1">
          <input v-model="showInactive" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="load" />
          {{ t('accounting.accounts.show_inactive') }}
        </label>
        <button @click="codebookTransferApi.download('accounts')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('codebookTransfer.export') }}
        </button>
        <button v-if="auth.canWrite('accounting')" @click="importOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('codebookTransfer.import') }}
        </button>
        <button v-if="canCreateAccount" @click="openCreate" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.accounts.new') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="accounts.length === 0" boxed icon="doc"
      :title="t('accounting.accounts.empty')"
      :cta="canCreateAccount ? t('accounting.accounts.new') : undefined"
      @action="openCreate" />

    <div v-else class="space-y-5">
      <div v-for="g in groups" :key="g.key" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-3 py-2 bg-neutral-50 border-b border-neutral-100 text-xs font-bold uppercase tracking-wide text-neutral-500">
          {{ g.label }}
        </div>
        <!-- Desktop tabulka -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.accounts.code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.accounts.name') }}</th>
                <th class="px-3 py-2 text-left font-medium w-32">{{ t('accounting.accounts.type_col') }}</th>
                <th class="px-3 py-2 text-center font-medium w-24">{{ t('accounting.accounts.normal_side') }}</th>
                <th class="px-3 py-2 text-center font-medium w-20">{{ t('users.active') }}</th>
                <th class="px-3 py-2 w-24"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in g.rows" :key="a.id" class="cursor-pointer hover:bg-neutral-50"
                :class="{ 'opacity-50': !a.is_active }"
                @click="openDetail(a, $event)" @auxclick.prevent="openDetail(a, $event)">
                <td class="px-3 py-2 font-mono" :class="a.is_synthetic ? 'font-semibold' : 'text-neutral-500'">
                  <span v-if="!a.is_synthetic" class="text-neutral-300 mr-1">└</span>
                  <RouterLink :to="detailLink(a)" class="row-link text-primary-600 hover:text-primary-700 hover:underline"
                    :title="t('accounting.accounts.open_detail')" @click.stop>
                    {{ a.account_code }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2">{{ a.name }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ typeLabel(a.account_type) }}</td>
                <td class="px-3 py-2 text-center text-xs">
                  <span v-if="a.normal_side" class="px-1.5 py-0.5 rounded font-medium"
                    :class="a.normal_side === 'debit' ? 'bg-primary-100 text-primary-700' : 'bg-warning-50 text-warning-600'">
                    {{ sideLabel(a.normal_side) }}
                  </span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="a.is_active" class="text-success-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right">
                  <button v-if="auth.canWrite('accounting') && !a.is_synthetic" @click.stop="toggleActive(a)"
                    class="cursor-pointer text-xs"
                    :class="a.is_active ? 'text-danger-500 hover:text-danger-600' : 'text-primary-600 hover:text-primary-700'">
                    {{ a.is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.reactivate') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Mobile karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="a in g.rows" :key="`m-${a.id}`" class="p-3 space-y-1" :class="{ 'opacity-50': !a.is_active }">
            <div class="flex items-baseline justify-between gap-2">
              <RouterLink :to="detailLink(a)" class="font-mono text-primary-600"
                :class="a.is_synthetic ? 'font-semibold' : ''">{{ a.account_code }}</RouterLink>
              <span class="text-xs text-neutral-500">{{ typeLabel(a.account_type) }}</span>
            </div>
            <div class="text-neutral-900">{{ a.name }}</div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-neutral-500">{{ t('accounting.accounts.normal_side') }}: {{ sideLabel(a.normal_side) }}</span>
              <button v-if="auth.canWrite('accounting') && !a.is_synthetic" @click="toggleActive(a)"
                class="cursor-pointer" :class="a.is_active ? 'text-danger-500' : 'text-primary-600'">
                {{ a.is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.reactivate') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: nová analytika -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.accounts.new_title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.accounts.new_hint') }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.parent') }}</label>
            <select v-model="form.parent_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="s in syntheticAccounts" :key="s.id" :value="s.id">{{ s.account_code }} — {{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.code') }}</label>
            <input v-model="form.account_code" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
              :placeholder="t('accounting.accounts.code_placeholder')" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.name') }}</label>
            <input v-model="form.name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="save" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('common.create') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <CodebookImportDialog v-model="importOpen" kind="accounts" :title="t('codebookTransfer.title_accounts')" @imported="load" />
  </div>
</template>
