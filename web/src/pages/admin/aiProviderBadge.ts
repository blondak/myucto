import type { AiProvider } from '@/api/integrations'

export type AiProviderBadge = 'active' | 'no_key' | null

/**
 * Odznak u tlačítka poskytovatele AI extrakce. „Aktivní" jen tehdy, když přes
 * poskytovatele extrakce opravdu běží: je zvolený pro firmu A má uložený klíč.
 * Zvolený poskytovatel bez klíče dostane „bez klíče", ostatní nic.
 */
export function aiProviderBadge(
  provider: AiProvider,
  activeProvider: AiProvider | null | undefined,
  configured: boolean,
): AiProviderBadge {
  if (!activeProvider || activeProvider !== provider) return null
  return configured ? 'active' : 'no_key'
}
