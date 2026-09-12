/**
 * Kontrakt příchozího webhooku Integračního centra pro stránku vývojáře.
 *
 * Pravidla kopírují serverovou stranu (IntegrationWebhookAction,
 * IntegrationWebhookService, IntegrationInboxService). Výpočet podpisu běží
 * jen v prohlížeči přes Web Crypto: secret nikdy neopustí stránku a server
 * nenabízí žádný endpoint, který by se dal zneužít k podepisování cizích zpráv.
 * Stejný testovací vektor ověřuje PHP i vitest, takže se obě strany nerozejdou.
 */

export const WEBHOOK_MAX_CLOCK_SKEW = 300
export const WEBHOOK_MAX_BYTES = 1048576
export const WEBHOOK_REQUIRED_FIELDS = ['event_id', 'entity_type', 'entity_id', 'event_type', 'aggregate_version'] as const

export type WebhookIssueCode = 'too_large' | 'invalid_json' | 'not_object' | 'missing' | 'invalid'

export interface WebhookIssue {
  code: WebhookIssueCode
  field?: string
}

const ENTITY_TYPE = /^[a-z][a-z0-9_.-]{0,59}$/
const EVENT_TYPE = /^[a-z][a-z0-9_.-]{0,99}$/

const byteLength = (value: string) => new TextEncoder().encode(value).length

function scalarText(value: unknown): string | null {
  if (typeof value === 'string') return value.trim()
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return null
}

export function validateWebhookEvent(body: string): WebhookIssue[] {
  if (byteLength(body) > WEBHOOK_MAX_BYTES) return [{ code: 'too_large' }]
  let parsed: unknown
  try { parsed = JSON.parse(body) } catch { return [{ code: 'invalid_json' }] }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return [{ code: 'not_object' }]
  const event = parsed as Record<string, unknown>
  const issues: WebhookIssue[] = []
  for (const field of WEBHOOK_REQUIRED_FIELDS) {
    if (!(field in event)) issues.push({ code: 'missing', field })
  }
  if (issues.length) return issues

  const identity = (field: 'event_id' | 'entity_id') => {
    const text = scalarText(event[field])
    if (text === null || text === '' || byteLength(text) > 190) issues.push({ code: 'invalid', field })
  }
  identity('event_id')
  identity('entity_id')
  const entityType = scalarText(event.entity_type)
  if (entityType === null || !ENTITY_TYPE.test(entityType)) issues.push({ code: 'invalid', field: 'entity_type' })
  const eventType = scalarText(event.event_type)
  if (eventType === null || !EVENT_TYPE.test(eventType)) issues.push({ code: 'invalid', field: 'event_type' })
  const version = scalarText(event.aggregate_version)
  if (version === null || !/^[0-9]+$/.test(version) || Number(version) < 1) {
    issues.push({ code: 'invalid', field: 'aggregate_version' })
  }
  return issues
}

export function currentTimestamp(nowMs = Date.now()): string {
  return String(Math.floor(nowMs / 1000))
}

export function isTimestampFresh(timestamp: string, nowMs = Date.now()): boolean {
  if (!/^[0-9]+$/.test(timestamp)) return false
  return Math.abs(Math.floor(nowMs / 1000) - Number(timestamp)) <= WEBHOOK_MAX_CLOCK_SKEW
}

export function webCryptoAvailable(): boolean {
  return typeof globalThis.crypto?.subtle?.importKey === 'function'
}

