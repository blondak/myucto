<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

/**
 * Druh výdaje na řádku přijaté faktury (§DM).
 *
 * Klasifikace je ZÁMĚRNĚ 4-cestná, ne 2-cestná: PHM je materiál, ale drobný majetek to není
 * a do jeho evidence nesmí. Účetní to drží stejně — 501.100 (materiál + PHM) vedle
 * 501.200 (drobný majetek).
 *
 * Mapování na účty se NEDRŽÍ natvrdo tady, ale v `posting_rules` (rule_key), aby si ho tenant
 * mohl přesměrovat na vlastní analytiku bez zásahu do kódu.
 */
enum ExpenseKind: string
{
    case Service = 'service';
    case Material = 'material';
    case SmallAsset = 'small_asset';
    /**
     * Drobný NEHMOTNÝ majetek (DDNM) — software, licence, ochranné známky pod hranicí,
     * kterou si účetní jednotka stanoví. Vlastní druh, ne varianta `SmallAsset`: kontace
     * je jiná (518 místo 501), protože licence není spotřeba materiálu a sloučení by
     * rozbilo druhové členění ve výsledovce.
     */
    case SmallIntangible = 'small_intangible';
    case FixedAsset = 'fixed_asset';

    /** Kontační klíč do `posting_rules`; fallback účet je až v PostingService. */
    public function ruleKey(): string
    {
        return match ($this) {
            self::Service => 'invoice.services.received',
            self::Material => 'invoice.material.received',
            self::SmallAsset => 'invoice.small_asset.received',
            self::SmallIntangible => 'invoice.small_intangible.received',
            self::FixedAsset => 'invoice.dhm.received',
        };
    }

    /** Účet, když kontace v `posting_rules` chybí (drží dosavadní chování: 518 / 042). */
    public function fallbackAccount(): string
    {
        return match ($this) {
            self::Service, self::SmallIntangible => '518',
            self::Material, self::SmallAsset => '501',
            self::FixedAsset => '042',
        };
    }

    /** Patří na kartu evidence drobného majetku (§28 ZoÚ / ČÚS 013)? */
    public function isSmallAssetEvidence(): bool
    {
        return $this === self::SmallAsset || $this === self::SmallIntangible;
    }

    /** Druh karty evidence — inventarizace hmotného a nehmotného se vede jinak. */
    public function smallAssetCardKind(): string
    {
        return $this === self::SmallIntangible ? 'intangible' : 'tangible';
    }

    public static function tryFromNullable(?string $v): ?self
    {
        return $v === null || $v === '' ? null : self::tryFrom($v);
    }
}
