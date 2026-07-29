<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { licenseApi, type LicenseStatus, type LicenseStateKind, type UpgradeQuote } from '@/api/license'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const status = ref<LicenseStatus | null>(null)
const loading = ref(true)
const errorMsg = ref<string | null>(null)

const keyInput = ref('')
const activating = ref(false)
const deactivating = ref(false)
const activateError = ref<string | null>(null)
// Klíč obsazený na jiné instalaci — nabídneme přenos (takeover).
const alreadyBound = ref(false)
const transfersRemaining = ref<number | null>(null)

const isAdmin = computed(() => auth.isSuperadmin)

// In-place navýšení počtu uživatelů (poměrný doplatek z uložené karty).
const upgradeUsers = ref<number>(1)
const quoting = ref(false)
const upgrading = ref(false)
const quote = ref<UpgradeQuote | null>(null)
const upgradeError = ref<string | null>(null)
const upgradeSuccess = ref<string | null>(null)

/** Navýšení má smysl jen u aktivního placeného předplatného (aktivní klíč). */
const canUpgrade = computed(() => {
  const s = status.value
  return !!s && !!s.license_key_masked && (s.state === 'active' || s.state === 'overage')
})

/** Přečerpání rozsahu licence — víc aktivních uživatelů / firem, než licencuje klíč. */
const usersOverage = computed(() => {
  const s = status.value
  return !!s && s.users_licensed > 0 && s.users_active > s.users_licensed
})
const companiesOverage = computed(() => {
  const s = status.value
  return !!s && s.max_companies !== null && s.companies_active > s.max_companies
})
const hasOverage = computed(() => usersOverage.value || companiesOverage.value)

/** Barevný akcent karty stavu dle stavu licence. */
const STATE_STYLE: Record<LicenseStateKind, { card: string; badge: string }> = {
  active:        { card: 'border-success-300 bg-success-50/40', badge: 'bg-success-100 text-success-700' },
  trial:         { card: 'border-primary-300 bg-primary-50/40',  badge: 'bg-primary-100 text-primary-700' },
  overage:       { card: 'border-warning-300 bg-warning-50/40',  badge: 'bg-warning-100 text-warning-800' },
  trial_expired: { card: 'border-danger-300 bg-danger-50/40',    badge: 'bg-danger-100 text-danger-700' },
  degraded:      { card: 'border-danger-300 bg-danger-50/40',    badge: 'bg-danger-100 text-danger-700' },
}

const stateStyle = computed(() => status.value ? STATE_STYLE[status.value.state] : STATE_STYLE.trial)

const tierLabel = computed(() => {
  const tier = status.value?.tier
  if (!tier) return '—'
  const map: Record<string, string> = {
    single: t('license.tier_single'),
    multi10: t('license.tier_multi10'),
    unlimited: t('license.tier_unlimited'),
  }
  return map[tier] ?? tier
})

/** CTA URL na objednávku s předvyplněnou instalací, počty a fakturačními údaji firmy.
 *  Vše jen jako výchozí hodnoty — zákazník je na webu může změnit (tam proběhne ARES). */
const buyUrl = computed(() => {
  const s = status.value
  if (!s) return 'https://myucto.cz/objednavka'
  const base = s.buy_url || 'https://myucto.cz/objednavka'
  const c = s.company
  const raw: Record<string, string> = {
    instance: s.instance_id,
    users: String(s.users_active),
    companies: String(s.companies_active),
    company: c?.name ?? '',
    ico: c?.ic ?? '',
    dic: c?.dic ?? '',
    street: c?.street ?? '',
    city: c?.city ?? '',
    zip: c?.zip ?? '',
    email: c?.email ?? '',
  }
  const params = new URLSearchParams()
  for (const [k, v] of Object.entries(raw)) {
    if (v !== '') params.set(k, v)
  }
  return `${base}${base.includes('?') ? '&' : '?'}${params.toString()}`
})

function fmtDate(ts: number | null): string {
  if (!ts) return '—'
  return new Date(ts * 1000).toLocaleDateString()
}
function fmtDateTime(s: string | null): string {
  if (!s) return '—'
  try { return new Date(s).toLocaleString() } catch { return s }
}

async function load() {
  loading.value = true
  errorMsg.value = null
  try {
    status.value = await licenseApi.status()
    // Výchozí cílový počet = aktuální aktivní počet uživatelů.
    upgradeUsers.value = Math.max(status.value.users_active, 1)
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Nepodařilo se načíst stav licence.'
  } finally {
    loading.value = false
  }
}

function fmtAmount(amount: number | null, currency: string | null): string {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'CZK' }).format(amount)
  } catch {
    return `${amount} ${currency ?? ''}`.trim()
  }
}

function upgradeErrMsg(e: unknown): string {
  const err = e as { response?: { data?: { error?: { message?: string } } } }
  return err.response?.data?.error?.message ?? t('license.upgrade_failed')
}

