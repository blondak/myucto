<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { useWorkspaceStore } from '@/stores/workspace'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { authorizationGuard } from '@/router'
import { getPaneRuntime, listPaneRuntimes } from '@/workspace/runtimeRegistry'
import { resizePaneFractions } from '@/workspace/panelSizing'
import { paneCountFromShortcut, panelIndexFromShortcut } from '@/workspace/panelShortcuts'
import WorkspacePane from './WorkspacePane.vue'

const workspace = useWorkspaceStore()
const { t } = useI18n()
const navigation = useWorkspaceNavigation()
const supplier = useSupplierStore()
const primaryRouter = useRouter()
workspace.resetLayout(1, primaryRouter.currentRoute.value.fullPath)
const host = ref<HTMLElement | null>(null)
const grid = ref<HTMLElement | null>(null)
const width = ref(0)
const paneFractions = ref<number[]>([1])
let observer: ResizeObserver | null = null
let resizeIndex: number | null = null
let resizeStartX = 0
let resizeStartFractions: number[] = []
const splitterWidth = 6

const allowTwo = computed(() => width.value >= 1100)
const allowThree = computed(() => width.value >= 1600)
const gridStyle = computed(() => ({
  gridTemplateColumns: paneFractions.value
    .flatMap((fraction, index) => [
      `minmax(18rem, ${fraction}fr)`,
      ...(index < paneFractions.value.length - 1 ? [`${splitterWidth}px`] : []),
    ])
    .join(' '),
}))

function resetFractions(): void {
  paneFractions.value = Array.from({ length: workspace.paneCount }, () => 1 / workspace.paneCount)
}

function resizeByPixels(index: number, deltaX: number, source: number[]): void {
  if (!grid.value) return
  const paneWidth = grid.value.clientWidth - splitterWidth * (workspace.paneCount - 1)
  if (paneWidth <= 0) return
  paneFractions.value = resizePaneFractions(source, index, deltaX, paneWidth)
}

function onResizeMove(event: PointerEvent): void {
  if (resizeIndex === null) return
  resizeByPixels(resizeIndex, event.clientX - resizeStartX, resizeStartFractions)
}

function stopResize(): void {
  resizeIndex = null
  document.removeEventListener('pointermove', onResizeMove)
  document.removeEventListener('pointerup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
}

function startResize(index: number, event: PointerEvent): void {
  resizeIndex = index
  resizeStartX = event.clientX
  resizeStartFractions = [...paneFractions.value]
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('pointermove', onResizeMove)
  document.addEventListener('pointerup', stopResize, { once: true })
}

function resizeWithKeyboard(index: number, event: KeyboardEvent): void {
  if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return
  event.preventDefault()
  resizeByPixels(index, event.key === 'ArrowLeft' ? -32 : 32, paneFractions.value)
}

function activatePanelShortcut(event: KeyboardEvent): void {
  const index = panelIndexFromShortcut(event)
  if (index !== null) {
    const pane = workspace.panes[index]
    if (!pane) return
    event.preventDefault()
    event.stopImmediatePropagation()
    navigation.activatePane(pane.id)
    getPaneRuntime(pane.id)?.root.focus({ preventScroll: true })
    return
  }

  const count = paneCountFromShortcut(event)
  if (count === null || count > workspace.maximumPaneCount) return
  event.preventDefault()
  event.stopImmediatePropagation()
  void navigation.setPaneCount(count)
}

async function enforceSupportedLayout(): Promise<void> {
  if ((workspace.paneCount === 3 && !allowThree.value) || (workspace.paneCount === 2 && !allowTwo.value)) {
    await navigation.setPaneCount(1)
  }
}

async function reevaluatePanePermissions(): Promise<void> {
  for (const runtime of listPaneRuntimes()) {
    const current = runtime.router.currentRoute.value
    if (!current.matched.length) continue
    const decision = await authorizationGuard(current, current, {
      allowGlobalSideEffects: runtime.id === 'primary',
      onGlobalNavigation: to => {
        if (typeof to === 'string' && /^https?:\/\//i.test(to)) window.location.replace(to)
        else void primaryRouter.push(to)
      },
    })
    if (decision && decision !== true && typeof decision !== 'boolean') await runtime.router.replace(decision)
  }
}

watch(() => supplier.currentSupplierId, () => { void reevaluatePanePermissions() })
watch(width, () => { void enforceSupportedLayout() })
watch(() => workspace.paneCount, resetFractions, { immediate: true })
watch([allowTwo, allowThree], () => {
  workspace.setMaximumPaneCount(allowThree.value ? 3 : allowTwo.value ? 2 : 1)
}, { immediate: true })

onMounted(() => {
  window.addEventListener('keydown', activatePanelShortcut)
  observer = new ResizeObserver((entries) => {
    width.value = entries[0]?.contentRect.width ?? host.value?.clientWidth ?? 0
  })
  if (host.value) observer.observe(host.value)
  void nextTick(() => {
    width.value = host.value?.clientWidth ?? 0
    void enforceSupportedLayout()
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', activatePanelShortcut)
  observer?.disconnect()
  stopResize()
})
</script>

<template>
  <div ref="host" class="workspace-host min-h-0 min-w-0" :class="workspace.paneCount > 1 ? 'workspace-host-multi' : ''">
    <div ref="grid" class="workspace-grid min-h-0 min-w-0" :class="workspace.paneCount > 1 ? 'grid' : 'block'" :style="workspace.paneCount > 1 ? gridStyle : undefined">
      <template v-for="(pane, index) in workspace.panes" :key="`${pane.id}:${workspace.layoutRevision}`">
        <WorkspacePane
          :pane-id="pane.id"
          :index="index + 1"
          :global-router="primaryRouter"
          :primary-router="pane.kind === 'primary' ? primaryRouter : undefined"
          :show-header="workspace.paneCount > 1"
          :single="workspace.paneCount === 1"
        >
          <slot v-if="pane.kind === 'primary'" name="primary" :revision="workspace.layoutRevision" />
        </WorkspacePane>
        <div
          v-if="index < workspace.panes.length - 1"
          class="workspace-splitter group relative z-10 cursor-col-resize bg-surface print:hidden"
          role="separator"
          tabindex="0"
          aria-orientation="vertical"
          :aria-label="t('workspace.resize_panels')"
          @pointerdown.prevent="startResize(index, $event)"
          @keydown="resizeWithKeyboard(index, $event)"
        >
          <span class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-neutral-200 transition-all group-hover:w-0.5 group-hover:bg-primary-400 group-focus:w-0.5 group-focus:bg-primary-500" />
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.workspace-grid {
  min-height: 0;
}

.workspace-host-multi {
  margin-right: -2rem;
  margin-bottom: -1.5rem;
  margin-left: -2rem;
}

.workspace-host-multi:first-child {
  margin-top: -1.5rem;
}

.workspace-host-multi .workspace-grid {
  height: calc(100vh - 6.1rem);
  min-height: 32rem;
}

@media print {
  .workspace-grid {
    display: block;
    height: auto;
    min-height: 0;
  }

  .workspace-pane:not(.workspace-pane-active) {
    display: none;
  }
}
</style>
