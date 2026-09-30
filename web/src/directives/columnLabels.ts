import { watch, type Directive, type Ref } from 'vue'
import type { ColumnDef } from '@/composables/useTablePrefs'

export interface ColumnLabelsController {
  columns: ColumnDef[]
  columnLabelsDefault: Ref<boolean>
  showColumnLabels: Readonly<Ref<boolean>>
}

const controllerTables = new WeakMap<ColumnLabelsController, Set<HTMLTableElement>>()
const disposers = new WeakMap<HTMLTableElement, { ctrl: ColumnLabelsController; dispose: () => void; update: () => void }>()
const ignoredColumnKeys = new Set(['actions', 'action', 'preview', 'show', 'select', 'selection', 'checkbox'])

function syncDefault(ctrl: ColumnLabelsController) {
  ctrl.columnLabelsDefault.value = [...(controllerTables.get(ctrl) ?? [])]
    .some(table => table.classList.contains('adaptive-table') || table.classList.contains('multirow-table'))
}

function textLabel(header: HTMLTableCellElement): string {
  if (header.querySelector('input[type="checkbox"], input[type="radio"]')) return ''
  const copy = header.cloneNode(true) as HTMLElement
  copy.querySelectorAll('[aria-hidden="true"], svg, .sr-only').forEach(element => element.remove())
  return (copy.textContent ?? '').replace(/[▲▼×]/g, '').replace(/\s+/g, ' ').trim()
}

function refresh(table: HTMLTableElement, ctrl: ColumnLabelsController, translate?: (key: string) => string) {
  syncDefault(ctrl)
  table.classList.toggle('column-labels', ctrl.showColumnLabels.value)
  const ignoredLabels = new Set(['common.actions', 'common.preview', 'common.show'].map(key => translate?.(key) ?? key))
  for (const column of ctrl.columns) {
    if (ignoredColumnKeys.has(column.key)) ignoredLabels.add(translate?.(column.labelKey) ?? column.labelKey)
  }
  const headerRows = Array.from(table.tHead?.rows ?? [])
  const header = headerRows.length === 1 ? headerRows[0] : undefined
  const labels: string[] = []
  for (const cell of Array.from(header?.cells ?? [])) {
    const label = textLabel(cell)
    const excluded = cell.colSpan !== 1 || ignoredColumnKeys.has(cell.dataset.columnKey ?? '') || ignoredLabels.has(label)
    for (let index = 0; index < cell.colSpan; index++) labels.push(excluded ? '' : label)
  }
  for (const section of [table.tHead, table.tFoot]) {
    for (const row of Array.from(section?.rows ?? [])) {
      for (const cell of Array.from(row.cells)) cell.removeAttribute('data-column-label')
    }
  }
  for (const body of Array.from(table.tBodies)) {
    for (const row of Array.from(body.rows)) {
      const cells = Array.from(row.cells)
      const detail = row.matches('.table-detail-row, .detail-row, .table-summary-row, .table-total-row, [data-column-labels-ignore]')
        || cells.some(cell => cell.colSpan !== 1 || cell.rowSpan !== 1)
        || cells.length !== labels.length
      cells.forEach((cell, index) => {
        const label = !detail && cell.tagName === 'TD' ? labels[index] ?? '' : ''
        if (label) {
          if (cell.dataset.columnLabel !== label) cell.dataset.columnLabel = label
        } else if (cell.hasAttribute('data-column-label')) cell.removeAttribute('data-column-label')
      })
    }
  }
}

function classesWithoutLabels(value: string | null): string {
  return (value ?? '').split(/\s+/).filter(value => value && value !== 'column-labels').sort().join(' ')
}

export function bindColumnLabels(table: HTMLTableElement, ctrl: ColumnLabelsController, translate?: (key: string) => string): () => void {
  disposers.get(table)?.dispose()
  const tables = controllerTables.get(ctrl) ?? new Set<HTMLTableElement>()
  tables.add(table)
  controllerTables.set(ctrl, tables)
  let disposed = false
  let scheduled = false
  const update = () => { if (!disposed) refresh(table, ctrl, translate) }
  const schedule = () => {
    if (scheduled || disposed) return
    scheduled = true
    queueMicrotask(() => { scheduled = false; update() })
  }
  update()
  const stop = watch(() => ctrl.showColumnLabels.value, schedule, { flush: 'post' })
  const observer = new MutationObserver(records => {
    const relevant = records.some(record => record.type !== 'attributes'
      || record.target !== table || record.attributeName !== 'class'
      || classesWithoutLabels(record.oldValue) !== classesWithoutLabels(table.getAttribute('class')))
    if (relevant) schedule()
  })
  observer.observe(table, { subtree: true, childList: true, characterData: true, attributes: true, attributeOldValue: true, attributeFilter: ['class', 'colspan', 'rowspan', 'aria-hidden', 'data-column-key'] })
  const dispose = () => {
    if (disposed) return
    disposed = true
    observer.disconnect()
    stop()
    tables.delete(table)
    if (!tables.size) controllerTables.delete(ctrl)
    syncDefault(ctrl)
    table.classList.remove('column-labels')
    for (const body of Array.from(table.tBodies)) {
      for (const row of Array.from(body.rows)) {
        for (const cell of Array.from(row.cells)) cell.removeAttribute('data-column-label')
      }
    }
    disposers.delete(table)
  }
  disposers.set(table, { ctrl, dispose, update })
  return dispose
}

export const vColumnLabels: Directive<HTMLTableElement, ColumnLabelsController> = {
  mounted(table, binding) {
    const translate = (binding.instance as { $t?: (key: string) => string } | null)?.$t
    bindColumnLabels(table, binding.value, translate)
  },
  updated(table, binding) {
    const state = disposers.get(table)
    if (state?.ctrl === binding.value) state.update()
    else {
      const translate = (binding.instance as { $t?: (key: string) => string } | null)?.$t
      bindColumnLabels(table, binding.value, translate)
    }
  },
  beforeUnmount(table) { disposers.get(table)?.dispose() },
}
