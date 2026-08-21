<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { licenseApi, type ManagedLinks } from '@/api/license'

const { t, tm, rt } = useI18n()
const auth = useAuthStore()

interface Article { title: string; body: string }
const articles = tm('license.terms_articles') as unknown as Article[]

/**
 * Spravovaný provoz — krátká vsuvka do souhlasu (H-31).
 *
 * Je to souhlasová obrazovka, ne prodejní stránka: stačí, že jde o hostovanou
 * instalaci, kdo drží provoz a zálohy, a kam se kliká pro plné znění. Rozsah
 * služby patří na /hosting, ne sem.
 *
 * Adresy se berou z odpovědi serveru (`license.server_url`), ne z konstanty
 * v šabloně — jinak by testovací instalace posílala zákazníka na ostrý web.
 */
const managedLinks = ref<ManagedLinks | null>(null)
const isManaged = computed(() => auth.isManagedInstallation)

onMounted(async () => {
  if (!isManaged.value) return
  try {
    // Chyba je tady nepodstatná: bez odkazů se blok ukáže bez nich, souhlas
    // kvůli tomu nepadá.
    managedLinks.value = (await licenseApi.status()).instance?.links ?? null
  } catch { /* ponech bez odkazů */ }
})
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('license.terms_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('license.terms_subtitle') }}</p>
    </header>

    <div class="space-y-6">
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <p class="text-sm text-neutral-700 leading-relaxed">{{ t('license.terms_intro') }}</p>
        <p class="text-sm text-neutral-600 leading-relaxed mt-3">{{ t('license.terms_provider') }}</p>
      </section>

      <!-- Spravovaný provoz — jen základní fakta a odkazy na plná znění. -->
      <section
        v-if="isManaged"
        class="rounded-lg border border-primary-200 bg-primary-50/30 p-5"
        data-terms-managed
      >
        <h2 class="text-base font-semibold text-neutral-900">{{ t('hosting.terms_block_title') }}</h2>
        <p class="mt-1.5 text-sm text-neutral-700 leading-relaxed">{{ t('hosting.terms_block_desc') }}</p>
        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <a
            v-if="managedLinks?.terms" :href="managedLinks.terms" target="_blank" rel="noopener"
            class="text-primary-600 hover:text-primary-800 hover:underline font-medium"
          >{{ t('license.full_terms_link') }} →</a>
          <a
            v-if="managedLinks?.privacy" :href="managedLinks.privacy" target="_blank" rel="noopener"
            class="text-primary-600 hover:text-primary-800 hover:underline font-medium"
          >{{ t('hosting.privacy_link') }} →</a>
          <RouterLink to="/hosting" class="text-primary-600 hover:text-primary-800 hover:underline font-medium">
            {{ t('nav.hosting') }} →
          </RouterLink>
        </p>
      </section>

      <section
        v-for="(article, i) in articles" :key="i"
        class="rounded-lg border border-neutral-200 bg-surface p-5"
      >
        <h2 class="text-base font-semibold text-neutral-900">{{ rt(article.title) }}</h2>
        <p class="text-sm text-neutral-700 leading-relaxed mt-1.5">{{ rt(article.body) }}</p>
      </section>

      <div class="rounded-md bg-neutral-50 border border-neutral-200 p-4 text-sm text-neutral-600">
        {{ t('license.terms_summary_note') }}
        <a href="https://myucto.cz/obchodni-podminky" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800 hover:underline font-medium">
          {{ t('license.full_terms_link') }} →
        </a>
      </div>

      <p class="text-xs text-neutral-500">{{ t('license.terms_effective') }}</p>
    </div>
  </div>
</template>
