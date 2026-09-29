import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { runInNewContext } from 'node:vm'
import test from 'node:test'

const script = readFileSync(join(import.meta.dirname, '../public/assets/mcp-oauth-redirect-v1.js'), 'utf8')

test('po souhlasu přejde prohlížeč na adresu OAuth klienta', () => {
  const target = 'https://client.example.test/callback?code=synthetic-code&state=synthetic-state'
  const redirects = []
  class Anchor {}
  const link = new Anchor()
  link.href = target
  runInNewContext(script, {
    HTMLAnchorElement: Anchor,
    document: { getElementById: id => id === 'mcp-oauth-continue' ? link : null },
    window: { location: { replace: url => redirects.push(url) } },
  })
  assert.deepEqual(redirects, [target])
})
