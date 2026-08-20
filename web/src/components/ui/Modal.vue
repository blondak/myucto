<script setup lang="ts">
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { usePaneActivity, usePaneId } from '@/workspace/paneActivity'
import { lockBodyScroll, unlockBodyScroll } from '@/utils/bodyScrollLock'

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header/footer.
 * Tělo se scrolluje, hlavička a volitelná patička zůstávají. Šířka přes
 * `widthClass` (Tailwind utility).
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
const paneActive = usePaneActivity()
const paneId = usePaneId()
let scrollLocked = false

function syncBodyScrollLock(active: boolean): void {
  if (active && !scrollLocked) {
    lockBodyScroll()
    scrollLocked = true
  } else if (!active && scrollLocked) {
    unlockBodyScroll()
    scrollLocked = false
  }
}

function onKey(e: KeyboardEvent) {
  if (paneActive.value && e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  syncBodyScrollLock(paneActive.value)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  syncBodyScrollLock(false)
})
watch(paneActive, syncBodyScrollLock)

void props
</script>

<template>
  <Teleport to="body">
    <!--
      Backdrop rozostřuje pozadí a je o odstín hlubší: modál je modální, obsah
      pod ním má ustoupit. Panel přijíždí spring křivkou (.rise-in) — naskočení
      bez pohybu působí jako překreslení stránky, ne jako otevření dialogu.
    -->
    <div v-show="paneActive" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-900/45 backdrop-blur-[3px]"
      role="dialog" aria-modal="true" :aria-hidden="paneActive ? undefined : true" :data-workspace-pane="paneId ?? undefined"
      @click.self="emit('close')">
      <div class="rise-in flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-xl bg-surface-raised shadow-2xl ring-1 ring-neutral-200" :class="widthClass">
        <header class="px-5 py-3.5 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" aria-label="Close"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="shrink-0 border-t border-neutral-200 px-5 py-4">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>
