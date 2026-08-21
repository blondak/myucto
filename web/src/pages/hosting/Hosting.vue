<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { licenseApi, type LicenseStatus } from '@/api/license'
import { formatQuotaBytes } from '@/api/storageQuota'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Hosting — shrnutí PROVOZU spravované instalace (H-31).
 *
 * Záměrně to není druhá obrazovka aktivace. Aktivace (`/activation/purchase`)
 * řeší LICENCI: klíč, navýšení uživatelů, zrušení obnovy. Tady se odpovídá na
 * otázku „co mám zaplacené a v jakém je to stavu" — tarif, místo, platnost,
 * co provoz zahrnuje a kam napsat. Kde se to potkává, odkazujeme tam a zpátky,
 * místo abychom tutéž agendu obsluhovali dvakrát.
 *
 * ⚠️ Nesmí se tu slíbit, co nemáme: samoobslužná obnova k datu, vlastní doména,
 * SSH ani přístup do databáze. Seznam „co je / co není" proto vede přes sdílené
 * překlady `license.managed_included` / `managed_excluded` — jediné místo, kde
 * je ten slib napsaný.
 */
const { t, te, tm, rt } = useI18n()

const status = ref<LicenseStatus | null>(null)
const loading = ref(true)
const errorMsg = ref<string | null>(null)

const instance = computed(() => status.value?.instance ?? null)
const storage = computed(() => instance.value?.storage ?? null)
const billing = computed(() => instance.value?.billing ?? null)
const links = computed(() => instance.value?.links ?? null)

/** Self-hosted: blok `instance` v odpovědi vůbec není. */
const isManaged = computed(() => instance.value !== null)

/**
 * ⚠️ Tři stavy místa, které se nesmí slít (zrcadlo obrazovky aktivace):
 *  - `unmeasured` — ještě se neměřilo. NENÍ to nula.
 *  - `unknown_quota` — víme kolik, ne z kolika → žádný pruh, žádná procenta.
 *  - `known` — teprve tady má poměr smysl.
 */
const storageMode = computed<'unmeasured' | 'unknown_quota' | 'known'>(() => {
  const s = storage.value
  if (!s || !s.measured || s.usage_bytes === null) return 'unmeasured'
  if (s.quota_bytes === null || s.percent === null) return 'unknown_quota'
  return 'known'
})

/** Skutečné vynucení (`blocks_writes`) přebíjí poměr — instalace může být zamčená dřív. */
const storageLevel = computed<'none' | 'ok' | 'warning' | 'exhausted'>(() => {
  const s = storage.value
  if (!s) return 'none'
  if (s.blocks_writes) return 'exhausted'
  if (storageMode.value !== 'known' || s.percent === null) return 'none'
  if (s.percent >= s.read_only_percent) return 'exhausted'
  if (s.percent >= s.warn_percent) return 'warning'
  return 'ok'
})

const STORAGE_STYLE: Record<'none' | 'ok' | 'warning' | 'exhausted', { card: string; bar: string }> = {
  none:      { card: 'border-neutral-200 bg-surface',       bar: 'bg-neutral-300' },
  ok:        { card: 'border-neutral-200 bg-surface',       bar: 'bg-success-500' },
  warning:   { card: 'border-warning-300 bg-warning-50/40', bar: 'bg-warning-500' },
  exhausted: { card: 'border-danger-300 bg-danger-50/40',   bar: 'bg-danger-500' },
}
const storageStyle = computed(() => STORAGE_STYLE[storageLevel.value])

/** Přes 100 % se pruh nepřetáčí; poměr v textu zůstává pravdivý. */
const storageBarWidth = computed(() => {
  const p = storage.value?.percent
  if (storageMode.value !== 'known' || p === null || p === undefined) return 0
  return Math.max(0, Math.min(100, p))
})

const usedLabel = computed(() => formatQuotaBytes(storage.value?.usage_bytes ?? null))
const quotaLabel = computed(() => formatQuotaBytes(storage.value?.quota_bytes ?? null))

/** Tarif provozu. Neznámý kód se ukáže tak, jak přišel — nevymýšlíme mu název. */
const planLabel = computed(() => {
  const plan = instance.value?.plan
  if (!plan) return t('license.managed_plan_unknown')
  const key = `license.managed_plan_${plan}`
  return te(key) ? t(key) : plan
})

