<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;

final class PayrollApprovedRevisionPostingService
{
    private const STATUTORY_KINDS = [
        'social_insurance',
        'health_insurance',
        'income_tax',
        'net_pay',
    ];

    public function __construct(
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollPostingAdapter $posting,
        private readonly AccountingModeRepository $accountingModes,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @return array{
     *   batch_id:int,
     *   journal_entry_id:?int,
     *   status:'posted'|'no_change',
     *   idempotent:bool,
     *   preview:PayrollPostingPreview
     * }|null
     */
    public function post(
        int $supplierId,
        int $revisionId,
        array $snapshot,
        array $result,
        int $actorUserId,
    ): ?array {
        if ($supplierId <= 0 || $revisionId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a uživatel účetního můstku musí být kladná čísla.',
            );
        }
        $year = self::snapshotYear($snapshot);
        if ($this->accountingModes->forYear($supplierId, $year) !== 'double_entry') {
            return null;
        }

        $sets = [];
        foreach (self::STATUTORY_KINDS as $kind) {
            $set = $this->statutoryResults->find(
                $supplierId,
                $revisionId,
                $kind,
            );
            if ($set === null) {
                throw new \DomainException(
                    "Schválená revize nemá zákonný výsledek {$kind}.",
                );
            }
            $sets[$kind] = $set;
        }

        $employer = $snapshot['employer'] ?? null;
        $accounts = is_array($employer) && !array_is_list($employer)
            ? ($employer['accounting_accounts'] ?? null)
            : null;
        if (!is_array($accounts) || array_is_list($accounts)) {
            throw new \DomainException(
                'Schválený mzdový snapshot nemá zmrazenou sadu předkontací.',
            );
        }
        $normalizedAccounts = [];
        foreach ($accounts as $key => $account) {
            if (!is_string($key)
                || !is_string($account)
                || preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/D', $account) !== 1
            ) {
                throw new \DomainException(
                    'Schválený mzdový snapshot nemá platnou sadu předkontací.',
                );
            }
            $normalizedAccounts[$key] = $account;
        }
        ksort($normalizedAccounts, SORT_STRING);

        return $this->posting->post(
            $supplierId,
            $revisionId,
            $snapshot,
            $result,
            $sets,
            $normalizedAccounts,
            ['user_id' => $actorUserId],
        );
    }

    /** @param array<string,mixed> $snapshot */
    private static function snapshotYear(array $snapshot): int
    {
        $periodStart = $snapshot['period_start'] ?? null;
        if (!is_string($periodStart)
            || preg_match('/^([0-9]{4})-[0-9]{2}-[0-9]{2}$/D', $periodStart, $match) !== 1
        ) {
            throw new \DomainException(
                'Mzdový snapshot nemá platný počátek období.',
            );
        }

        return (int) $match[1];
    }
}
