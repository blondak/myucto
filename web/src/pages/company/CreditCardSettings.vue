<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import {
  creditCardsApi,
  CREDIT_CARD_SETTINGS_FIELDS,
  type CreditCardSettingsField,
  type CreditCardSettingsPayload,
  type CreditCardSettingsResponse,
} from '@/api/creditCards'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const data = ref<CreditCardSettingsResponse | null>(null)
const form = reactive<Record<CreditCardSettingsField, number | null>>(
  Object.fromEntries(CREDIT_CARD_SETTINGS_FIELDS.map(f => [f, null])) as Record<CreditCardSettingsField, number | null>,
)

const canConfigure = computed(() => auth.canWrite('bank.post') && !!data.value?.double_entry)

function apply(r: CreditCardSettingsResponse) {
  data.value = r
  for (const f of CREDIT_CARD_SETTINGS_FIELDS) form[f] = r.settings[`${f}_account_id`]
}

async function load() {
  loading.value = true
  try {
    apply(await creditCardsApi.settings())
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.settings.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save() {
  saving.value = true
  try {
    const payload: CreditCardSettingsPayload = {}
    for (const f of CREDIT_CARD_SETTINGS_FIELDS) payload[`${f}_account_id`] = form[f]
    apply(await creditCardsApi.saveSettings(payload))
    toast.success(t('credit_cards.settings.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.settings.save_failed')))
  } finally {
    saving.value = false
  }
}

function optionsFor(field: CreditCardSettingsField) {
  const prefixes = data.value?.allowed_prefixes[field] ?? []
  return (data.value?.account_options ?? []).filter(a => prefixes.some(p => a.account_code.startsWith(p)))
}

/** Účet, na který se opravdu účtuje (zvolený, jinak výchozí) - pro schéma zápisů. */
function effectiveCode(field: CreditCardSettingsField): string {
  const id = form[field]
  const chosen = id !== null ? data.value?.account_options.find(a => a.id === id)?.account_code : undefined
  return chosen ?? data.value?.defaults[field] ?? ''
}

const scheme = computed(() => [
  { key: 'purchase', entry: '321 / 231.x' },
  { key: 'purchase_rule', entry: '5xx / 231.x' },
  { key: 'refund', entry: '231.x / 321' },
  { key: 'opening', entry: `${effectiveCode('opening')} / 231.x` },
  { key: 'repayment_own', entry: '231.x / 261 · 261 / 221.x' },
  { key: 'repayment', entry: `231.x / ${effectiveCode('repayment')}` },
  { key: 'interest', entry: `${effectiveCode('interest')} / 231.x` },
  { key: 'fee', entry: `${effectiveCode('fee')} / 231.x` },
  { key: 'cash', entry: `${effectiveCode('cash')} / 231.x` },
  { key: 'reward', entry: `231.x / ${effectiveCode('reward')}` },
])

const actions = computed<ActionItem[]>(() => [
  {
    key: 'save', label: t('credit_cards.settings.save'), icon: 'check', tier: 'primary', variant: 'success',
    run: () => { void save() }, loading: saving.value, disabled: saving.value || loading.value,
    show: canConfigure.value,
  },
])

const SELECT = 'h-9 w-full px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50'
</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <div v-if="!data.double_entry" class="mb-4 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
        {{ t('credit_cards.settings.tax_evidence_notice') }}
      </div>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('credit_cards.settings.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 max-w-3xl">{{ t('credit_cards.settings.description') }}</p>
          </div>
          <ActionBar :actions="actions" />
        </div>

        <form class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="save()">
          <label v-for="f in CREDIT_CARD_SETTINGS_FIELDS" :key="f" class="block text-sm font-medium text-neutral-700">
            {{ t(`credit_cards.settings.field_${f}`) }}
            <select v-model="form[f]" :class="SELECT" class="mt-1" :disabled="!canConfigure" :data-testid="`cc-setting-${f}`">
              <option :value="null">{{ t('credit_cards.settings.default_account', { code: data.defaults[f] }) }}</option>
              <option v-for="a in optionsFor(f)" :key="a.id" :value="a.id">{{ a.account_code }} - {{ a.name }}{{ a.non_deductible ? ` (${t('credit_cards.settings.non_deductible')})` : '' }}</option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t(`credit_cards.settings.help_${f}`) }}</span>
          </label>
        </form>
      </section>

      <section class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5">
        <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('credit_cards.settings.scheme_title') }}</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.settings.scheme_case') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('credit_cards.settings.scheme_entry') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in scheme" :key="row.key">
                <td class="px-3 py-2">{{ t(`credit_cards.settings.scheme_${row.key}`) }}</td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ row.entry }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('credit_cards.settings.scheme_note') }}</p>
      </section>
    </template>
  </div>
</template>
