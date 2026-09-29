import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import test from 'node:test'
import { JSDOM } from 'jsdom'

const script = readFileSync(join(import.meta.dirname, '../public/assets/mcp-consent-v2.js'), 'utf8')

function consentPage(passkey = false) {
  const dom = new JSDOM(`<!doctype html><form id="mcp-consent-form">
    <input name="csrf_token" value="synthetic-csrf">
    <input name="totp_code">
    ${passkey ? '<input id="mcp-step-up-token"><button type="button" id="mcp-passkey-button">Passkey</button><span id="mcp-passkey-status"></span>' : ''}
    <button type="submit" name="decision" value="approve">Povolit</button>
    <button type="submit" name="decision" value="deny">Zamítnout</button>
    <span id="mcp-form-status"></span>
  </form>`, { url: 'https://myucto.example.test/oauth/authorize', runScripts: 'outside-only' })
  dom.window.eval(script)
  return dom
}

function submit(dom, decision) {
  const form = dom.window.document.getElementById('mcp-consent-form')
  const button = form.querySelector(`button[value="${decision}"]`)
  const event = new dom.window.SubmitEvent('submit', { cancelable: true, submitter: button })
  form.dispatchEvent(event)
  return event.defaultPrevented
}

test('souhlas s TOTP odešle formulář jen jednou', () => {
  const dom = consentPage()
  dom.window.document.querySelector('input[name="totp_code"]').value = '123456'
  assert.equal(submit(dom, 'approve'), false)
  assert.equal(submit(dom, 'approve'), true)
  dom.window.close()
})

test('bez passkey důkazu nebo TOTP kódu se souhlas neodešle', () => {
  const dom = consentPage(true)
  assert.equal(submit(dom, 'approve'), true)
  assert.match(dom.window.document.getElementById('mcp-passkey-status').textContent, /Nejdříve ověřte passkey/)
  dom.window.document.querySelector('input[name="totp_code"]').value = '123456'
  assert.equal(submit(dom, 'approve'), false)
  dom.window.close()
})

test('zadání TOTP odstraní starý passkey důkaz i při automatickém vyplnění', () => {
  const dom = consentPage(true)
  const proof = dom.window.document.getElementById('mcp-step-up-token')
  const totp = dom.window.document.querySelector('input[name="totp_code"]')
  proof.value = 'synthetic-old-proof'
  totp.value = '123456'
  totp.dispatchEvent(new dom.window.Event('input', { bubbles: true }))
  assert.equal(proof.value, '')
  assert.match(dom.window.document.getElementById('mcp-passkey-status').textContent, /Použije se kód/)
  proof.value = 'synthetic-autofill-proof'
  assert.equal(submit(dom, 'approve'), false)
  assert.equal(proof.value, '')
  dom.window.close()
})
