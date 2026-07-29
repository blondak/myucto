<script setup lang="ts">
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { BTN_SM_BASE, OUTLINE, FILLED, MENU_ICON, ICONS, type ActionVariant, type ActionIcon } from './buttonStyles'

/**
 * Kompaktní řádková lišta akcí pro husté tabulky (výpis banky apod.).
 *
 * Drží akce na jednom řádku: prvních `inlineCount` (default 2) jako malá tlačítka
 * (btnSm), zbytek spadne do „…" popupu teleportovaného do <body> — stejný princip
 * jako ActionBar na detailových stránkách, jen menší. Řeší přetékání/scrollování
 * úzkého sloupce akcí, když je tlačítek víc.
 */

export interface RowAction {
  key: string
  label: string
  icon?: ActionIcon
  variant?: ActionVariant   // default 'neutral'
  filled?: boolean          // inline: plné tlačítko místo outline (hlavní akce)
  disabled?: boolean
  title?: string
  to?: RouteLocationRaw
  href?: string
  download?: boolean
  run?: () => void
  show?: unknown            // default true; falsy = skrýt
}

const props = withDefaults(defineProps<{ actions: RowAction[]; inlineCount?: number }>(), {
  inlineCount: 2,
})

const { t } = useI18n()

const DOTS = 'M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0z'

const visible = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))
const inline = computed(() => visible.value.slice(0, props.inlineCount))
const menu = computed(() => visible.value.slice(props.inlineCount))
const hasMenu = computed(() => menu.value.length > 0)

function tagOf(a: RowAction) {
  if (a.to) return RouterLink
  if (a.href) return 'a'
  return 'button'
}
function attrsOf(a: RowAction): Record<string, unknown> {
  if (a.to) return { to: a.to }
  if (a.href) return { href: a.href, target: '_blank', rel: 'noopener', ...(a.download ? { download: '' } : {}) }
  return { type: 'button' }
}
function inlineClass(a: RowAction): string {
  const v = a.variant ?? 'neutral'
  return `${BTN_SM_BASE} ${a.filled ? FILLED[v] : OUTLINE[v]}`
}

// ─── dropdown ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 208
  const mh = menuRef.value?.offsetHeight ?? 240
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4)
  pos.value = { top, left }
}
async function toggle() {
  if (open.value) { open.value = false; return }
  reposition(); open.value = true; await nextTick(); reposition()
}
function close() { open.value = false }
function runItem(a: RowAction) {
  close()
  if (a.run) a.run()
}

function onKey(e: KeyboardEvent) { if (e.key === 'Escape') close() }
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <div class="inline-flex items-center gap-1 justify-end">
    <component :is="tagOf(a)" v-for="a in inline" :key="a.key" v-bind="attrsOf(a)"
      :class="inlineClass(a)" :disabled="a.disabled" :title="a.title || a.label"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.label }}
    </component>

    <button v-if="hasMenu" ref="triggerRef" type="button" @click.stop="toggle"
      :class="['cursor-pointer w-7 h-7 shrink-0 inline-flex items-center justify-center rounded-md ring-1 ring-inset transition-colors',
               open ? 'bg-neutral-100 text-neutral-700 ring-neutral-400'
                    : 'text-neutral-500 ring-neutral-300 hover:bg-neutral-100 hover:text-neutral-700']"
      :aria-expanded="open" :title="t('common.more_actions')">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path :d="DOTS" /></svg>
    </button>

    <Teleport to="body">
      <template v-if="open">
        <div class="fixed inset-0 z-[60]" @click="close" @contextmenu.prevent="close" aria-hidden="true"></div>
        <div ref="menuRef" class="fixed z-[61] w-52 max-w-[calc(100vw-16px)] bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 text-sm"
          :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
          <component :is="tagOf(a)" v-for="a in menu" :key="a.key" v-bind="attrsOf(a)"
            :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                     a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                     a.disabled ? 'opacity-50 pointer-events-none' : '']"
            :title="a.title || undefined" @click="runItem(a)">
            <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
            </svg>
            <span>{{ a.label }}</span>
          </component>
        </div>
      </template>
    </Teleport>
  </div>
</template>
