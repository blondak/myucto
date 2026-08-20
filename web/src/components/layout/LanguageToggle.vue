<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { ensureLocaleLoaded } from '@/i18n'

const { locale } = useI18n()
const targetLocale = computed<'cs' | 'en'>(() => locale.value === 'cs' ? 'en' : 'cs')
const targetLabel = computed(() => targetLocale.value === 'cs' ? 'Čeština' : 'English')
const ukClipPathId = `uk-flag-toggle-${useId()}`

async function toggleLocale() {
  const next = targetLocale.value
  await ensureLocaleLoaded(next)
  locale.value = next
  localStorage.setItem('locale', next)
}
</script>

<template>
  <button
    type="button"
    class="cursor-pointer h-8 w-9 inline-flex items-center justify-center rounded-md border border-neutral-200 bg-surface hover:bg-neutral-50"
    :title="targetLabel"
    :aria-label="targetLabel"
    @click="toggleLocale"
  >
    <svg v-if="targetLocale === 'cs'" width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="6" height="2" fill="#ffffff"/>
      <rect y="2" width="6" height="2" fill="#d7141a"/>
      <polygon points="0,0 3,2 0,4" fill="#11457e"/>
    </svg>
    <svg v-else width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <clipPath :id="ukClipPathId"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
      <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
      <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
      <path d="M0,0 L60,30 M60,0 L0,30" :clip-path="`url(#${ukClipPathId})`" stroke="#C8102E" stroke-width="4"/>
      <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
      <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
    </svg>
  </button>
</template>
