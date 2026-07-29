import { ref } from 'vue'

export interface Toast {
  id: number
  type: 'success' | 'error' | 'info' | 'warning'
  text: string
  action?: { label: string; handler: () => void | Promise<void> }
}

const toasts = ref<Toast[]>([])
let nextId = 1

function push(type: Toast['type'], text: string, ttl = 5000, action?: Toast['action']) {
  const id = nextId++
  toasts.value.push({ id, type, text, action })
  setTimeout(() => {
    const i = toasts.value.findIndex(t => t.id === id)
    if (i !== -1) toasts.value.splice(i, 1)
  }, ttl)
}

function dismiss(id: number) {
  const i = toasts.value.findIndex(t => t.id === id)
  if (i !== -1) toasts.value.splice(i, 1)
}

export function useToast() {
  return {
    toasts,
    success: (t: string, action?: Toast['action']) => push('success', t, action ? 10000 : 5000, action),
    error:   (t: string) => push('error', t, 8000),
    info:    (t: string) => push('info', t),
    warning: (t: string) => push('warning', t, 6000),
    dismiss,
  }
}
