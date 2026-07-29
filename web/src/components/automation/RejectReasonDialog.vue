<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{ count: number; busy?: boolean }>()
const emit = defineEmits<{ confirm: [reason: string]; close: [] }>()
const { t } = useI18n()
const reason = ref('wrong_account')
const reasons = ['wrong_account', 'not_ours', 'duplicate', 'other'] as const
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
    <section class="w-full max-w-md rounded-xl bg-surface p-5 shadow-xl">
      <header class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">{{ t('automation.reject_reason_title') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('automation.reject_reason_summary', { count }) }}</p></div><button type="button" class="text-2xl" @click="emit('close')">×</button></header>
      <div class="mt-4 space-y-2"><label v-for="value in reasons" :key="value" class="flex cursor-pointer items-center gap-2 rounded border border-neutral-200 px-3 py-2"><input v-model="reason" type="radio" :value="value"><span>{{ t(`automation.reject_reason.${value}`) }}</span></label></div>
      <footer class="mt-5 flex flex-wrap justify-end gap-2"><button type="button" class="rounded border border-neutral-300 px-4 py-2 text-sm" :disabled="busy" @click="emit('close')">{{ t('common.cancel') }}</button><button type="button" class="rounded bg-danger-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40" :disabled="busy" @click="emit('confirm', reason)">✕ {{ t('bank.posting.action_reject') }}</button></footer>
    </section>
  </div>
</template>
