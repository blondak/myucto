<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

/**
 * Jediné místo, které skládá výchozí nastavení nového připojení: definice
 * konektoru (výchozí vlastníci polí, pravidla předvyplnění mapování) a
 * číselníky firmy (výchozí sklad, měna a jazyk). Používá ho formulář „Nové
 * připojení", ukázkové napojení i jeho automatické založení; frontend hodnoty
 * dostává v odpovědi /connectors a jen je přebírá.
 *
 * Místní hodnota se bere vždy z číselníku TÉTO firmy; chybí-li, řádek se
 * vynechá a nic se nevymýšlí. Hodnota v externím systému je buď stejný kód
 * (pravidlo '='), nebo zřetelná zástupná hodnota `<…>`, kterou uživatel nahradí.
 */
final class IntegrationConnectionDefaults
{
    public const SAMPLE_NAME = 'Ukázkové napojení (vzor Shoptet)';
    public const SAME_VALUE = '=';

    public function __construct(
        private readonly ConnectorDefinitions $definitions,
        private readonly IntegrationLocalLookups $lookups,
    ) {}

    /** @return array<string, array<string,mixed>> klíč konektoru → výchozí nastavení (jen dostupné konektory) */
    public function all(int $supplierId): array
    {
        $out = [];
        foreach ($this->definitions->all() as $definition) {
            if ($definition['available']) {
                $out[$definition['key']] = $this->for($definition, $supplierId);
            }
        }
        return $out;
    }

    /** @return array{status:string,mappings:\stdClass,field_ownership:\stdClass,rate_limit_per_minute:int,retention_days:int} */
    public function for(array $definition, int $supplierId): array
    {
        $rules = $definition['mapping_defaults'] ?? [];
        $mappings = new \stdClass();
        foreach ($definition['mappings'] as $mapping) {
            $rule = $rules[$mapping['type']] ?? null;
            if ($rule === null) {
                continue;
            }
            $value = $this->lookups->preferred($supplierId, $mapping['source']);
            if ($value !== null) {
                $mappings->{$mapping['type']} = (object) [$value => $rule === self::SAME_VALUE ? $value : $rule];
            }
        }
        return [
            'status' => 'draft',
            'mappings' => $mappings,
            'field_ownership' => (object) array_column($definition['fields'], 'default_owner', 'key'),
            'rate_limit_per_minute' => 60,
            'retention_days' => 30,
        ];
    }
}