/** Hexadecimální HMAC-SHA256 z „{timestamp}.{body}", tedy hodnota za „sha256=". */
export async function signWebhook(secret: string, timestamp: string, body: string): Promise<string> {
  if (!webCryptoAvailable()) throw new Error('web_crypto_unavailable')
  const encoder = new TextEncoder()
  const key = await globalThis.crypto.subtle.importKey(
    'raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  )
  const signature = await globalThis.crypto.subtle.sign('HMAC', key, encoder.encode(`${timestamp}.${body}`))
  return Array.from(new Uint8Array(signature), byte => byte.toString(16).padStart(2, '0')).join('')
}

/** Porovná podpis nezávisle na předponě „sha256=" a velikosti písmen. */
export function signaturesMatch(expectedHex: string, provided: string): boolean {
  const normalized = provided.trim().replace(/^sha256=/i, '').toLowerCase()
  return normalized.length === 64 && normalized === expectedHex.toLowerCase()
}

/**
 * Ukázka: objednávka ve stylu Shoptetu převedená do našeho kontraktu. Pole
 * `payload` napodobují detail objednávky Shoptetu (private/SHOPTET-ANALYZA.md
 * § 2.3), data jsou syntetická. Skutečný Shoptet náš webhook sám nevolá.
 */
export const EXAMPLE_EVENT = {
  event_id: 'shoptet-order-2026000123-1',
  entity_type: 'order',
  entity_id: '2026000123',
  event_type: 'order.created',
  aggregate_version: 1,
  occurred_at: '2026-09-12T10:15:00+02:00',
  payload: {
    code: '2026000123',
    status: { id: -1 },
    currency: { code: 'CZK' },
    price: { withVat: '1210.00', withoutVat: '1000.00', vat: '210.00' },
    paid: false,
    language: 'cs',
    creationTime: '2026-09-12T10:14:52+02:00',
    customer: { email: 'zakaznik@example.test', billingAddress: { fullName: 'Jana Příkladová', city: 'Praha', zip: '11000', countryCode: 'CZ' } },
    items: [{ itemType: 'product', code: 'SKU-1001', amount: '2', vatRate: '21', itemPrice: { withVat: '605.00' } }],
  },
}

export const EXAMPLE_ACCEPTED = { accepted: true, duplicate: false, id: 42 }

export const EXAMPLE_CHANGE_FEED = {
  cursor_expired: false,
  snapshot_required: false,
  minimum_cursor: 0,
  next_cursor: 1289,
  has_more: false,
  items: [
    { cursor: 1288, entity_type: 'stock_item', entity_id: 512, change_type: 'upsert', source_area: 'price', occurred_at: '2026-09-12 10:15:00.000000' },
    { cursor: 1289, entity_type: 'stock_item', entity_id: 513, change_type: 'tombstone', source_area: 'product', occurred_at: '2026-09-12 10:16:00.000000' },
  ],
}

export function webhookUrl(origin: string, connectionUuid: string): string {
  return `${origin}/api/public/integrations/webhooks/${connectionUuid}`
}

export function changeFeedUrl(origin: string): string {
  return `${origin}/api/v1/catalog/changes?after_cursor=0&limit=250`
}

const shellQuote = (value: string) => `'${value.replace(/'/g, `'\\''`)}'`

export function curlSample(url: string): string {
  const body = JSON.stringify(EXAMPLE_EVENT)
  return [
    'SECRET="$WEBHOOK_SECRET"',
    `BODY=${shellQuote(body)}`,
    'TS=$(date +%s)',
    'SIG=$(printf \'%s.%s\' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed \'s/^.* //\')',
    `curl -sS -X POST ${shellQuote(url)} \\`,
    '  -H \'Content-Type: application/json\' \\',
    '  -H "X-Integration-Timestamp: $TS" \\',
    '  -H "X-Integration-Signature: sha256=$SIG" \\',
    '  --data-binary "$BODY"',
  ].join('\n')
}

export function phpSample(url: string): string {
  return [
    '<?php',
    "$secret = getenv('WEBHOOK_SECRET');",
    `$event = json_decode('${JSON.stringify(EXAMPLE_EVENT)}', true, 64, JSON_THROW_ON_ERROR);`,
    '$body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);',
    '$timestamp = (string) time();',
    "$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);",
    '',
    `$ch = curl_init(${JSON.stringify(url)});`,
    'curl_setopt_array($ch, [',
    '    CURLOPT_POST => true,',
    '    CURLOPT_POSTFIELDS => $body,',
    '    CURLOPT_RETURNTRANSFER => true,',
    '    CURLOPT_HTTPHEADER => [',
    "        'Content-Type: application/json',",
    "        'X-Integration-Timestamp: ' . $timestamp,",
    "        'X-Integration-Signature: sha256=' . $signature,",
    '    ],',
    ']);',
    '$response = curl_exec($ch);',
    '$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);',
  ].join('\n')
}

export function nodeSample(url: string): string {
  return [
    "import { createHmac } from 'node:crypto'",
    '',
    'const secret = process.env.WEBHOOK_SECRET',
    `const body = JSON.stringify(${JSON.stringify(EXAMPLE_EVENT, null, 2)})`,
    'const timestamp = Math.floor(Date.now() / 1000).toString()',
    "const signature = createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex')",
    '',
    `const response = await fetch(${JSON.stringify(url)}, {`,
    "  method: 'POST',",
    '  headers: {',
    "    'Content-Type': 'application/json',",
    "    'X-Integration-Timestamp': timestamp,",
    "    'X-Integration-Signature': `sha256=${signature}`,",
    '  },',
    '  body,',
    '})',
    'console.log(response.status, await response.json())',
  ].join('\n')
}

export function changeFeedSample(origin: string, supplierId: number | null): string {
  const lines = [
    `curl -sS ${shellQuote(changeFeedUrl(origin))} \\`,
    '  -H "Authorization: Bearer $API_TOKEN"',
  ]
  if (supplierId) {
    lines[1] += ' \\'
    lines.push(`  -H 'X-Supplier-Id: ${supplierId}'`)
  }
  return lines.join('\n')
}

/** Hotový příkaz s již spočítaným podpisem. Obsahuje podpis, nikdy secret. */
export function signedCurl(url: string, timestamp: string, signatureHex: string, body: string): string {
  return [
    `curl -sS -X POST ${shellQuote(url)} \\`,
    '  -H \'Content-Type: application/json\' \\',
    `  -H 'X-Integration-Timestamp: ${timestamp}' \\`,
    `  -H 'X-Integration-Signature: sha256=${signatureHex}' \\`,
    `  --data-binary ${shellQuote(body)}`,
  ].join('\n')
}
