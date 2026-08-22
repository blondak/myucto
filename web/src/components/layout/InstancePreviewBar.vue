<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { instancePreview } from '@/api/instancePreview'

/**
 * Pruh „NÁHLED" nad celou aplikací.
 *
 * Náhled stavů provozu se nepromítá jen do obrazovky Předplatné a provoz —
 * mění i tečku u položky Hosting v menu a položky v „Akcích pro tebe" na
 * dashboardu. Proklikat se dá tedy jedině tak, že náhled přežije odchod
 * ze stránky, a v tu chvíli MUSÍ být vidět odkudkoli. Náhled, který se dá
 * splést se skutečností, je horší než žádný.
 *
 * ⚠️ Tři věci, které to drží bezpečné:
 *
 *  1. **Nezapne se sám.** Zapíná ho jen superadmin výslovným `?nahled=…`
 *     na /hosting (viz `Hosting.vue`).
 *  2. **Žije jen v paměti.** Žádné localStorage ani session — obnovení
 *     stránky ho zahodí, takže zapomenutý náhled nepřežije ani zavření
 *     záložky.
 *  3. **Nic neodemyká.** Mění POUZE zobrazení; na `auth.license`, podle které
 *     se rozhoduje přístup k modulům, nesahá.
 */
const { t } = useI18n()
</script>

<template>
  <div
    v-if="instancePreview.isActive.value"
    class="w-full bg-accent-600 text-white"
    role="status"
    data-instance-preview-bar
  >
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-1.5 text-sm">
      <span class="rounded bg-white/25 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wider">
        {{ t('hosting.preview_badge') }}
      </span>
      <span class="min-w-0 flex-1 font-medium">{{ t('hosting.preview_warning') }}</span>
      <RouterLink
        :to="{ path: '/hosting' }"
        class="shrink-0 whitespace-nowrap rounded-md bg-white/15 px-3 py-1 font-medium underline underline-offset-2 hover:bg-white/25"
      >
        {{ t('hosting.preview_stop') }}
      </RouterLink>
    </div>
  </div>
</template>
