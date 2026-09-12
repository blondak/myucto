<script setup lang="ts">
// Návodné texty pro vývojáře jsou záměrně jen česky přímo v šabloně (výjimka
// z i18n schválená uživatelem); ovládací prvky zůstávají přes t().
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IntegrationConnection } from '@/api/eshopIntegrations'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import IntegrationCodeBlock from './IntegrationCodeBlock.vue'
import IntegrationSignatureTester from './IntegrationSignatureTester.vue'
import {
  EXAMPLE_ACCEPTED, EXAMPLE_CHANGE_FEED, EXAMPLE_EVENT, changeFeedSample, curlSample, nodeSample, phpSample, webhookUrl,
} from '@/utils/integrationWebhook'

const props = defineProps<{
  connection: IntegrationConnection
  supplierId: number | null
  canWrite: boolean
  acting: boolean
  revealedSecret: string | null
  expandExample?: boolean
}>()
const emit = defineEmits<{ rotate: [] }>()
const { t } = useI18n()

const origin = window.location.origin
const url = computed(() => webhookUrl(origin, props.connection.connection_uuid))
const tab = ref<'curl' | 'php' | 'node'>('curl')
const samples = computed(() => ({ curl: curlSample(url.value), php: phpSample(url.value), node: nodeSample(url.value) }))
const tabLabels = { curl: 'curl', php: 'PHP', node: 'Node.js' } as const
const exampleBody = JSON.stringify(EXAMPLE_EVENT, null, 2)
const acceptedBody = JSON.stringify(EXAMPLE_ACCEPTED)
const feedSample = computed(() => changeFeedSample(origin, props.supplierId))
const feedResponse = JSON.stringify(EXAMPLE_CHANGE_FEED, null, 2)
const eventFields = [
  { key: 'event_id', text: 'Jednoznačné ID události v externím systému, nejvýše 190 znaků. Podle něj server pozná opakované doručení.' },
  { key: 'entity_type', text: 'Typ entity malými písmeny, například product, variant nebo order (nejvýše 60 znaků, a–z, 0–9, tečka, pomlčka, podtržítko).' },
  { key: 'entity_id', text: 'ID entity v externím systému, nejvýše 190 znaků.' },
  { key: 'event_type', text: 'Druh změny, například product.updated nebo order.created.' },
  { key: 'aggregate_version', text: 'Celé číslo od 1, které s každou změnou téže entity roste. Událost se starší nebo stejnou verzí, než jaká už byla zpracována, se při zpracování přeskočí.' },
]
const responses = [
  { code: '202', text: 'Událost je přijatá a uložená do fronty příchozích událostí (duplicate: false).' },
  { code: '202', text: 'Stejná událost (stejné event_id i stejné tělo) už dorazila dřív. Nic se neuloží podruhé a odpověď má duplicate: true. Opakované odeslání je proto bezpečné.' },
  { code: '400', text: 'Tělo není platný JSON objekt, chybí povinné pole nebo má neplatnou hodnotu (webhook_invalid).' },
  { code: '401', text: 'Připojení neexistuje nebo není aktivní, secret ještě nebyl vytvořen, časové razítko je mimo 5 minut nebo nesedí podpis (webhook_unauthorized). Z bezpečnostních důvodů se neříká, co přesně.' },
  { code: '409', text: 'Pod stejným event_id už přišla událost s jiným obsahem (webhook_idempotency_conflict). Novou změnu pošlete s novým event_id a vyšší aggregate_version.' },
  { code: '413', text: 'Tělo je větší než 1 MiB (payload_too_large).' },
]
const active = computed(() => props.connection.status === 'active')
</script>

