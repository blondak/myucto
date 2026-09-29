const button = document.getElementById('mcp-passkey-button')
const status = document.getElementById('mcp-passkey-status') || document.getElementById('mcp-form-status')
const proof = document.getElementById('mcp-step-up-token')
const csrf = document.querySelector('input[name="csrf_token"]')
const form = document.getElementById('mcp-consent-form')
const totp = form.querySelector('input[name="totp_code"]')
let proofIssuedAt = 0
let submitting = false

const decode = value => {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)
  return Uint8Array.from(atob(padded), char => char.charCodeAt(0))
}

const encode = value => {
  let binary = ''
  for (const byte of new Uint8Array(value)) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

const post = async (path, body) => {
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.value },
    body: JSON.stringify(body),
  })
  const result = await response.json().catch(() => null)
  if (!response.ok) {
    throw new Error(result?.error?.message || 'Ověření se nepodařilo. Zkuste to znovu.')
  }
  return result
}

form.addEventListener('submit', event => {
  if (submitting) {
    event.preventDefault()
    return
  }
  if (event.submitter?.value === 'deny') return
  if (totp?.value.trim() && proof?.value) {
    proof.value = ''
    proofIssuedAt = 0
  }
  if (proof?.value && Date.now() - proofIssuedAt > 270000) {
    proof.value = ''
    status.textContent = 'Ověření passkey vypršelo. Ověřte ji prosím znovu.'
  }
  if (button && !proof.value && !totp?.value.trim()) {
    event.preventDefault()
    status.textContent = 'Nejdříve ověřte passkey nebo zadejte nový kód ověřovací aplikace.'
    return
  }
  submitting = true
  status.textContent = 'Povoluji přístup…'
})

totp?.addEventListener('input', () => {
  if (!totp.value.trim() || !proof?.value) return
  proof.value = ''
  proofIssuedAt = 0
  status.textContent = 'Použije se kód ověřovací aplikace.'
})

button?.addEventListener('click', async () => {
  proof.value = ''
  proofIssuedAt = 0
  button.disabled = true
  status.textContent = 'Čekám na ověření passkey…'
  try {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      throw new Error('Tento prohlížeč nepodporuje passkey.')
    }
    const operation = 'api_token.create'
    const flow = await post('/api/auth/webauthn/step-up/options', { operation })
    const options = flow.public_key
    const credential = await navigator.credentials.get({
      publicKey: {
        ...options,
        challenge: decode(options.challenge),
        allowCredentials: (options.allowCredentials || []).map(item => ({ ...item, id: decode(item.id) })),
      },
    })
    if (!credential) throw new Error('Ověření bylo zrušeno.')
    const result = await post('/api/auth/webauthn/step-up/verify', {
      flow_token: flow.flow_token,
      operation,
      credential: {
        id: credential.id,
        rawId: encode(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment,
        clientExtensionResults: credential.getClientExtensionResults(),
        response: {
          clientDataJSON: encode(credential.response.clientDataJSON),
          authenticatorData: encode(credential.response.authenticatorData),
          signature: encode(credential.response.signature),
          userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : null,
        },
      },
    })
    proof.value = result.step_up_token
    if (totp) totp.value = ''
    proofIssuedAt = Date.now()
    status.textContent = 'Passkey ověřeno. Nyní povolte přístup; ověření platí pět minut.'
  } catch (error) {
    status.textContent = error instanceof Error ? error.message : 'Ověření se nepodařilo.'
  } finally {
    button.disabled = false
  }
})
