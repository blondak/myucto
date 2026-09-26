<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use MyInvoice\Service\Migration\Shared\CompanyProfileCarryOver;

/** Výběr běžných kontaktních údajů; účetní a daňové volby se nepřebírají. */
final class StereoNxCompanyProfile
{
    public const FIELDS = ['company_name', 'dic', 'street', 'city', 'zip', 'email', 'phone', 'web'];

    public static function matchesCompany(array $identity, array $target): bool
    {
        $source = PartnerIdentityMatcher::ico((string) ($identity['ico'] ?? ''));
        return $source !== '' && $source === PartnerIdentityMatcher::ico((string) ($target['ic'] ?? ''));
    }

    /** @return array<string,string> */
    public static function suggestions(array $identity, array $target): array
    {
        if (!self::matchesCompany($identity, $target)) return [];
        $source = (array) ($identity['company_profile'] ?? []);
        $source['company_name'] = $source['company_name'] ?? $identity['name'] ?? '';
        $source['dic'] = $identity['dic'] ?? '';
        return CompanyProfileCarryOver::suggestions($source, $target, self::FIELDS);
    }

    /** @return array<string,string> */
    public static function selected(array $identity, array $target, array $fields, array $expected): array
    {
        return CompanyProfileCarryOver::selected(self::suggestions($identity, $target), $target,
            $fields, $expected, self::FIELDS,
            static fn (string $code, string $message): StereoNxException => new StereoNxException($code, $message));
    }
}
