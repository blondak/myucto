<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Osobní a smluvní skutečnosti, které rozhodují o tom, jestli zaměstnanec
 * vůbec smí přesčas konat.
 *
 * `birthDate` slouží k § 245 odst. 1 ve spojení s § 350 odst. 2 (mladistvý je
 * zaměstnanec mladší 18 let). Chybějící datum narození se NEBERE jako „není
 * mladistvý" — {@see OvertimeLimitEvaluator} v takovém případě vystaví
 * samostatnou výhradu, protože bez data narození nejde absolutní zákaz ověřit.
 *
 * `workloads` jsou úseky sjednané pracovní doby seřazené podle `from`. Podle
 * § 78 odst. 1 písm. i) věty druhé platí, že zaměstnancům s KRATŠÍ pracovní
 * dobou „není možné práci přesčas nařídit" — úvazek se přitom v čase mění,
 * takže se posuzuje ke dni přesčasu, ne k počátku vyrovnávacího období.
 */
final readonly class OvertimeEmploymentProfile
{
    /** @param list<array{from:string,to:?string,basis_points:int}> $workloads */
    public function __construct(
        public ?string $birthDate = null,
        public array $workloads = [],
    ) {
        if ($birthDate !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if ($parsed === false || $parsed->format('Y-m-d') !== $birthDate) {
                throw new \InvalidArgumentException('Datum narození musí být YYYY-MM-DD.');
            }
        }
    }

    /** Je zaměstnanec k danému dni mladistvý (§ 350 odst. 2)? */
    public function juvenileOn(string $date): bool
    {
        if ($this->birthDate === null) {
            return false;
        }

        return $date < (new \DateTimeImmutable($this->birthDate))
            ->modify('+18 years')
            ->format('Y-m-d');
    }

    /** Má zaměstnanec k danému dni sjednanou kratší pracovní dobu? */
    public function partTimeOn(string $date): bool
    {
        foreach ($this->workloads as $segment) {
            if ($date < $segment['from']) {
                continue;
            }
            if ($segment['to'] !== null && $date > $segment['to']) {
                continue;
            }
            if ($segment['basis_points'] < 10_000) {
                return true;
            }
        }

        return false;
    }
}
