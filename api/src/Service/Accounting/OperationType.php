<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class OperationType
{
    public const DOCUMENT_INVOICE = 'document.invoice';
    public const DOCUMENT_PURCHASE = 'document.purchase';
    public const BANK_PAYMENT_MATCHED = 'bank.payment.matched';
    public const BANK_TRANSFER_OWN = 'bank.transfer.own';
    /** Pojistné OSVČ za sebe (vs_type cssz_vsdp / health_insurance_number, taxpayer_type fo). */
    public const REMITTANCE_SOCIAL = 'bank.remittance.social.own';
    public const REMITTANCE_HEALTH = 'bank.remittance.health.own';
    /**
     * Pojistné zaměstnavatele za zaměstnance (taxpayer_type po). Kontace je shodná s OSVČ
     * (336/221 — úhrada existujícího závazku), ale operace je jiná: liší se předpis, který
     * úhradu kryje (mzdová rekapitulace 524/336 + srážky 331/336, ne 526/336 OSVČ), a tím
     * i politika a fronta ke schválení. Bez těchto typů odvod PO nenajde v remittance_map
     * řádek a spadne na REMITTANCE_OTHER s auto_allowed=0 → nikdy se nezaúčtuje automaticky.
     */
    public const REMITTANCE_SOCIAL_EMPLOYER = 'bank.remittance.social.employer';
    public const REMITTANCE_HEALTH_EMPLOYER = 'bank.remittance.health.employer';
    public const REMITTANCE_VAT = 'bank.remittance.vat';
    /**
     * Daň v režimu jednoho správního místa (OSS, § 110 a násl. ZDPH). Vlastní typ, protože
     * má vlastní ÚČET: předpis jde na 345.100 (migrace 1295), ne na 343 — patří státu
     * spotřeby a do českého přiznání nevstupuje. Dokud se úhrada účtovala jako
     * {@see REMITTANCE_VAT}, snižovala 343, a nevynulovala se ani jedna strana:
     * na 343 vznikl přeplatek a na 345.100 zůstal závazek napořád.
     */
    public const REMITTANCE_OSS = 'bank.remittance.oss';
    public const REMITTANCE_INCOME = 'bank.remittance.income';
    public const REMITTANCE_WITHHOLDING = 'bank.remittance.withholding';
    public const REMITTANCE_PAYROLL = 'bank.remittance.payroll';
    public const REMITTANCE_PROPERTY = 'bank.remittance.property';
    public const REMITTANCE_ROAD = 'bank.remittance.road';
    public const REMITTANCE_FLAT = 'bank.remittance.flat';
    public const REMITTANCE_OTHER = 'bank.remittance.other';
    public const BANK_INTEREST = 'bank.interest';
    public const BANK_FEE = 'bank.fee';
    public const BANK_RULE_CUSTOM = 'bank.rule.custom';
    public const BANK_LEARNED = 'bank.learned';
    public const AI_BANK = 'ai.classify.bank';
    public const AI_DOCUMENT = 'ai.classify.document';
    public const DETECTOR_TAX_REMITTANCE = 'detector.tax_remittance';
    public const DETECTOR_OWN_TRANSFER = 'detector.own_transfer';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DOCUMENT_INVOICE,
            self::DOCUMENT_PURCHASE,
            self::BANK_PAYMENT_MATCHED,
            self::BANK_TRANSFER_OWN,
            self::REMITTANCE_SOCIAL,
            self::REMITTANCE_HEALTH,
            self::REMITTANCE_SOCIAL_EMPLOYER,
            self::REMITTANCE_HEALTH_EMPLOYER,
            self::REMITTANCE_VAT,
            self::REMITTANCE_OSS,
            self::REMITTANCE_INCOME,
            self::REMITTANCE_WITHHOLDING,
            self::REMITTANCE_PAYROLL,
            self::REMITTANCE_PROPERTY,
            self::REMITTANCE_ROAD,
            self::REMITTANCE_FLAT,
            self::REMITTANCE_OTHER,
            self::BANK_INTEREST,
            self::BANK_FEE,
            self::BANK_RULE_CUSTOM,
            self::BANK_LEARNED,
            self::AI_BANK,
            self::AI_DOCUMENT,
            self::DETECTOR_TAX_REMITTANCE,
            self::DETECTOR_OWN_TRANSFER,
        ];
    }

    public static function isAi(string $operationType): bool
    {
        return str_starts_with($operationType, 'ai.');
    }

    public static function detectorFor(string $operationType): ?string
    {
        if ($operationType === self::BANK_TRANSFER_OWN) {
            return self::DETECTOR_OWN_TRANSFER;
        }
        if (str_starts_with($operationType, 'bank.remittance.')) {
            return self::DETECTOR_TAX_REMITTANCE;
        }
        return null;
    }
}