const managedSinceLabel = computed(() => {
  const raw = instance.value?.managed_since
  if (!raw) return null
  const parsed = new Date(raw)
  return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleDateString()
})

const subscription = computed(() => status.value?.subscription ?? null)
const paidUntil = computed(() => subscription.value?.valid_until ?? status.value?.valid_until ?? null)

/** Co provoz zahrnuje / co v něm není — sdílené s obrazovkou aktivace. */
const included = computed(() => (tm('license.managed_included') as unknown[]).map(i => rt(i as string)))
const excluded = computed(() => (tm('license.managed_excluded') as unknown[]).map(i => rt(i as string)))

function fmtPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} %`
}
function fmtDate(ts: number | null): string {
  return ts ? new Date(ts * 1000).toLocaleDateString() : '—'
}
function fmtDateTime(value: string | null): string {
  if (!value) return '—'
  try { return new Date(value).toLocaleString() } catch { return value }
}

async function load() {
  loading.value = true
  errorMsg.value = null
  try {
    status.value = await licenseApi.status()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? t('hosting.load_failed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('hosting.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('hosting.subtitle') }}</p>
    </header>

    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <!-- Self-hosted — stránka nemá co ukázat a nesmí nic slibovat. -->
    <div
      v-else-if="status && !isManaged"
      class="rounded-lg border border-neutral-200 bg-surface p-5 text-sm text-neutral-700"
      data-hosting-selfhosted
    >
      <p class="font-medium text-neutral-900">{{ t('hosting.self_hosted_title') }}</p>
      <p class="mt-1">{{ t('hosting.self_hosted_desc') }}</p>
      <RouterLink to="/activation/license" class="mt-3 inline-block text-primary-600 hover:text-primary-800 hover:underline">
        {{ t('nav.license') }} →
      </RouterLink>
    </div>

    <div v-else-if="status && instance" class="space-y-6" data-hosting-managed>
      <!-- Neuhrazeno — komerční moduly jsou zavřené. Stejný fakt jako červená
           linka nahoře, tady i s tím, odkud to víme (poslední kontrola). -->
      <section
        v-if="billing?.unpaid"
        class="rounded-lg border border-danger-300 bg-danger-50/60 p-5 text-sm"
        data-hosting-unpaid
      >
        <p class="font-semibold text-danger-700">{{ t('hosting.unpaid_title') }}</p>
        <p class="mt-1 text-danger-600">{{ t('hosting.unpaid_desc') }}</p>
        <p class="mt-2 text-xs text-neutral-600">
          {{ t('hosting.unpaid_source', {
            state: t('license.state_' + billing.license_state),
            checked: fmtDateTime(billing.last_check_at),
          }) }}
        </p>
        <RouterLink to="/activation/purchase" :class="[btnFilled('danger'), 'mt-3']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ t('hosting.unpaid_cta') }}
        </RouterLink>
      </section>

      <!-- Rozsah provozu -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.service_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('license.managed_intro') }}</p>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_plan') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ planLabel }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_users') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">
              {{ status.users_active }} / {{ status.users_licensed > 0 ? status.users_licensed : '∞' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_valid_until') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">
              <template v-if="status.state === 'trial'">{{ fmtDate(status.trial_ends_at) }}</template>
              <template v-else-if="status.perpetual">{{ t('license.perpetual_validity') }}</template>
              <template v-else>{{ fmtDate(paidUntil) }}</template>
            </dd>
          </div>
          <div v-if="managedSinceLabel">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_since') }}</dt>
            <dd class="mt-0.5 text-neutral-700">{{ managedSinceLabel }}</dd>
          </div>
          <div v-if="subscription">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.renewal_title') }}</dt>
            <dd class="mt-0.5 font-medium" :class="subscription.auto_renew ? 'text-success-700' : 'text-warning-800'">
              {{ subscription.auto_renew ? t('license.renewal_on') : t('license.renewal_off') }}
            </dd>
          </div>
        </dl>

        <p class="mt-4 text-xs text-neutral-500">
          {{ t('hosting.subscription_managed_elsewhere') }}
          <RouterLink to="/activation/purchase" class="text-primary-600 hover:text-primary-800 hover:underline">
            {{ t('nav.purchase_subscription') }}
          </RouterLink>
        </p>
      </section>

      <!-- Obsazené místo -->
      <section class="rounded-lg border p-5" :class="storageStyle.card" data-hosting-storage>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.managed_storage_title') }}</h2>

        <p v-if="storageMode === 'unmeasured'" class="mt-2 text-sm text-neutral-600">
          {{ t('license.managed_storage_unmeasured') }}
        </p>

        <template v-else-if="storageMode === 'unknown_quota'">
          <p class="mt-2 text-2xl font-semibold text-neutral-900">{{ usedLabel }}</p>
          <p class="mt-1 text-sm text-neutral-600">{{ t('license.managed_storage_quota_unknown') }}</p>
        </template>

        <template v-else>
          <p class="mt-2 text-sm text-neutral-700">
            <span class="text-2xl font-semibold text-neutral-900">{{ usedLabel }}</span>
            <span class="text-neutral-500"> / {{ quotaLabel }}</span>
            <span class="ml-2 font-medium">{{ fmtPercent(storage?.percent) }}</span>
          </p>
          <div
            class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-neutral-200"
            role="progressbar" :aria-valuenow="Math.round(storageBarWidth)" aria-valuemin="0" aria-valuemax="100"
          >
            <div class="h-full rounded-full transition-all" :class="storageStyle.bar" :style="{ width: storageBarWidth + '%' }"></div>
          </div>
        </template>

        <p v-if="storage?.measured_at" class="mt-2 text-xs text-neutral-500">
          {{ t('license.managed_storage_measured_at', { at: fmtDateTime(storage?.measured_at ?? null) }) }}
        </p>

        <div v-if="storageLevel === 'exhausted'" class="mt-4 rounded-md border border-danger-300 bg-danger-50/60 p-3 text-sm text-danger-700">
          <p class="font-medium">{{ t('license.managed_storage_exhausted_title') }}</p>
          <p class="mt-1 text-danger-600">{{ t('license.managed_storage_exhausted_desc') }}</p>
        </div>
        <div v-else-if="storageLevel === 'warning'" class="mt-4 rounded-md border border-warning-300 bg-warning-50/60 p-3 text-sm text-warning-800">
          <p class="font-medium">{{ t('license.managed_storage_warning_title') }}</p>
          <p class="mt-1">{{ t('license.managed_storage_warning_desc', { percent: storage?.read_only_percent ?? 100 }) }}</p>
        </div>
      </section>

      <!-- Co provoz zahrnuje a co ne -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.scope_title') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_included_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-700">
              <li v-for="(item, i) in included" :key="'inc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_excluded_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-600">
              <li v-for="(item, i) in excluded" :key="'exc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
        <p class="mt-4 text-xs text-neutral-500">{{ t('hosting.restore_note') }}</p>
      </section>

      <!-- Kam se obrátit. Odkaz kreslíme JEN když adresu známe — mrtvé tlačítko
           je horší než žádné. -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.links_title') }}</h2>
        <div class="mt-4 flex flex-wrap gap-2">
          <a
            v-if="links?.expand_storage" :href="links.expand_storage" target="_blank" rel="noopener"
            :class="btnFilled(storageLevel === 'exhausted' ? 'danger' : 'primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.box" /></svg>
            {{ t('hosting.expand_storage_cta') }}
          </a>
          <a
            v-if="links?.subscription" :href="links.subscription" target="_blank" rel="noopener"
            :class="btnOutline('primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('license.managed_subscription_cta') }}
          </a>
          <a
            v-if="links?.support" :href="links.support" target="_blank" rel="noopener"
            :class="btnOutline('warning')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
            {{ t('hosting.report_outage_cta') }}
          </a>
        </div>
        <p v-if="!links?.subscription && !links?.expand_storage" class="mt-3 text-sm text-neutral-600">
          {{ t('license.managed_subscription_contact') }}
        </p>
      </section>

      <p class="text-xs text-neutral-500">
        <RouterLink to="/activation/purchase" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.purchase_subscription') }}</RouterLink>
        ·
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
