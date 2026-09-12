<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import IntegrationCodeBlock from './IntegrationCodeBlock.vue'
import {
  EXAMPLE_EVENT, currentTimestamp, isTimestampFresh, signWebhook, signaturesMatch, signedCurl,
  validateWebhookEvent, webCryptoAvailable, type WebhookIssue,
} from '@/utils/integrationWebhook'

const props = defineProps<{ url: string }>()
const { t } = useI18n()

// Secret žije jen v této komponentě: neposílá se na server, neukládá se a
// nikam se nevypisuje. Výsledkem je podpis, který platí jen pro dané tělo a čas.
const secret = ref('')
const timestamp = ref(currentTimestamp())
const body = ref(JSON.stringify(EXAMPLE_EVENT))
const provided = ref('')
const signature = ref<string | null>(null)
const issues = ref<WebhookIssue[]>([])
const fresh = ref(true)
const error = ref('')
const computing = ref(false)

function issueText(issue: WebhookIssue) {
  return t(`eshop.integrations.tester_issues.${issue.code}`, { field: issue.field ?? '' })
}

async function compute() {
  error.value = ''
  signature.value = null
  if (secret.value === '') { error.value = t('eshop.integrations.tester_secret_required'); return }
  if (!webCryptoAvailable()) { error.value = t('eshop.integrations.tester_crypto_unavailable'); return }
  computing.value = true
  try {
    issues.value = validateWebhookEvent(body.value)
    fresh.value = isTimestampFresh(timestamp.value)
    signature.value = await signWebhook(secret.value, timestamp.value, body.value)
  } catch {
    error.value = t('eshop.integrations.tester_crypto_unavailable')
  } finally {
    computing.value = false
  }
}

function now() { timestamp.value = currentTimestamp() }
</script>

<template>
  <div class="space-y-3" data-test="signature-tester">
    <p class="text-xs text-neutral-500">{{ t('eshop.integrations.tester_hint') }}</p>
    <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
      <label class="min-w-0 text-sm">
        <span class="mb-1 block font-medium">{{ t('eshop.integrations.tester_secret') }}</span>
        <input v-model="secret" type="password" autocomplete="off" spellcheck="false" class="form-input w-full font-mono" data-test="tester-secret" @keydown.enter.prevent="compute" />
      </label>
      <label class="min-w-0 text-sm">
        <span class="mb-1 block font-medium">{{ t('eshop.integrations.tester_timestamp') }}</span>
        <span class="flex flex-wrap gap-2">
          <input v-model="timestamp" inputmode="numeric" class="form-input min-w-0 flex-1 font-mono" data-test="tester-timestamp" @keydown.enter.prevent="compute" />
          <button type="button" :class="btnOutlineSm('neutral')" data-test="tester-now" @click="now">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('eshop.integrations.tester_now') }}
          </button>
        </span>
      </label>
    </div>
    <label class="block text-sm">
      <span class="mb-1 block font-medium">{{ t('eshop.integrations.tester_body') }}</span>
      <textarea v-model="body" rows="6" spellcheck="false" class="form-textarea w-full font-mono text-xs" data-test="tester-body"></textarea>
    </label>
    <label class="block text-sm">
      <span class="mb-1 block font-medium">{{ t('eshop.integrations.tester_compare') }}</span>
      <input v-model="provided" spellcheck="false" class="form-input w-full font-mono text-xs" placeholder="sha256=…" data-test="tester-compare" @keydown.enter.prevent="compute" />
    </label>
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('primary')" :disabled="computing" data-test="tester-compute" @click="compute">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>{{ t('eshop.integrations.tester_compute') }}
      </button>
    </div>
    <p v-if="error" class="text-sm text-danger-700" data-test="tester-error">{{ error }}</p>
    <div v-if="signature" class="space-y-2">
      <IntegrationCodeBlock :code="`sha256=${signature}`" :label="t('eshop.integrations.tester_result')" test-id="tester-signature" />
      <p v-if="provided.trim()" class="text-sm font-medium" :class="signaturesMatch(signature, provided) ? 'text-success-700' : 'text-danger-700'" data-test="tester-match">
        {{ t(signaturesMatch(signature, provided) ? 'eshop.integrations.tester_match' : 'eshop.integrations.tester_mismatch') }}
      </p>
      <p v-if="!fresh" class="text-sm text-warning-700" data-test="tester-stale">{{ t('eshop.integrations.tester_timestamp_stale') }}</p>
      <ul v-if="issues.length" class="list-disc space-y-0.5 pl-5 text-sm text-danger-700" data-test="tester-issues">
        <li v-for="(issue, index) in issues" :key="index">{{ issueText(issue) }}</li>
      </ul>
      <p v-else class="text-sm text-success-700" data-test="tester-body-ok">{{ t('eshop.integrations.tester_body_ok') }}</p>
      <IntegrationCodeBlock :code="signedCurl(props.url, timestamp, signature, body)" :label="t('eshop.integrations.tester_curl')" test-id="tester-curl" />
    </div>
  </div>
</template>
