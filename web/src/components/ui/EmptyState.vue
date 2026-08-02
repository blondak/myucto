<script setup lang="ts">
defineProps<{
  title: string
  message?: string
  cta?: string
  to?: string
}>()
</script>

<template>
  <!--
    Prázdný stav je první, co uživatel v nové agendě uvidí — nesmí vypadat jako
    chyba nebo nedotažený seznam. Piktogram sedí v soustředných kruzích s brand
    tintem místo šedého kolečka, nadpis má váhu obsahu a CTA nese ikonu „+"
    stejně jako všechna ostatní zakládací tlačítka.
  -->
  <div class="rise-in p-12 text-center">
    <div class="relative mx-auto mb-4 w-16 h-16">
      <span class="absolute inset-0 rounded-full bg-primary-500/5" aria-hidden="true"></span>
      <span class="absolute inset-2 rounded-full bg-primary-500/10" aria-hidden="true"></span>
      <span class="absolute inset-0 grid place-content-center">
        <svg class="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2a4 4 0 0 1 4-4h6m0 0l-3-3m3 3l-3 3M3 12a9 9 0 1 1 18 0 9 9 0 0 1-18 0z"/>
        </svg>
      </span>
    </div>
    <p class="text-base font-medium text-neutral-800">{{ title }}</p>
    <p v-if="message" class="mx-auto mt-1.5 max-w-md text-sm text-neutral-500">{{ message }}</p>
    <RouterLink v-if="cta && to" :to="to"
      class="inline-flex items-center gap-1.5 mt-5 h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md shadow-sm hover:shadow-md transition-all duration-150 active:translate-y-px">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
      </svg>
      {{ cta }}
    </RouterLink>
  </div>
</template>
