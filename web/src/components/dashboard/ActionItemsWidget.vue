<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type ActionItem, type ActionItemsResult } from '@/api/crm'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const actionItems = ref<ActionItemsResult | null>(null)
const openMenuIdx = ref<number | null>(null)

function toggleMenu(idx: number) {
  openMenuIdx.value = openMenuIdx.value === idx ? null : idx
}

async function dismissItem(itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') {
  try {
    await crmApi.dismissActionItem(itemType, mode)
    openMenuIdx.value = null
    await reload()
    toast.success(t('crm.action_items.dismissed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function restoreAllDismissed() {
  try {
    const r = await crmApi.restoreAllActionItems()
    await reload()
    toast.success(t('crm.action_items.restored_n', { n: r.restored }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

/**
 * Doplatek DPPO se dotahuje ZVLÁŠŤ. Je to živá projekce celoročního účetnictví
 * (naměřeno 444 z 473 ms původního feedu), takže kdyby byl součástí action-items,
 * čekal by dashboard půl vteřiny na jednu dlaždici. Takhle se seznam vykreslí hned
 * a daňová položka do něj přibude, až dopočítá.
 *
 * Selhání je tiché a bez následků — zbytek seznamu je už zobrazený.
 */
async function loadTaxBalance() {
  try {
    const r = await crmApi.actionItemTaxBalance()
    if (r.item && actionItems.value) {
      appendItem(r.item)
    }
  } catch {
    // dlaždice se prostě neobjeví
  }
}

function appendItem(item: ActionItem) {
  if (!actionItems.value) return
  // Ochrana proti dvojímu vložení (dismiss/restore znovu načte seznam i dlaždici).
  if (actionItems.value.items.some(i => i.type === item.type)) return
  actionItems.value.items.push(item)
  actionItems.value.total += 1
}

async function reload() {
  actionItems.value = await crmApi.actionItems()
  await loadTaxBalance()
}

onMounted(async () => {
  try {
    actionItems.value = await crmApi.actionItems()
  } catch {
    // tichý fail — widget se prostě nezobrazí
    return
  }
  void loadTaxBalance()
})
</script>

<template>
  <!-- ═══ Action items widget (daily TODO) ═══ -->
  <!-- Nejcennější widget na dashboardu (co mám dneska udělat) měl doteď vzhled
       nejtišší karty. Zvednutá plocha + brand rámeček mu dávají váhu, kterou
       jeho obsah zaslouží. `to-white` bylo navíc v dark módu doslova bílé —
       gradient musí končit v tokenu plochy, ne v natvrdo zapsané barvě. -->
  <div v-if="actionItems && actionItems.total > 0" class="bg-surface-raised border border-primary-500/25 rounded-xl shadow-md overflow-hidden">
    <header class="px-5 py-3.5 border-b border-neutral-200 flex items-center justify-between gap-3 bg-gradient-to-r from-primary-50 to-surface">
      <h3 class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-[0.12em] text-primary-700">
        <span aria-hidden="true">⚡</span>
        {{ t('crm.action_items.title') }}
        <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 bg-primary-600 text-white rounded-full text-xs font-semibold tabular-nums">{{ actionItems.total }}</span>
      </h3>
      <button v-if="actionItems.dismissed_count > 0 && auth.canWrite('dashboard')" type="button" @click="restoreAllDismissed"
        class="text-xs text-neutral-500 hover:text-primary-600 underline decoration-dotted">
        {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
      </button>
    </header>
    <div class="divide-y divide-neutral-100">
      <div v-for="(item, idx) in actionItems.items" :key="idx"
        class="relative px-5 py-3 hover:bg-neutral-50">
        <div class="flex items-center justify-between">
        <RouterLink :to="item.link" class="flex items-center gap-3 flex-1 min-w-0">
          <span :class="['inline-block w-2.5 h-2.5 rounded-full shrink-0',
            item.severity === 'high' ? 'bg-danger-500' :
            item.severity === 'medium' ? 'bg-warning-500' : 'bg-neutral-400']"></span>
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-700">{{ item.title }}</div>
            <div class="text-xs text-neutral-500 mt-0.5">{{ item.hint }}</div>
          </div>
        </RouterLink>
        <div class="flex items-center gap-1 ml-3 shrink-0">
          <RouterLink :to="item.link" class="text-neutral-400 hover:text-primary-700 p-1 transition-transform hover:translate-x-0.5" :title="t('crm.action_items.go_to')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
          </RouterLink>
          <button v-if="auth.canWrite('dashboard')" type="button" @click.stop="toggleMenu(idx)"
            class="text-neutral-400 hover:text-neutral-700 p-1 rounded hover:bg-neutral-100"
            :title="t('crm.action_items.dismiss')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
          </button>
          <div v-if="(openMenuIdx === idx) && auth.canWrite('dashboard')"
            class="absolute right-3 top-12 z-20 bg-surface border border-neutral-200 rounded-md shadow-lg py-1 w-[280px]"
            @click.stop>
            <div class="px-3 py-1.5 text-xs uppercase tracking-wide text-neutral-500 font-semibold border-b border-neutral-100">
              {{ t('crm.action_items.dismiss_title') }}
            </div>
            <button type="button" @click="dismissItem(item.type, 'day')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_day') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_day_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'week')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_week') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_week_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'historical')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_historical') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_historical_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'forever')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-danger-600 border-t border-neutral-100">
              {{ t('crm.action_items.dismiss_forever') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_forever_hint') }}</div>
            </button>
          </div>
        </div>
        </div>
        <!-- Rozpad (FV / PF / banka) — jen u položek s breakdown (unbooked_documents) -->
        <div v-if="item.breakdown && item.breakdown.length"
          class="flex flex-wrap gap-2 mt-2 ml-[1.375rem]">
          <RouterLink v-for="b in item.breakdown" :key="b.key" :to="b.link"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-neutral-100 hover:bg-primary-50 text-xs text-neutral-600 hover:text-primary-700 whitespace-nowrap">
            <span>{{ t('crm.action_items.breakdown_' + b.key) }}</span>
            <span class="font-semibold">{{ b.count }}</span>
          </RouterLink>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ Standalone restore hint — pro případ že total=0 ale jsou skryté ═══ -->
  <div v-else-if="actionItems && actionItems.dismissed_count > 0"
    class="bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2 flex items-center justify-between text-sm">
    <span class="text-neutral-500">
      {{ t('crm.action_items.all_clear_n_hidden', { n: actionItems.dismissed_count }) }}
    </span>
    <button v-if="auth.canWrite('dashboard')" type="button" @click="restoreAllDismissed"
      class="text-xs text-primary-600 hover:text-primary-700 underline decoration-dotted">
      {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
    </button>
  </div>
</template>