<template>
  <div class="space-y-4" data-test="developer-panel">
    <IntegrationCodeBlock :code="url" :label="t('eshop.integrations.webhook_url')" test-id="webhook-url" />

    <ul class="space-y-1 text-sm">
      <li class="flex items-start gap-2" :class="active ? 'text-success-700' : 'text-warning-700'" data-test="ready-active">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="active ? ICONS.checkCircle : ICONS.bell" /></svg>
        {{ t(active ? 'eshop.integrations.ready_active' : 'eshop.integrations.ready_not_active') }}
      </li>
      <li class="flex items-start gap-2" :class="connection.webhook_configured ? 'text-success-700' : 'text-warning-700'" data-test="ready-secret">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="connection.webhook_configured ? ICONS.checkCircle : ICONS.bell" /></svg>
        {{ t(connection.webhook_configured ? 'eshop.integrations.ready_secret' : 'eshop.integrations.ready_no_secret') }}
      </li>
    </ul>

    <div v-if="!connection.webhook_configured" class="space-y-2 rounded-md border border-warning-200 bg-warning-50 p-3" data-test="secret-first">
      <p class="text-sm font-semibold text-warning-700">{{ t('eshop.integrations.secret_first_title') }}</p>
      <p class="text-xs text-warning-700">{{ t('eshop.integrations.secret_first_hint') }}</p>
      <button v-if="canWrite" type="button" :disabled="acting" :class="btnFilled('primary')" data-test="rotate-secret" @click="emit('rotate')">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>{{ t('eshop.integrations.create_secret') }}
      </button>
    </div>
    <div v-else class="flex flex-wrap items-center gap-2">
      <button v-if="canWrite" type="button" :disabled="acting" :class="btnOutline('warning')" data-test="rotate-secret" @click="emit('rotate')">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('eshop.integrations.rotate_secret') }}
      </button>
      <span class="text-xs text-neutral-500">{{ t('eshop.integrations.rotate_hint') }}</span>
    </div>
    <div v-if="revealedSecret" class="space-y-2 rounded-md border border-warning-200 bg-warning-50 p-3">
      <p class="text-xs font-medium text-warning-700">{{ t('eshop.integrations.secret_once') }}</p>
      <IntegrationCodeBlock :code="revealedSecret" :label="t('eshop.integrations.webhook_secret')" test-id="webhook-secret" />
    </div>

    <details class="rounded-md border border-neutral-200" open>
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Hlavičky a podpis</summary>
      <div class="space-y-3 border-t border-neutral-200 p-3 text-sm">
        <p>Každý požadavek je <code>POST</code> na adresu výše a musí nést tyto hlavičky:</p>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-neutral-200 text-xs text-neutral-500"><th class="p-2">Hlavička</th><th class="p-2">Význam</th></tr></thead>
            <tbody>
              <tr class="border-b border-neutral-100"><td class="whitespace-nowrap p-2 font-mono text-xs">Content-Type: application/json</td><td class="p-2">Tělo je JSON objekt v UTF-8, nejvýše 1 MiB.</td></tr>
              <tr class="border-b border-neutral-100"><td class="whitespace-nowrap p-2 font-mono text-xs">X-Integration-Timestamp</td><td class="p-2">Unixový čas odeslání v sekundách, například <code>1760000000</code>. Server přijme odchylku od svého času nejvýše 5 minut.</td></tr>
              <tr><td class="whitespace-nowrap p-2 font-mono text-xs">X-Integration-Signature</td><td class="p-2"><code>sha256=</code> a za ním 64 znaků hexadecimálního HMAC-SHA256 z řetězce <code>časové_razítko.tělo</code>, klíčem je secret tohoto připojení.</td></tr>
            </tbody>
          </table>
        </div>
        <pre class="overflow-x-auto rounded bg-neutral-50 p-3 text-xs">signature = hex(HMAC_SHA256(secret, timestamp + "." + body))
