<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\CzechBirthNumber;

/**
 * Shoda data narození a pohlaví s rodným číslem v registračním podání.
 *
 * EDV 1.4.0.6, ID 10056 a 10059: datum narození a pohlaví se kontrolují proti
 * RČ (`client/@bno`), je-li vyplněno; nesoulad ČSSZ zamítne na vstupu. Z RČ
 * plyne datum narození i pohlaví (měsíc +50 žena, +20 a +70 alternativa),
 * EČP je nenese, takže u něj se nic neporovnává.
 */
final class PayrollRegistrationBirthNumberConsistency
{
    /**
     * @param array<string,mixed> $identity
     * @return list<array{field:string,code:string,message:string}>
     */
    public static function problems(array $identity, ?string $birthNumber): array
    {
        if ($birthNumber === null || trim($birthNumber) === '') {
            return [];
        }
        try {
            $normalized = CzechBirthNumber::normalize($birthNumber);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $problems = [];
        $birthDate = $identity['birth_date'] ?? null;
        $expectedDate = CzechBirthNumber::birthDate($normalized);
        if (is_string($birthDate) && $birthDate !== '' && $birthDate !== $expectedDate) {
            $problems[] = [
                'field' => 'identity.birth_date',
                'code' => 'registration_identity_birth_date_mismatch',
                'message' => 'Datum narození (' . $birthDate . ') nesouhlasí s rodným '
                    . 'číslem, ze kterého plyne ' . $expectedDate . '. ČSSZ takové '
                    . 'podání zamítne. Opravte datum narození nebo rodné číslo '
                    . 'na kartě osoby.',
            ];
        }
        $sex = $identity['sex'] ?? null;
        $expectedSex = CzechBirthNumber::sex($normalized);
        if (in_array($sex, ['male', 'female'], true)
            && $expectedSex !== null
            && $sex !== $expectedSex
        ) {
            $problems[] = [
                'field' => 'identity.sex',
                'code' => 'registration_identity_sex_mismatch',
                'message' => 'Pohlaví nesouhlasí s rodným číslem, podle kterého '
                    . 'je zaměstnanec ' . ($expectedSex === 'male' ? 'muž' : 'žena')
                    . '. ČSSZ takové podání zamítne. Opravte pohlaví nebo rodné '
                    . 'číslo na kartě osoby.',
            ];
        }

        return $problems;
    }
}
