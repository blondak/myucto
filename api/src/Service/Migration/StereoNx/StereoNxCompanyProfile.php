<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Pohoda\PartnerImporter;

/** Výběr běžných kontaktních údajů; účetní a daňové volby se nepřebírají. */
final class StereoNxCompanyProfile
{
    public const FIELDS = ['company_name', 'dic', 'street', 'city', 'zip', 'email', 'phone', 'web'];

    public static function matchesCompany(array $identity, array $target): bool
    {
        $source = PartnerImporter::ico((string) ($identity['ico'] ?? ''));
        return $source !== '' && $source === PartnerImporter::ico((string) ($target['ic'] ?? ''));
    }

    /** @return array<string,string> */
    public static function suggestions(array $identity, array $target): array
    {
        if (!self::matchesCompany($identity, $target)) return [];
        $source = (array) ($identity['company_profile'] ?? []);
        $source['company_name'] = $source['company_name'] ?? $identity['name'] ?? '';
        $source['dic'] = $identity['dic'] ?? '';
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $source[$field] ?? null;
            if (is_string($value) && trim($value) !== '' && trim((string) ($target[$field] ?? '')) !== trim($value)) {
                $out[$field] = trim($value);
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function selected(array $identity, array $target, array $fields, array $expected): array
    {
        $suggestions = self::suggestions($identity, $target);
        $out = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)
                || !array_key_exists($field, $expected) || !is_string($expected[$field])) {
                throw new StereoNxException('company_profile_selection', 'Vyberte konkrétní údaje firmy k převzetí.');
            }
            if (trim((string) ($target[$field] ?? '')) !== $expected[$field]) {
                throw new StereoNxException('company_profile_changed', 'Údaje firmy se od zobrazení náhledu změnily. Načtěte náhled znovu a zkontrolujte výběr.');
            }
            if (isset($suggestions[$field])) $out[$field] = $suggestions[$field];
        }
        return $out;
    }
}
