<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t, tm, rt } = useI18n()

const points = tm('license.model_points') as unknown[]
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('license.model_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('license.model_subtitle') }}</p>
    </header>

    <div class="space-y-6">
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <p class="text-sm text-neutral-700 leading-relaxed">{{ t('license.model_intro') }}</p>
        <ul class="mt-4 space-y-2">
          <li v-for="(p, i) in points" :key="i" class="flex items-start gap-2 text-sm text-neutral-700">
            <svg class="w-4 h-4 mt-0.5 shrink-0 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            <span>{{ rt(p as string) }}</span>
          </li>
        </ul>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.trial_title') }}</h2>
        <p class="text-sm text-neutral-700 leading-relaxed mt-1.5">{{ t('license.trial_body') }}</p>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.tiers_title') }}</h2>
        <p class="text-sm text-neutral-700 leading-relaxed mt-1.5">{{ t('license.tiers_body') }}</p>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.readonly_title') }}</h2>
        <p class="text-sm text-neutral-700 leading-relaxed mt-1.5">{{ t('license.readonly_body') }}</p>
      </section>

      <p class="text-sm text-neutral-600">
        <a href="https://myucto.cz/licence" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800 hover:underline font-medium">
          {{ t('license.full_license_link') }} →
        </a>
      </p>
    </div>
  </div>
</template>