async function calcQuote() {
  if (quoting.value) return
  const n = Math.floor(Number(upgradeUsers.value))
  if (!n || n < 1) return
  quoting.value = true
  upgradeError.value = null
  upgradeSuccess.value = null
  quote.value = null
  try {
    quote.value = await licenseApi.upgradeQuote(n)
  } catch (e: unknown) {
    upgradeError.value = upgradeErrMsg(e)
  } finally {
    quoting.value = false
  }
}

async function doUpgrade() {
  if (upgrading.value || !quote.value) return
  const n = quote.value.new_users
  if (!confirm(t('license.upgrade_confirm', { n }))) return
  upgrading.value = true
  upgradeError.value = null
  upgradeSuccess.value = null
  try {
    const res = await licenseApi.upgrade(n)
    status.value = res.state
    quote.value = null
    upgradeSuccess.value = t('license.upgrade_success', { n: res.new_users })
    upgradeUsers.value = Math.max(res.state.users_active, res.new_users)
    // Obnov /me, ať zmizí overage banner a projeví se nový limit.
    await auth.refresh()
  } catch (e: unknown) {
    upgradeError.value = upgradeErrMsg(e)
  } finally {
    upgrading.value = false
  }
}

async function activate(takeover = false) {
  const key = keyInput.value.trim()
  if (!key || activating.value) return
  // Přenos vazby z jiné instalace potvrdíme (počítá se do limitu 2/30 dní).
  if (takeover && !confirm(t('license.takeover_confirm'))) return
  activating.value = true
  activateError.value = null
  try {
    status.value = await licenseApi.activate(key, takeover)
    keyInput.value = ''
    alreadyBound.value = false
    transfersRemaining.value = null
    // Obnov /me, ať zmizí bannery a zpřístupní se komerční moduly.
    await auth.refresh()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { code?: string; message?: string; transfers_remaining?: number } } } }
    const errObj = err.response?.data?.error
    const code = errObj?.code
    if (code === 'already_bound') {
      // Klíč je aktivní jinde → nabídneme tlačítko „přenést" (takeover).
      alreadyBound.value = true
      transfersRemaining.value = typeof errObj?.transfers_remaining === 'number' ? errObj.transfers_remaining : null
      activateError.value = null
    } else {
      alreadyBound.value = false
      activateError.value = errObj?.message ?? t('license.activate_failed')
    }
  } finally {
    activating.value = false
  }
}

