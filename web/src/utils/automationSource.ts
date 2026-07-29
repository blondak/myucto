import type { AutomationSource } from '@/api/automation'

export function automationSourceClass(source: AutomationSource): string {
  return ({
    rule: 'bg-primary-50 text-primary-700 hover:text-primary-800',
    learned: 'bg-primary-100 text-primary-800 hover:text-primary-900',
    detector: 'bg-success-50 text-success-600 hover:text-success-700',
    transfer: 'bg-sky-50 text-sky-700 hover:text-sky-800',
    matched: 'bg-emerald-50 text-emerald-700 hover:text-emerald-800',
    schedule: 'bg-warning-50 text-warning-700 hover:text-warning-800',
    ai: 'bg-violet-50 text-violet-700 hover:text-violet-800',
    document: 'bg-neutral-100 text-neutral-700 hover:text-neutral-800',
  } satisfies Record<AutomationSource, string>)[source]
}
