<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

final class PayrollRulesetProvider
{
    /** @var list<PayrollRulesetVersion> */
    private array $versions;

    /** @param list<PayrollRulesetVersion> $versions */
    public function __construct(array $versions)
    {
        if ($versions === []) {
            throw new InvalidArgumentException('Payroll ruleset registry cannot be empty.');
        }

        $ids = array_map(static fn (PayrollRulesetVersion $version): string => $version->id, $versions);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Payroll ruleset version IDs must be unique.');
        }

        $this->versions = $versions;
        $this->assertNoSelectableOverlaps();
    }

    /** @return list<PayrollRulesetVersion> */
    public function versions(): array
    {
        return $this->versions;
    }

    public function forDate(PayrollRulesetDomain $domain, string $date): PayrollRulesetVersion
    {
        $matches = array_values(array_filter(
            $this->versions,
            static fn (PayrollRulesetVersion $version): bool =>
                $version->domain === $domain
                && $version->lifecycle !== PayrollRulesetLifecycle::Superseded
                && $version->contains($date),
        ));

        if (count($matches) !== 1) {
            throw new PayrollRulesetException(
                "Expected exactly one inspectable {$domain->value} ruleset for {$date}, found " . count($matches) . '.',
            );
        }

        return $matches[0];
    }

    public function forCalculation(PayrollRulesetDomain $domain, string $date): PayrollRulesetVersion
    {
        $ruleset = $this->forDate($domain, $date);
        $ruleset->assertCalculationReady();

        return $ruleset;
    }

    public function assertContinuousCoverage(
        PayrollRulesetDomain $domain,
        string $from,
        string $to,
    ): void {
        self::assertDate($from);
        self::assertDate($to);
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        if ($end < $cursor) {
            throw new InvalidArgumentException('Ruleset coverage end cannot precede its start.');
        }

        while ($cursor <= $end) {
            $this->forDate($domain, $cursor->format('Y-m-d'));
            $cursor = $cursor->modify('+1 day');
        }
    }

    /** @return list<array{id:string,sha256:string}> */
    public function canonicalManifest(): array
    {
        $manifest = array_map(
            static fn (PayrollRulesetVersion $version): array => [
                'id' => $version->id,
                'sha256' => $version->canonicalHash,
            ],
            $this->versions,
        );
        usort($manifest, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $manifest;
    }

    public function canonicalManifestJson(): string
    {
        return CanonicalJson::encode(['rulesets' => $this->canonicalManifest()]);
    }

    /**
     * This runtime registry contains only the current selectable head of each
     * effective interval. Older immutable lifecycle versions belong in the audit
     * store; loading them beside their successor would make date lookup ambiguous.
     */
    private function assertNoSelectableOverlaps(): void
    {
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $selectable = array_values(array_filter(
                $this->versions,
                static fn (PayrollRulesetVersion $version): bool =>
                    $version->domain === $domain
                    && $version->lifecycle !== PayrollRulesetLifecycle::Superseded,
            ));
            usort($selectable, static fn (PayrollRulesetVersion $left, PayrollRulesetVersion $right): int =>
                $left->effectiveFrom <=> $right->effectiveFrom
            );

            $previous = null;
            foreach ($selectable as $version) {
                if ($previous !== null && $version->effectiveFrom <= $previous->effectiveTo) {
                    throw new PayrollRulesetException(
                        "Selectable {$domain->value} rulesets {$previous->id} and {$version->id} overlap and make lookup ambiguous.",
                    );
                }
                $previous = $version;
            }
        }
    }

    private static function assertDate(string $value): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Ruleset coverage dates must use YYYY-MM-DD.');
        }
    }
}
