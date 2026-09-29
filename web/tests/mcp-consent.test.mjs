import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import test from 'node:test'
import { JSDOM } from 'jsdom'

const script = readFileSync(join(import.meta.dirname, '../public/assets/mcp-consent-v3.js'), 'utf8')

function consentPage(passkey = false, accessFields = '') {
  const dom = new JSDOM(`<!doctype html><form id="mcp-consent-form">
    <input name="csrf_token" value="synthetic-csrf">
    <input name="totp_code">
    ${accessFields}
    ${passkey ? '<input id="mcp-step-up-token"><button type="button" id="mcp-passkey-button">Passkey</button><span id="mcp-passkey-status"></span>' : ''}
    <button type="submit" name="decision" value="approve">Povolit</button>
    <button type="submit" name="decision" value="deny">Zamítnout</button>
    <span id="mcp-form-status"></span>
  </form>`, { url: 'https://myucto.example.test/oauth/authorize', runScripts: 'outside-only' })
  dom.window.eval(script)
  return dom
}

test('výběr firmy bez práva zápisu odebere zápis z nabídky souhlasu', () => {
  const dom = consentPage(false, `<select name="supplier_id">
    <option value="1" data-can-write="1">Firma se zápisem</option>
    <option value="2" data-can-write="0">Firma pro čtení</option>
  </select><select name="grant_scope">
    <option value="read">Pouze čtení</option>
    <option value="read_write">Čtení a zápis</option>
  </select>`)
  const supplier = dom.window.document.querySelector('select[name="supplier_id"]')
  const grant = dom.window.document.querySelector('select[name="grant_scope"]')
  const writeOption = grant.querySelector('option[value="read_write"]')
  grant.value = 'read_write'
  supplier.value = '2'
  supplier.dispatchEvent(new dom.window.Event('change', { bubbles: true }))
  assert.equal(grant.value, 'read')
  assert.equal(writeOption.disabled, true)
  supplier.value = '1'
  supplier.dispatchEvent(new dom.window.Event('change', { bubbles: true }))
  assert.equal(writeOption.disabled, false)
  dom.window.close()
})

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
