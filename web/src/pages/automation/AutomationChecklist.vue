<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { automationApi } from '@/api/automation'
const props = defineProps<{ supplierId: number }>()
const { t } = useI18n()
const scope = ref('daily')
const items = ref<Array<{ key: string; ok: boolean; count: number; link: { route: string; query: Record<string,string> } }>>([])
async function load() { items.value = (await automationApi.checklist(scope.value, props.supplierId)).items }
onMounted(load)
</script>
<template><div class="space-y-4"><div class="flex flex-wrap gap-2"><button v-for="s in ['daily','month_end','vat_return']" :key="s" @click="scope=s;load()" class="rounded px-3 py-2 text-sm" :class="scope===s?'bg-primary-600 text-white':'border border-neutral-300'">{{ t(`automation.checklist.scope_${s}`) }}</button></div><div class="rounded-lg border border-neutral-200 bg-surface divide-y divide-neutral-200"><RouterLink v-for="item in items" :key="item.key" :to="item.link" class="flex items-center justify-between gap-3 p-4 hover:bg-neutral-50"><span><span class="mr-2">{{ item.ok ? '✓' : '✕' }}</span>{{ t(`automation.checklist.${item.key}`) }}</span><strong :class="item.ok?'text-success-600':'text-danger-500'">{{ item.count }}</strong></RouterLink></div></div></template>