X-Integration-Signature: sha256=&lt;signature&gt;</pre>
        <p class="text-xs text-neutral-500">Podepisuje se přesně odeslané tělo, bajt po bajtu. Když tělo po výpočtu podpisu přeformátujete (mezery, pořadí klíčů, escapování diakritiky), podpis přestane sedět. Secret nikdy neposílejte v požadavku ani ho neukládejte do zdrojového kódu.</p>
      </div>
    </details>

    <details class="rounded-md border border-neutral-200" :open="expandExample" data-test="example-details">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Tělo události</summary>
      <div class="space-y-3 border-t border-neutral-200 p-3 text-sm">
        <p>Ukázka je objednávka ve stylu Shoptetu převedená do našeho kontraktu (syntetická data). Skutečný Shoptet náš webhook sám nevolá, své webhooky podepisuje HMAC-SHA1 v hlavičce <code>Shoptet-Webhook-Signature</code>. Ukázka je proto vzor pro prostředníka nebo vlastní skript, který objednávky ze Shoptetu přeposílá sem.</p>
        <p>Povinná pole jsou <code>event_id</code>, <code>entity_type</code>, <code>entity_id</code>, <code>event_type</code> a <code>aggregate_version</code>. Další pole, například <code>payload</code> nebo <code>occurred_at</code>, můžete poslat libovolně; uloží se spolu s událostí a zpracuje je konektor.</p>
        <dl class="space-y-1.5">
          <div v-for="field in eventFields" :key="field.key" class="grid grid-cols-1 gap-1 sm:grid-cols-[11rem_minmax(0,1fr)]">
            <dt class="font-mono text-xs">{{ field.key }}</dt>
            <dd class="text-neutral-600">{{ field.text }}</dd>
          </div>
        </dl>
        <IntegrationCodeBlock :code="exampleBody" label="Ukázka těla" test-id="example-body" />
      </div>
    </details>

    <details class="rounded-md border border-neutral-200">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Ukázky odeslání (curl, PHP, Node.js)</summary>
      <div class="space-y-3 border-t border-neutral-200 p-3 text-sm">
        <p class="text-xs text-neutral-500">Ukázky čtou secret z proměnné prostředí <code>WEBHOOK_SECRET</code>. Adresa je už doplněná pro toto připojení.</p>
        <div class="inline-flex flex-wrap overflow-hidden rounded-md border border-neutral-300" role="tablist">
          <button v-for="key in (['curl', 'php', 'node'] as const)" :key="key" type="button" role="tab" :aria-selected="tab === key" class="whitespace-nowrap px-3 py-1 text-xs font-medium" :class="tab === key ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-50'" :data-test="`sample-tab-${key}`" @click="tab = key">{{ tabLabels[key] }}</button>
        </div>
        <IntegrationCodeBlock :code="samples[tab]" :label="tabLabels[tab]" test-id="sample-code" />
      </div>
    </details>

    <details class="rounded-md border border-neutral-200">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Odpovědi, opakování a pořadí</summary>
      <div class="space-y-3 border-t border-neutral-200 p-3 text-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-neutral-200 text-xs text-neutral-500"><th class="p-2">HTTP</th><th class="p-2">Význam</th></tr></thead>
            <tbody>
              <tr v-for="(response, index) in responses" :key="index" class="border-b border-neutral-100 last:border-0">
                <td class="whitespace-nowrap p-2 font-mono text-xs">{{ response.code }}</td>
                <td class="p-2">{{ response.text }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <IntegrationCodeBlock :code="acceptedBody" label="Odpověď 202" />
        <p><strong>Opakované doručení:</strong> když odesílatel neví, jestli událost dorazila (výpadek sítě, timeout), pošle ji znovu se stejným <code>event_id</code> a stejným tělem. Server ji pozná a vrátí <code>duplicate: true</code>. Na chybu 5xx nebo výpadek reagujte opakováním s rostoucí pauzou.</p>
        <p><strong>Pořadí změn:</strong> o pořadí rozhoduje <code>aggregate_version</code>, ne čas doručení. Když dorazí starší verze po novější, při zpracování se přeskočí, takže pozdě doručená stará změna nepřepíše novější stav.</p>
        <p class="text-neutral-600">Přijaté události čekají ve frontě příchozích událostí. Zapisovat je do katalogu a objednávek bude konkrétní konektor; do té doby je v diagnostice uvidíte jako čekající.</p>
      </div>
    </details>

    <details class="rounded-md border border-neutral-200">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Stahování změn katalogu (change feed)</summary>
      <div class="space-y-3 border-t border-neutral-200 p-3 text-sm">
        <p>Změny katalogu si externí systém stahuje sám přes veřejné API. Každá změna karty, ceny, obrázku, překladu, zásoby nebo rezervace dostane rostoucí kurzor, takže se nic neztratí ani při výpadku.</p>
        <p>
          Potřebujete API token s právem číst e-shop. Vytvoříte ho v profilu v sekci API tokeny.
          <RouterLink :to="{ name: 'profile-api-tokens' }" class="font-medium text-primary-700 underline">{{ t('eshop.integrations.open_api_tokens') }}</RouterLink>
        </p>
        <p v-if="supplierId" class="text-neutral-600">Pokud token není vázaný na jednu firmu, pošlete hlavičku <code>X-Supplier-Id: {{ supplierId }}</code> (ID této firmy).</p>
        <IntegrationCodeBlock :code="feedSample" label="curl" test-id="feed-sample" />
        <p><code>after_cursor</code> je poslední zpracovaný kurzor (na začátku 0), <code>limit</code> je 1 až 1000 (výchozí 250). Po každé stránce si uložte <code>next_cursor</code> a pokračujte od něj; dokud je <code>has_more</code> true, načtěte hned další stránku. Položka říká, která karta (<code>entity_id</code>) se změnila a v jaké oblasti (<code>source_area</code>); aktuální data karty si pak načtete přes <code>POST /api/v1/catalog/products/batch</code>. Hodnota <code>tombstone</code> znamená smazanou nebo vyřazenou kartu.</p>
        <IntegrationCodeBlock :code="feedResponse" label="Ukázka odpovědi" />
        <p class="text-neutral-600">Odpověď <strong>410</strong> znamená, že váš kurzor je starší než uchovávaná historie změn (viz uchování záznamů v kroku 6). Stáhněte celý katalog znovu přes <code>POST /api/v1/catalog/products/batch</code> a pokračujte od <code>minimum_cursor</code>. Chyba 400 je neplatný kurzor nebo limit, 403 chybějící oprávnění nebo vypnutý sklad.</p>
      </div>
    </details>

    <details class="rounded-md border border-neutral-200">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold">Co dělá porovnání úplnosti</summary>
      <div class="border-t border-neutral-200 p-3 text-sm">
        <p>Porovnání projde propojené identity tohoto připojení (které externí ID patří ke které místní kartě) a ověří, že místní karty, na které odkazují, pořád existují. Chybějící hlásí jako neúplné mapování a připojení přepne do stavu Chyba. Běží na pozadí po dávkách a jeho průběh uvidíte v diagnostice; pro aktivní připojení se spouští i pravidelně. Obsah externího systému nestahuje ani nemění.</p>
      </div>
    </details>

    <section class="rounded-md border border-primary-200 p-3">
      <h4 class="mb-2 text-sm font-semibold">{{ t('eshop.integrations.tester_title') }}</h4>
      <IntegrationSignatureTester :url="url" />
    </section>
  </div>
</template>
