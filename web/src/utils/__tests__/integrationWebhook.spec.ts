// @vitest-environment node
import { describe, expect, it } from 'vitest'
import {
  currentTimestamp, isTimestampFresh, signWebhook, signaturesMatch, signedCurl, validateWebhookEvent, webhookUrl,
} from '../integrationWebhook'

const BODY = '{"event_id":"evt-synthetic-1","entity_type":"product","entity_id":"SKU-1","event_type":"product.updated","aggregate_version":1}'
// Stejný vektor ověřuje PHP (api/tests/Unit/Eshop/ConnectorDefinitionsTest.php) přes hash_hmac.
const EXPECTED = '32404cdce90e08a3a90563123daa684d5a45b9da669cf86adddf6fd254f3a13f'

describe('integrationWebhook', () => {
  it('computes the same HMAC-SHA256 signature as the server', async () => {
    await expect(signWebhook('synthetic-webhook-secret', '1760000000', BODY)).resolves.toBe(EXPECTED)
    await expect(signWebhook('synthetic-webhook-secret', '1760000001', BODY)).resolves.not.toBe(EXPECTED)
  })

  it('compares signatures with or without the sha256 prefix', () => {
    expect(signaturesMatch(EXPECTED, `sha256=${EXPECTED}`)).toBe(true)
    expect(signaturesMatch(EXPECTED, EXPECTED.toUpperCase())).toBe(true)
    expect(signaturesMatch(EXPECTED, 'sha256=' + '0'.repeat(64))).toBe(false)
    expect(signaturesMatch(EXPECTED, 'sha256=abc')).toBe(false)
  })

  it('validates the body with the same rules as the inbox', () => {
    expect(validateWebhookEvent(BODY)).toEqual([])
    expect(validateWebhookEvent('{')).toEqual([{ code: 'invalid_json' }])
    expect(validateWebhookEvent('[1]')).toEqual([{ code: 'not_object' }])
    expect(validateWebhookEvent('{"event_id":"x"}').map(issue => issue.field))
      .toEqual(['entity_type', 'entity_id', 'event_type', 'aggregate_version'])
    const invalid = JSON.stringify({ event_id: ' ', entity_type: 'Product', entity_id: 'x'.repeat(191), event_type: 'updated!', aggregate_version: 0 })
    expect(validateWebhookEvent(invalid).map(issue => issue.field))
      .toEqual(['event_id', 'entity_id', 'entity_type', 'event_type', 'aggregate_version'])
    expect(validateWebhookEvent(JSON.stringify({ event_id: 'e', entity_type: 'order', entity_id: 7, event_type: 'order.created', aggregate_version: '3' })))
      .toEqual([])
    expect(validateWebhookEvent('{"a":"' + 'x'.repeat(1048576) + '"}')).toEqual([{ code: 'too_large' }])
  })

  it('accepts timestamps only within five minutes', () => {
    const now = 1_760_000_000_000
    expect(currentTimestamp(now)).toBe('1760000000')
    expect(isTimestampFresh('1760000300', now)).toBe(true)
    expect(isTimestampFresh('1759999700', now)).toBe(true)
    expect(isTimestampFresh('1760000301', now)).toBe(false)
    expect(isTimestampFresh('17600000.5', now)).toBe(false)
  })

  it('builds a ready command that carries the signature but never the secret', () => {
    const url = webhookUrl('https://ucto.example.test', '00000000-0000-4000-8000-000000000007')
    const command = signedCurl(url, '1760000000', EXPECTED, BODY)
    expect(url).toBe('https://ucto.example.test/api/public/integrations/webhooks/00000000-0000-4000-8000-000000000007')
    expect(command).toContain(`X-Integration-Signature: sha256=${EXPECTED}`)
    expect(command).toContain("X-Integration-Timestamp: 1760000000")
    expect(command).not.toContain('synthetic-webhook-secret')
  })
})
