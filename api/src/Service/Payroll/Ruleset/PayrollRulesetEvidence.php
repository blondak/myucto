<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use DateTimeImmutable;

/**
 * Doklady o kontrole a schválení pro verzi složenou z defaultu a DB overridu.
 *
 * `PayrollRulesetVersion` odmítne `approved`/`active` bez `RulesetApproval`
 * (s jedinou výjimkou dodané sady, viz {@see VendorRulesetManifest})
 * a `RulesetApproval` odmítne shodného kontrolora a schvalovatele. To je správné
 * pro dokument, který popisuje, ČÍM je verze podložená — ale nesmí to znamenat,
 * že jeden admin nemůže ruleset uvést do provozu. Když je editor i schvalovatel
 * tatáž osoba, doplní se jako kontrolor technická kontrola sady a UI/API k tomu
 * hlásí `four_eyes_not_met`. Kdo skutečně co udělal, drží append-only audit.
 */
final class PayrollRulesetEvidence
{
    public const TECHNICAL_IDENTITY = 'myucto/payroll-ruleset-technical-check';
    public const ADMIN_IDENTITY = 'myucto/payroll-ruleset-admin';

    public static function identity(?int $userId): string
    {
        return $userId === null ? self::ADMIN_IDENTITY : 'user:' . $userId;
    }

    /**
     * @param array<string, mixed>|null $override
     */
    public static function technicalReview(
        ?array $override,
        ?RulesetTechnicalReview $default,
        PayrollRulesetLifecycle $lifecycle,
        string $reason,
    ): ?RulesetTechnicalReview {
        $reviewedBy = self::nullableInt($override, 'reviewed_by');
        if ($reviewedBy !== null) {
            return new RulesetTechnicalReview(
                self::identity($reviewedBy),
                self::date(self::nullableStr($override, 'reviewed_at')),
                $reason === '' ? 'Kontrola provedena v administraci MyÚčto.' : $reason,
            );
        }
        if ($default !== null) {
            return $default;
        }
        if ($lifecycle === PayrollRulesetLifecycle::Draft) {
            return null;
        }

        return new RulesetTechnicalReview(
            self::TECHNICAL_IDENTITY,
            self::date(self::nullableStr($override, 'updated_at')),
            $reason === '' ? 'Kontrola provedena v administraci MyÚčto.' : $reason,
        );
    }

    /**
     * @param array<string, mixed>|null $override
     */
    public static function approval(
        ?array $override,
        ?RulesetApproval $default,
        ?RulesetTechnicalReview $technicalReview,
        PayrollRulesetLifecycle $lifecycle,
        string $reason,
    ): ?RulesetApproval {
        if (!in_array($lifecycle, [
            PayrollRulesetLifecycle::Approved,
            PayrollRulesetLifecycle::Active,
            PayrollRulesetLifecycle::Superseded,
        ], true)) {
            return null;
        }

        // Bez jmenovaného schvalovatele se doklad o schválení NEVYRÁBÍ. Dřív se tu
        // dopisovala syntetická `RulesetApproval` se systémovou identitou, takže
        // řádek zapsaný přímo do DB s `lifecycle = active` prošel jako schválený,
        // aniž by kdokoli schvaloval. Chybějící schvalovatel = chybějící schválení;
        // co s tím udělá lifecycle, řeší {@see PayrollRulesetRegistry::merge()}.
        $approvedBy = self::nullableInt($override, 'approved_by');
        if ($approvedBy === null) {
            return $default;
        }

        $approver = self::identity($approvedBy);
        $reviewer = $technicalReview === null ? self::TECHNICAL_IDENTITY : $technicalReview->checkedBy;
        if ($reviewer === $approver) {
            $reviewer = self::TECHNICAL_IDENTITY;
        }
        $reviewedOn = $technicalReview === null ? self::date(null) : $technicalReview->checkedOn;
        $approvedOn = self::date(self::nullableStr($override, 'approved_at'));
        if ($reviewedOn > $approvedOn) {
            $reviewedOn = $approvedOn;
        }

        return new RulesetApproval(
            $reviewer,
            $reviewedOn,
            $approver,
            $approvedOn,
            $reason === '' ? 'Schváleno v administraci MyÚčto.' : $reason,
        );
    }

    /**
     * Čtyři oči jsou POLITIKA, ne tvrdá podmínka — vrací se jako varování.
     *
     * @param array<string, mixed>|null $override
     */
    public static function fourEyesMet(?array $override): bool
    {
        $approvedBy = self::nullableInt($override, 'approved_by');
        if ($approvedBy === null) {
            return true;
        }
        foreach (['created_by', 'updated_by', 'reviewed_by'] as $field) {
            if ($approvedBy === self::nullableInt($override, $field)) {
                return false;
            }
        }

        return true;
    }

    private static function date(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return (new DateTimeImmutable('now'))->format('Y-m-d');
        }

        return substr($timestamp, 0, 10);
    }

    /** @param array<string, mixed>|null $row */
    private static function nullableStr(?array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed>|null $row */
    private static function nullableInt(?array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : null;
    }
}
