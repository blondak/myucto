<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
</script>

<template>
  <!--
    404 nemá být holé číslo na prázdné stránce. Číslice v display řezu z ní dělá
    stránku se záměrem, soustředné kruhy drží kompozici pohromadě a návrat je
    plnohodnotné tlačítko, ne odkaz ztracený pod textem — uživatel se sem dostane
    překlepem v URL nebo starým odkazem a chce jediné: cestu zpátky.
  -->
  <div class="min-h-screen flex items-center justify-center px-4 text-center">
    <div class="rise-in">
      <div class="relative mx-auto mb-6 w-40 h-40 grid place-content-center">
        <span class="absolute inset-0 rounded-full bg-primary-500/5" aria-hidden="true"></span>
        <span class="absolute inset-5 rounded-full bg-primary-500/10" aria-hidden="true"></span>
        <span class="font-display relative text-7xl leading-none text-primary-700">404</span>
      </div>
      <p class="text-base text-neutral-600">{{ t('errors.not_found') }}</p>
      <RouterLink to="/"
        class="inline-flex items-center gap-1.5 mt-6 h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md shadow-sm hover:shadow-md transition-all duration-150 active:translate-y-px">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        {{ t('nav.dashboard') }}
      </RouterLink>
    </div>
  </div>
</template>
