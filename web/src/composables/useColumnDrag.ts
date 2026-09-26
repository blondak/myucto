import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'
import '@/styles/columnDrag.css'

export function useColumnDrag(ctrl: TablePrefsCtrl) {
  const { t } = useI18n()
  const source = ref<string | null>(null)
  const target = ref<string | null>(null)
  const after = ref(false)
  let ignoreClickUntil = 0

  function clear() { source.value = null; target.value = null }

  function headerAttrs(key: string) {
    return {
      draggable: ctrl.ready.value,
      'data-column-key': key,
      'data-column-drop': target.value === key ? (after.value ? 'after' : 'before') : undefined,
      title: t('common.column_drag_hint'),
      onDragstart: (event: DragEvent) => {
        if (!ctrl.ready.value || !event.dataTransfer) { event.preventDefault(); return }
        event.stopPropagation()
        source.value = key
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData('text/plain', key)
      },
      onDragover: (event: DragEvent) => {
        if (!source.value || source.value === key) return
        event.preventDefault()
        event.stopPropagation()
        const box = (event.currentTarget as HTMLElement).getBoundingClientRect()
        target.value = key
        after.value = event.clientX > box.left + box.width / 2
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
      },
      onDragleave: (event: DragEvent) => {
        if (event.relatedTarget instanceof Node && (event.currentTarget as HTMLElement).contains(event.relatedTarget)) return
        if (target.value === key) target.value = null
      },
      onDrop: (event: DragEvent) => {
        if (!source.value) return
        event.preventDefault()
        event.stopPropagation()
        ctrl.moveColumn(source.value, key, after.value)
        ignoreClickUntil = Date.now() + 500
        clear()
      },
      onDragend: () => { ignoreClickUntil = Date.now() + 500; clear() },
      onClickCapture: (event: MouseEvent) => {
        if (Date.now() < ignoreClickUntil) { event.preventDefault(); event.stopImmediatePropagation() }
      },
    }
  }

  return { headerAttrs }
}
