<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

/**
 * Jediné místo, kde se definují konektory Integračního centra.
 *
 * Definice říká, co konektor od uživatele potřebuje (přístupové údaje), které
 * číselníky se mapují na hodnoty externího systému a o kterých polích se
 * rozhoduje, kdo je jejich vlastníkem. Z téhle třídy čte validace při uložení
 * připojení i editor na stránce, takže nový konektor znamená jen novou položku
 * v {@see self::CONNECTORS}.
 *
 * Texty pro UI jsou v i18n pod `eshop.integrations.connectors.<i18n>` a
 * `eshop.integrations.fields.<pole s podtržítky>`. Český `label` tady slouží
 * jen chybovým hláškám serveru.
 */
final class ConnectorDefinitions
{
    public const CUSTOM_WEBHOOK = 'custom.webhook';

    /** local = pravdou je MyÚčto, remote = externí systém, manual = rozdíl řeší člověk. */
    public const OWNERS = ['local', 'remote', 'manual'];

    public const CREDENTIAL_TYPES = ['text', 'secret', 'url'];

    /**
     * Typy mapování a číselník, ze kterého pocházejí místní hodnoty.
     * `item` je jednotné číslo pro chybové hlášky.
     */
    public const MAPPING_TYPES = [
        'warehouses' => ['source' => 'warehouses', 'label' => 'Sklady', 'item' => 'sklad'],
        'currencies' => ['source' => 'currencies', 'label' => 'Měny', 'item' => 'měna'],
        'languages' => ['source' => 'languages', 'label' => 'Jazyky', 'item' => 'jazyk'],
        'vat_rates' => ['source' => 'vat_rates', 'label' => 'Sazby DPH', 'item' => 'sazba DPH'],
    ];

    /**
     * Pole, u kterých se rozhoduje o vlastníkovi. Vycházejí z toho, co nese
     * změnový feed katalogu (karta, ceny, média, překlady, dostupnost) a co
     * drží prodejní objednávka.
     */
    public const FIELDS = [
        'product.sku' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Kód zboží (SKU)'],
        'product.name' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Název zboží'],
        'product.description' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Popis zboží'],
        'product.seo' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'SEO texty a adresa'],
        'product.ean' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'EAN'],
        'product.manufacturer' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Výrobce'],
        'product.media' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Obrázky a média'],
        'product.weight' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Hmotnost'],
        'product.price' => ['area' => 'price', 'default_owner' => 'local', 'label' => 'Prodejní cena'],
        'product.vat_rate' => ['area' => 'price', 'default_owner' => 'local', 'label' => 'Sazba DPH'],
        'product.visibility' => ['area' => 'product', 'default_owner' => 'local', 'label' => 'Prodejnost karty'],
        'stock.quantity' => ['area' => 'stock', 'default_owner' => 'local', 'label' => 'Skladové množství'],
        'order.status' => ['area' => 'order', 'default_owner' => 'remote', 'label' => 'Stav objednávky'],
        // Úhradu páruje MyÚčto z banky a pokladny, proto je výchozím vlastníkem místní strana.
        'order.payment_status' => ['area' => 'order', 'default_owner' => 'local', 'label' => 'Stav úhrady objednávky'],
        'order.fulfillment_status' => ['area' => 'order', 'default_owner' => 'local', 'label' => 'Stav expedice'],
        'customer.contact' => ['area' => 'order', 'default_owner' => 'remote', 'label' => 'Kontaktní údaje zákazníka'],
    ];

    /**
     * `available = false` je konektor, který se připravuje: UI ho ukáže jako
     * „připravujeme" a nové připojení s ním validace odmítne. `notes` jsou jen
     * informativní odstavce pro UI (i18n `connectors.<i18n>.notes.<note>`),
     * nic se podle nich nevaliduje.
     */
    private const CONNECTORS = [
        self::CUSTOM_WEBHOOK => [
            'available' => true,
            'i18n' => 'custom_webhook',
            'name' => 'Vlastní napojení přes webhook a API',
            'capabilities' => ['webhook_inbound', 'change_feed', 'reconcile'],
            'notes' => [],
            'free_fields' => true,
            'credentials' => [
                ['key' => 'endpoint_url', 'type' => 'url', 'required' => false, 'label' => 'Adresa pro odchozí události'],
                ['key' => 'endpoint_token', 'type' => 'secret', 'required' => false, 'label' => 'Token pro odchozí události'],
            ],
            'mappings' => ['warehouses', 'currencies', 'languages', 'vat_rates'],
            // Předvyplnění podle vzoru e-shopu na Shoptetu (private/SHOPTET-ANALYZA.md § 2.2, 2.5, 8.3):
            // měna je v Shoptetu ISO kód, proto 1:1 ('='); ID skladu (stockId) a kód jazykové
            // verze doložené nejsou, proto zřetelná zástupná hodnota v lomených závorkách.
            'mapping_defaults' => [
                'warehouses' => '<stockId skladu v Shoptetu>',
                'currencies' => '=',
                'languages' => '<kód jazyka v Shoptetu>',
            ],
            'fields' => [
                'product.sku', 'product.name', 'product.description', 'product.seo', 'product.ean',
                'product.manufacturer', 'product.media', 'product.weight', 'product.price', 'product.vat_rate',
                'product.visibility', 'stock.quantity', 'order.status', 'order.payment_status',
                'order.fulfillment_status', 'customer.contact',
            ],
        ],
        'shoptet' => [
            'available' => false,
            'i18n' => 'shoptet',
            'name' => 'Shoptet',
            'capabilities' => [],
            // Shoptet podepisuje webhooky vlastním způsobem (HMAC-SHA1 bez časového
            // razítka), na obecnou webhook URL se tedy nenapojí. Konektor bude mít
            // vlastní příjem a polling změn objednávek.
            'notes' => ['receiver', 'credentials', 'mappings'],
            'free_fields' => false,
            'credentials' => [],
            'mappings' => [],
            'mapping_defaults' => [],
            'fields' => [],
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::CONNECTORS) as $key) {
            $out[] = $this->find($key);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $key): ?array
    {
        $definition = self::CONNECTORS[$key] ?? null;
        if ($definition === null) {
            return null;
        }
        return [
            'key' => $key,
            'available' => $definition['available'],
            'i18n' => $definition['i18n'],
            'name' => $definition['name'],
            'capabilities' => $definition['capabilities'],
            'notes' => $definition['notes'],
            'free_fields' => $definition['free_fields'],
            'credentials' => $definition['credentials'],
            'mappings' => array_map(static fn (string $type): array => [
                'type' => $type,
                'source' => self::MAPPING_TYPES[$type]['source'],
                'label' => self::MAPPING_TYPES[$type]['label'],
            ], $definition['mappings']),
            'mapping_defaults' => $definition['mapping_defaults'],
            'fields' => array_map(static fn (string $field): array => [
                'key' => $field,
                'area' => self::FIELDS[$field]['area'],
                'default_owner' => self::FIELDS[$field]['default_owner'],
                'label' => self::FIELDS[$field]['label'],
            ], $definition['fields']),
        ];
    }
}
