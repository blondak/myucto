<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WidgetDefinition, WidgetInstance } from '@/dashboard/types'
const props = defineProps<{ instance: WidgetInstance; definition: WidgetDefinition; cellWidth: number; cellHeight: number; disabled?: boolean }>()
const emit = defineEmits<{ resize: [span: number, height: WidgetInstance['height']] }>()
const { t } = useI18n()
const active = ref(false)
let origin = { x: 0, y: 0, span: 0, rows: 0 }
const heightRows: Record<string, number> = { auto: 6, compact: 4, normal: 6, tall: 9 }
function nearest<T>(values: T[], target: number, number: (v: T) => number): T | undefined {
  return values.reduce<T | undefined>((best, value) => best === undefined || Math.abs(number(value) - target) < Math.abs(number(best) - target) ? value : best, undefined)
}
function start(event: PointerEvent) {
  if (props.disabled) return
  active.value = true
  origin = { x: event.clientX, y: event.clientY, span: props.instance.span, rows: heightRows[props.instance.height ?? 'auto'] ?? 6 }
  ;(event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId)
}
function move(event: PointerEvent) {
  if (!active.value || props.disabled) return
  const span = nearest(props.definition.spans, origin.span + (event.clientX - origin.x) / (props.cellWidth + 16), Number) ?? props.instance.span
  const heights = (props.definition.heights ?? ['auto']).filter(h => h !== 'auto')
  const height = Math.abs(event.clientY - origin.y) < 12 || !heights.length ? props.instance.height : nearest(heights, origin.rows + (event.clientY - origin.y) / (props.cellHeight + 16), h => heightRows[h] ?? 6) as WidgetInstance['height']
  emit('resize', span, height)
}
function stop(event: PointerEvent) { active.value = false; (event.currentTarget as HTMLElement).releasePointerCapture?.(event.pointerId) }
function keyboard(event: KeyboardEvent) {
  if (props.disabled) return
  const spans = [...props.definition.spans].sort((a,b) => a-b)
  const heights = (props.definition.heights ?? ['auto']).filter(h => h !== 'auto')
  let span = props.instance.span; let height = props.instance.height
  if (event.key === 'ArrowRight') span = spans.find(s => s > span) ?? span
  else if (event.key === 'ArrowLeft') span = [...spans].reverse().find(s => s < span) ?? span
  else if (event.key === 'ArrowDown') height = heights.find(h => (heightRows[h] ?? 0) > (heightRows[height ?? 'auto'] ?? 6)) as WidgetInstance['height'] ?? height
  else if (event.key === 'ArrowUp') height = [...heights].reverse().find(h => (heightRows[h] ?? 0) < (heightRows[height ?? 'auto'] ?? 6)) as WidgetInstance['height'] ?? height
  else return
  event.preventDefault(); emit('resize', span, height)
}
</script>
<template>
  <button type="button" :disabled="disabled" class="absolute bottom-1 right-1 rounded p-1 text-primary-600 hover:bg-primary-50 touch-none cursor-nwse-resize disabled:opacity-50" :aria-label="t('dashboardBoard.resize')" :title="t('dashboardBoard.resize')" @pointerdown.stop.prevent="start" @pointermove.stop.prevent="move" @pointerup.stop.prevent="stop" @pointercancel.stop.prevent="stop" @keydown="keyboard" @dragstart.stop.prevent>
    <svg aria-hidden="true" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 20 11-11M15 20l5-5M4 20 20 4" stroke-linecap="round" /></svg>
  </button>
</template>
