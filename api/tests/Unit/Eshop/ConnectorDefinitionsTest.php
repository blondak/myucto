<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Integration\ConnectorDefinitions;
use PHPUnit\Framework\TestCase;

final class ConnectorDefinitionsTest extends TestCase
{
    public function testEveryDefinitionUsesOnlyRegisteredTypesFieldsAndOwners(): void
    {
        $definitions = (new ConnectorDefinitions())->all();
        self::assertNotEmpty($definitions);
        $keys = array_column($definitions, 'key');
        self::assertSame(array_values(array_unique($keys)), $keys, 'Klíče konektorů musí být unikátní.');

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            // Stejný tvar, jaký kontroluje IntegrationConnectionService a sloupec connector_key.
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_.-]{1,79}$/D', $key);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $definition['i18n'], $key);
            self::assertNotSame('', $definition['name'], $key);
            self::assertIsBool($definition['available'], $key);
            foreach ($definition['notes'] as $note) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $note, $key);
            }

            foreach ($definition['credentials'] as $field) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,79}$/D', $field['key'], $key);
                self::assertContains($field['type'], ConnectorDefinitions::CREDENTIAL_TYPES, $key);
                self::assertIsBool($field['required'], $key);
                self::assertNotSame('', $field['label'], $key);
            }
            foreach ($definition['mappings'] as $mapping) {
                self::assertArrayHasKey($mapping['type'], ConnectorDefinitions::MAPPING_TYPES, $key);
                self::assertContains($mapping['source'], ['warehouses', 'currencies', 'languages', 'vat_rates'], $key);
            }
            $types = array_column($definition['mappings'], 'type');
            foreach ($definition['mapping_defaults'] as $type => $rule) {
                self::assertContains($type, $types, $key);
                // Buď stejný kód, nebo zřetelná zástupná hodnota, nikdy vymyšlená konkrétní hodnota.
                self::assertMatchesRegularExpression('/^(=|<[^<>]+>)$/D', $rule, $key);
            }
            $fieldKeys = array_column($definition['fields'], 'key');
            self::assertSame(array_values(array_unique($fieldKeys)), $fieldKeys, $key);
            foreach ($definition['fields'] as $field) {
                self::assertArrayHasKey($field['key'], ConnectorDefinitions::FIELDS, $key);
                // Klíč pole musí projít i validací volných polí, jinak by ho legacy cesta odmítla.
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_.]{0,99}$/D', $field['key'], $key);
                self::assertContains($field['default_owner'], ConnectorDefinitions::OWNERS, $key);
            }
        }
    }

    public function testCustomWebhookIsAvailableAndShoptetIsOnlyAnnounced(): void
    {
        $definitions = new ConnectorDefinitions();
        $custom = $definitions->find(ConnectorDefinitions::CUSTOM_WEBHOOK);
        self::assertNotNull($custom);
        self::assertTrue($custom['available']);
        self::assertTrue($custom['free_fields']);
        self::assertSame(['warehouses', 'currencies', 'languages', 'vat_rates'], array_column($custom['mappings'], 'type'));
        self::assertContains('stock.quantity', array_column($custom['fields'], 'key'));
        foreach ($custom['credentials'] as $field) {
            self::assertFalse($field['required'], 'Vlastní napojení nesmí vyžadovat údaje, které zatím nic nečte.');
        }

        $shoptet = $definitions->find('shoptet');
        self::assertNotNull($shoptet);
        self::assertFalse($shoptet['available']);
        self::assertNotContains('webhook_inbound', $shoptet['capabilities'],
            'Shoptet podepisuje webhooky jinak, na obecnou webhook URL se nenapojí.');
        self::assertSame('local', array_column($custom['fields'], 'default_owner', 'key')['order.payment_status']);
        self::assertNull($definitions->find('synthetic.unknown'));
    }

    /**
     * Stejný vektor ověřuje vitest spec výpočtu podpisu v prohlížeči
     * (web/src/utils/__tests__/integrationWebhook.spec.ts). Když se rozejde
     * serverová a prohlížečová strana, spadne jedna z nich.
     */
    public function testWebhookSignatureVectorMatchesBrowserImplementation(): void
    {
        $body = '{"event_id":"evt-synthetic-1","entity_type":"product","entity_id":"SKU-1","event_type":"product.updated","aggregate_version":1}';
        self::assertSame(
            '32404cdce90e08a3a90563123daa684d5a45b9da669cf86adddf6fd254f3a13f',
            hash_hmac('sha256', '1760000000.' . $body, 'synthetic-webhook-secret'),
        );
    }
}
