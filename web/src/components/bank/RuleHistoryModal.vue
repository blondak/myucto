<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { bankPostingApi, type BankPostingRule, type RuleHistory } from '@/api/bankPosting'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'

const props = defineProps<{ rule: BankPostingRule }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const history = ref<RuleHistory | null>(null)
const failed = ref(false)
const page = ref(1)
const perPage = 25

async function load() {
  failed.value = false
  try {
    const result = await bankPostingApi.ruleHistory(props.rule.id, page.value, perPage)
    if (result.events.length === 0 && result.corrections.length === 0 && result.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(result.total / result.per_page))
      return
    }
    history.value = result
  } catch { failed.value = true }
}
onMounted(load)
watch(page, load)

function eventText(event: RuleHistory['events'][number]): string {
  const key = `automation.rules.event.${event.event_type}`
  if (event.event_type === 'rule_demoted') {
    const reason = t(`automation.rules.demote_reason.${event.reason || 'manual'}`)
    return t(key, { reason })
  }
  return t(key)
}
</script>

<template>
  <Modal :title="t('automation.rules.history_title', { name: rule.name })" width-class="max-w-4xl" @close="emit('close')">
    <p v-if="!history && !failed" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="failed" class="rounded bg-danger-50 p-3 text-sm text-danger-600">{{ t('common.error') }}</p>
    <div v-else-if="history" class="space-y-6">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded border border-neutral-200 p-3"><div class="text-xs text-neutral-500">{{ t('bank.posting.hits', { count: history.stats.hit_count }) }}</div><strong>{{ history.stats.hit_count }}</strong></div>
        <div class="rounded border border-neutral-200 p-3"><div class="text-xs text-neutral-500">{{ t('automation.rules.clean_confirmations') }}</div><strong>{{ history.stats.approved_streak }}/5</strong></div>
        <div class="rounded border border-neutral-200 p-3"><div class="text-xs text-neutral-500">{{ t('automation.rules.corrections') }}</div><strong>{{ history.stats.override_count }}</strong></div>
        <div class="rounded border border-neutral-200 p-3"><div class="text-xs text-neutral-500">{{ t('automation.rules.success_rate') }}</div><strong>{{ Math.round(history.stats.success_rate * 100) }} %</strong></div>
      </div>

      <section v-if="history.events.length || history.total === 0">
        <h3 class="mb-3 font-medium">{{ t('automation.rules.timeline') }}</h3>
        <div v-if="history.events.length" class="space-y-3 border-l-2 border-primary-200 pl-4">
          <div v-for="event in history.events" :key="event.id" class="relative">
            <span class="absolute -left-[1.32rem] top-1 h-2.5 w-2.5 rounded-full bg-primary-500"></span>
            <div class="text-sm font-medium">{{ eventText(event) }}</div>
            <div class="text-xs text-neutral-500">{{ formatDate(event.created_at) }} · {{ event.created_by_name || t('automation.rules.system') }}</div>
          </div>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('automation.rules.history_empty') }}</p>
      </section>

      <section v-if="history.corrections.length || history.total === 0">
        <h3 class="mb-3 font-medium">{{ t('automation.rules.corrections') }}</h3>
        <div class="hidden overflow-x-auto sm:block">
          <table class="w-full text-sm"><thead class="bg-neutral-50 text-xs text-neutral-500"><tr><th class="p-2 text-left">{{ t('common.date') }}</th><th class="p-2 text-left">{{ t('automation.rules.change') }}</th><th class="p-2 text-right">{{ t('bank.amount') }}</th><th class="p-2 text-left">{{ t('automation.rules.who') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100"><tr v-for="item in history.corrections" :key="item.id"><td class="p-2">{{ formatDate(item.created_at) }}</td><td class="p-2 font-mono">{{ item.suggested || '—' }} → {{ item.final || '—' }}</td><td class="p-2 text-right">{{ item.amount == null ? '—' : formatMoney(item.amount, 'CZK') }}</td><td class="p-2">{{ item.created_by_name || t('automation.rules.system') }}</td></tr></tbody></table>
        </div>
        <div class="space-y-2 sm:hidden"><div v-for="item in history.corrections" :key="`m-${item.id}`" class="rounded border border-neutral-200 p-3 text-sm"><div class="font-mono">{{ item.suggested || '—' }} → {{ item.final || '—' }}</div><div class="mt-1 text-xs text-neutral-500">{{ formatDate(item.created_at) }} · {{ item.created_by_name || t('automation.rules.system') }}</div></div></div>
        <p v-if="!history.corrections.length" class="text-sm text-neutral-500">{{ t('automation.rules.corrections_empty') }}</p>
      </section>
      <PaginationBar :page="page" :per-page="history.per_page" :total="history.total" @update:page="page = $event" />
    </div>
  </Modal>
</template>
