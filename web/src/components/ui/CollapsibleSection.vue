<script setup lang="ts">
import { ref, computed, watch } from 'vue'

/**
 * Sbalitelná sekce detailu — hlavička s ikonou, titulkem, počtem a šipkou.
 *
 * Vzniklo ze tří skoro stejných ad-hoc přepínačů (DocumentPostingPanel,
 * JournalEntryNotes, JournalEntryHistory), které se každý choval jinak. Detail
 * zápisu i faktury nesly pod hlavní věcí sloupec sekcí (přílohy, poznámky,
 * historie, dokumenty), které jsou u drtivé většiny dokladů prázdné — prázdná
 * sekce tak zabírala víc místa než ta, kvůli které je doklad otevřený.
 *
 * Pravidlo: prázdná sekce je sbalená a drží jeden řádek, sekce s obsahem se
 * otevře sama. Ruční přepnutí má vždy přednost před automatikou — `count` se
 * po dotažení dat mění a nesmí uživateli zavírat, co si otevřel.
 */
const props = withDefaults(defineProps<{
  title: string
  /** Cesta ikony (`d` z {@see ICONS}); bez ní je hlavička jen text. */
  icon?: string
  /** Počet položek uvnitř. `null` = ještě se načítá, 0 = prázdno. */
  count?: number | null
  /** Otevřít i při nulovém počtu (sekce, kterou uživatel řeší pokaždé). */
  defaultOpen?: boolean
  /**
   * `inline` = podsekce v už orámovaném detailu (dělicí linka nahoře, drobná
   * hlavička), `card` = samostatná karta na stránce.
   */
  variant?: 'inline' | 'card'
}>(), { count: null, defaultOpen: false, variant: 'inline' })

const emit = defineEmits<{ (e: 'toggle', open: boolean): void }>()

const open = ref(props.defaultOpen || (props.count ?? 0) > 0)
const touched = ref(false)

// Počty dotahují potomci až po připojení (přílohy, poznámky, dokumenty). Než
// dorazí, je sekce sbalená; jakmile se ukáže, že něco obsahuje, otevře se sama.
watch(() => props.count, n => {
  if (!touched.value && (n ?? 0) > 0) { open.value = true }
})

const wrapperClass = computed(() => (props.variant === 'card'
  ? 'bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3'
  : 'border-t border-neutral-200 pt-3'))

const titleClass = computed(() => (props.variant === 'card'
  ? 'text-sm font-medium text-neutral-700'
  : 'text-xs font-medium text-neutral-500'))

function toggle() {
  touched.value = true
  open.value = !open.value
  emit('toggle', open.value)
}

defineExpose({ open })
</script>

<template>
  <section :class="wrapperClass">
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" class="cursor-pointer group flex min-w-0 items-center gap-2 text-left" @click="toggle">
        <svg class="w-3.5 h-3.5 shrink-0 text-neutral-400 transition-transform" :class="open ? 'rotate-90' : ''"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
        <svg v-if="icon" class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="icon" />
        </svg>
        <span class="truncate group-hover:text-neutral-700" :class="titleClass">{{ title }}</span>
        <span v-if="(count ?? 0) > 0" class="text-xs text-neutral-400">({{ count }})</span>
      </button>
      <!-- Akce sekce (nahrát, připojit) patří do hlavičky, ať jsou po ruce
           i u sbalené sekce — otvírat ji jen kvůli tlačítku je klik navíc. -->
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <slot name="actions" :open="open" />
      </div>
    </div>
    <div v-show="open" class="mt-2">
      <slot :open="open" />
    </div>
  </section>
</template>