async function deactivate() {
  if (deactivating.value) return
  if (!confirm(t('license.deactivate_confirm'))) return
  deactivating.value = true
  errorMsg.value = null
  try {
    const res = await licenseApi.deactivate()
    status.value = res.state
    await auth.refresh()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? t('license.deactivate_failed')
  } finally {
    deactivating.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('license.purchase_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('license.purchase_subtitle') }}</p>
    </header>

    <div v-if="!isAdmin" class="rounded-md bg-warning-50 border border-warning-200 p-4 text-sm text-warning-800">
      {{ t('license.no_admin') }}
    </div>

    <div v-else-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <div v-else-if="status" class="space-y-6">
      <!-- Karta stavu -->
      <section class="rounded-lg border p-5" :class="stateStyle.card">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium" :class="stateStyle.badge">
            {{ t('license.state_' + status.state) }}
          </span>
          <span class="text-sm text-neutral-500">{{ t('license.tier') }}: <strong class="text-neutral-700">{{ tierLabel }}</strong></span>
        </div>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.users') }}</dt>
            <dd class="mt-0.5 font-medium" :class="usersOverage ? 'text-danger-600' : 'text-neutral-900'">
              {{ status.users_active }} / {{ status.users_licensed > 0 ? status.users_licensed : '∞' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.companies') }}</dt>
            <dd class="mt-0.5 font-medium" :class="companiesOverage ? 'text-danger-600' : 'text-neutral-900'">
              {{ status.companies_active }} / {{ status.max_companies === null ? '∞' : status.max_companies }}
            </dd>
          </div>
          <div v-if="status.state === 'trial'">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.trial_ends') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-medium">{{ fmtDate(status.trial_ends_at) }}</dd>
          </div>
          <div v-else>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.valid_until') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-medium">
              {{ status.perpetual ? t('license.perpetual_validity') : fmtDate(status.valid_until) }}
            </dd>
          </div>
          <div v-if="status.overage_deadline">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.overage_deadline') }}</dt>
            <dd class="mt-0.5 text-warning-800 font-medium">{{ fmtDate(status.overage_deadline) }}</dd>
          </div>
          <div v-if="status.license_key_masked">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.key') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-mono text-xs">{{ status.license_key_masked }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.last_check') }}</dt>
            <dd class="mt-0.5 text-neutral-700">
              {{ fmtDateTime(status.last_check_at) }}
              <span v-if="!status.last_check_ok" class="text-danger-600">({{ t('license.check_failed') }})</span>
            </dd>
          </div>
        </dl>

        <!-- Přečerpání rozsahu — víc aktivních uživatelů / firem, než licence pokrývá. -->
        <div v-if="hasOverage" class="mt-4 rounded-md border border-danger-300 bg-danger-50/60 p-3 text-sm text-danger-700">
          <p class="font-medium">{{ t('license.overage_title') }}</p>
          <p class="mt-1 text-danger-600">{{ t('license.overage_desc') }}</p>
          <a v-if="canUpgrade" href="#upgrade" :class="[btnFilled('danger'), 'mt-3']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ t('license.overage_cta') }}
          </a>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
          <a :href="buyUrl" target="_blank" rel="noopener" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ t('license.buy_cta') }}
          </a>
          <button
            v-if="status.license_key_masked"
            type="button" @click="deactivate" :disabled="deactivating"
            :class="btnOutline('danger')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ deactivating ? '…' : t('license.deactivate') }}
          </button>
        </div>
      </section>

      <!-- In-place navýšení počtu uživatelů -->
      <section v-if="canUpgrade" id="upgrade" class="rounded-lg border border-primary-200 bg-primary-50/30 p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.upgrade_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('license.upgrade_desc') }}</p>

        <div class="mt-3 flex flex-wrap items-end gap-3">
          <label class="text-sm">
            <span class="block text-xs uppercase tracking-wider text-neutral-500 mb-1">{{ t('license.upgrade_users_label') }}</span>
            <input
              v-model.number="upgradeUsers" type="number" min="1" step="1"
              class="w-28 h-9 px-3 text-sm rounded-md border border-neutral-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
            />
          </label>
          <button
            type="button" @click="calcQuote" :disabled="quoting || !upgradeUsers || upgradeUsers < 1"
            :class="btnOutline('primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ quoting ? t('license.upgrade_quoting') : t('license.upgrade_quote_cta') }}
          </button>
        </div>

        <!-- Kalkulace poměrného doplatku -->
        <div v-if="quote" class="mt-4 rounded-md border border-primary-300 bg-surface p-3 text-sm">
          <p class="text-neutral-900 font-medium">
            {{ t('license.upgrade_amount', { amount: fmtAmount(quote.amount, quote.currency) }) }}
          </p>
          <p class="mt-0.5 text-neutral-500">
            {{ t('license.upgrade_from_to', { from: quote.current_users ?? '—', to: quote.new_users }) }}
          </p>
          <button
            type="button" @click="doUpgrade" :disabled="upgrading"
            :class="[btnFilled('success'), 'mt-3']"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ upgrading ? t('license.upgrading') : t('license.upgrade_pay_cta') }}
          </button>
        </div>

        <div v-if="upgradeSuccess" class="mt-3 rounded-md bg-success-50 border border-success-300 p-3 text-sm text-success-700">
          {{ upgradeSuccess }}
        </div>
        <div v-if="upgradeError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ upgradeError }}
        </div>
      </section>

      <!-- Aktivace klíče -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.activate_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('license.activate_desc') }}</p>
        <form class="mt-3 flex flex-wrap items-start gap-2" @submit.prevent="activate()">
          <input
            v-model="keyInput" type="text" :placeholder="t('license.key_placeholder')"
            class="flex-1 min-w-[240px] h-9 px-3 text-sm rounded-md border border-neutral-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none font-mono"
          />
          <button type="submit" :disabled="activating || !keyInput.trim()" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ activating ? t('license.activating') : t('license.activate') }}
          </button>
        </form>

        <!-- Klíč je aktivní na jiné instalaci → nabídka přenosu (takeover). -->
        <div v-if="alreadyBound" class="mt-3 rounded-md bg-warning-50 border border-warning-300 p-3 text-sm text-warning-800">
          <p class="font-medium">{{ t('license.already_bound_title') }}</p>
          <p class="mt-1">{{ t('license.already_bound_desc') }}</p>
          <p v-if="transfersRemaining !== null" class="mt-1 text-warning-700">
            {{ t('license.transfers_remaining', { n: transfersRemaining }) }}
          </p>
          <button
            type="button" @click="activate(true)" :disabled="activating || !keyInput.trim()"
            :class="[btnFilled('warning'), 'mt-3']"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ activating ? t('license.activating') : t('license.takeover_cta') }}
          </button>
        </div>

        <div v-if="activateError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ activateError }}
        </div>
      </section>

      <p class="text-xs text-neutral-500">
        <RouterLink to="/activation/license" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.license') }}</RouterLink>
        ·
        <RouterLink to="/activation/terms" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.terms') }}</RouterLink>
      </p>
    </div>

    <div v-if="errorMsg" class="mt-4 rounded-md bg-danger-50 border border-danger-500/40 p-4 text-sm text-danger-600">
      {{ errorMsg }}
    </div>
  </div>
</template>
