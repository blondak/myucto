<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { AutomationProvenance } from '@/api/automation'
import { automationSourceClass } from '@/utils/automationSource'

const props = defineProps<{ provenance: AutomationProvenance; title?: string }>()
const { t } = useI18n()

const label = computed(() => {
  const p = props.provenance
  if (p.source === 'rule' && p.rule_name) {
    const named = t('automation.source.rule_named', { name: p.rule_name })
    return p.rule_approved_streak === null || p.rule_approved_streak === undefined
      ? named : `${named} · ${t('automation.rules.ramp', { n: Math.min(5, p.rule_approved_streak) })}`
  }
  if (p.source === 'detector' && p.detector) {
    const key = `automation.detector.${p.detector}`
    const value = t(key)
    return value === key ? t('automation.source.detector') : value
  }
  return t(`automation.source.${p.source}`)
})

const displayTitle = computed(() => {
  if (props.title) return props.title
  const p = props.provenance
  if (p.source === 'rule' && p.rule_name) return t('automation.why.rule_named', { name: p.rule_name })
  return t(`automation.why.${p.source}`)
})

const ruleLink = computed(() => props.provenance.rule_id
  ? { path: '/automation', query: { tab: 'rules', rule: String(props.provenance.rule_id) } }
  : null)
const chipClass = computed(() => automationSourceClass(props.provenance.source))
</script>

<template>
  <RouterLink v-if="ruleLink" :to="ruleLink" :title="displayTitle"
    :class="chipClass" class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap">
    {{ label }}
  </RouterLink>
  <span v-else :title="displayTitle"
    :class="chipClass" class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap">
    {{ label }}
  </span>
</template>
