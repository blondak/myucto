<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue'

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header.
 * Tělo se scrolluje, hlavička zůstává. Šířka přes `widthClass` (Tailwind utility).
 */
const props = withDefaults(defineProps<{
  title: string
  widthClass?: string
}>(), {
  widthClass: 'max-w-3xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  // Zamkni body scroll dokud je modal otevřený.
  document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})

void props
</script>

<template>
  <Teleport to="body">
    <!--
      Backdrop rozostřuje pozadí a je o odstín hlubší: modál je modální, obsah
      pod ním má ustoupit. Panel přijíždí spring křivkou (.rise-in) — naskočení
      bez pohybu působí jako překreslení stránky, ne jako otevření dialogu.
    -->
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-900/45 backdrop-blur-[3px]"
      @click.self="emit('close')">
      <div class="rise-in bg-surface-raised rounded-xl shadow-2xl ring-1 ring-neutral-200 w-full flex flex-col max-h-[90vh]" :class="widthClass">
        <header class="px-5 py-3.5 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" aria-label="Close"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div class="overflow-y-auto flex-1 p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
