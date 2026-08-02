<script setup lang="ts">
const props = defineProps<{
  rows?: number
  cols?: number
}>()

/**
 * Šířky placeholderů se opakují podle pevného vzoru, ne náhodně.
 * Why: stejně dlouhé pruhy vypadají jako rozbité rozvržení, ne jako načítající se
 * data — skutečná tabulka má krátká čísla, dlouhé názvy a zase krátká data.
 * Vzor musí být deterministický, aby placeholder při každém překreslení
 * neposkakoval (Math.random() by se přepočítal při každém renderu).
 */
const WIDTHS = ['w-16', 'w-full', 'w-24', 'w-20', 'w-28', 'w-12']

function widthFor(row: number, col: number): string {
  return WIDTHS[(row * 3 + col * 2) % WIDTHS.length]
}

const colCount = () => props.cols ?? 5
</script>

<template>
  <div class="shimmer" role="status" aria-busy="true">
    <div class="border-b border-neutral-200 bg-neutral-50 px-4 py-3 flex gap-4">
      <div v-for="c in colCount()" :key="`h${c}`" class="h-2.5 rounded bg-neutral-300/70" :class="c === 2 ? 'flex-1' : 'w-20'"></div>
    </div>
    <div v-for="r in (rows ?? 6)" :key="r" class="border-b border-neutral-100 px-4 py-3.5 flex items-center gap-4">
      <div v-for="c in colCount()" :key="`c${c}`"
        class="h-3 rounded bg-neutral-200/70"
        :class="[c === 2 ? 'flex-1' : widthFor(r, c)]"></div>
    </div>
  </div>
</template>

<style scoped>
/*
 * Přejíždějící lesk místo `animate-pulse`. Pulsování celého bloku působí jako
 * blikání chyby; posun světelného pruhu čte mozek jako „něco se děje".
 * Respekt k prefers-reduced-motion řeší globální pravidlo v styles/main.css.
 */
.shimmer {
  position: relative;
  overflow: hidden;
}
.shimmer::after {
  content: "";
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(
    90deg,
    transparent,
    color-mix(in oklab, var(--color-neutral-900) 5%, transparent),
    transparent
  );
  animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
  to { transform: translateX(100%); }
}
</style>
