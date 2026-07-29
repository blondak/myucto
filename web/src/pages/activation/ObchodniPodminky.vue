<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t, tm, rt } = useI18n()

interface Article { title: string; body: string }
const articles = tm('license.terms_articles') as unknown as Article[]
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
