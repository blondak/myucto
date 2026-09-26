<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * JEDINÝ seznam údajů osoby, bez kterých nejde sestavit registrační datová
 * věta (PREZEC26 / REGZEC25).
 *
 * PROČ: požadavky žily jen v serializéru a snapshotu, každý jako samostatná
 * výjimka. Příprava registrace proto hlásila chybějící údaje PO JEDNOM — po
 * doplnění občanství vyskočilo rodné příjmení, pak místo narození — a do textu
 * prosakoval technický název pole v závorce. Sekce „Údaje pro registraci
 * zaměstnance" na kartě osoby mezitím svítila „Doplněno", protože si stav
 * počítala po svém (stačil jí jeden vyplněný titul).
 *
 * Teď se ptají všichni tady: příprava registrace (vrací VŠECHNY mezery naráz,
 * každou s adresou pole pro proklik) i karta osoby (štítek sekce). Serializér
 * si svoje kontroly nechává jako poslední pojistku, ale na tuhle cestu se
 * s úplnými daty už nedostane.
 *
 * Požadavky kopírují, co serializér opravdu čte (`identityElements()`):
 * `name/@sur,@fir`, `birth/@nam,@cit` a `stat/@cnt` u obou agend, u REGZEC
 * navíc `stat/@mal`; identifikátor `client/@bno` podle snapshotu (PREZEC RČ
 * nebo EČP, REGZEC identifikátor NEBO úplná zahraniční identita).
 */
final class PayrollRegistrationIdentityRequirements
{
    public const AGENDA_PREZEC = 'PREZEC26';
    public const AGENDA_REGZEC = 'REGZEC25';

    /** Panel karty osoby s historií jména a údaji pro registraci. */
    public const PANEL_IDENTITY = 'registration_identity';

    /** Panel karty osoby s identifikátory (RČ, EČP, VČP). */
    public const PANEL_IDENTIFIERS = 'identifiers';

    /** Kam proklik vede: karta osoby, nebo nastavení mezd zaměstnavatele. */
    public const TARGET_PERSON = 'person';
    public const TARGET_EMPLOYER_SETTINGS = 'employer_settings';
    public const TARGETS = [self::TARGET_PERSON, self::TARGET_EMPLOYER_SETTINGS];

    /**
     * Údaje identity, které nese datová věta u OBOU agend, v pořadí,
     * ve kterém je formulář na kartě osoby ukazuje.
     *
     * @var list<string>
     */
    private const COMMON_FIELDS = [
        'first_name',
        'last_name',
        'birth_surname',
        'birth_place',
        'citizenship_country_code',
    ];

    /**
     * Chybějící údaje osoby pro danou agendu.
     *
     * `$agenda === null` znamená „agendu ještě nejde určit" — typicky proto,
     * že chybí státní občanství, podle kterého se mezi PREZEC a REGZEC
     * rozhoduje. Pak se hlásí jen to, co vyžadují obě agendy, aby seznam
     * neslibal víc, než kolik se opravdu bude chtít.
     *
     * `$hasBirthSurname` je samostatně, protože rodné příjmení je v evidenci
     * šifrované a volající (karta osoby) má k dispozici jen informaci, zda
     * existuje.
     *
     * @param array<string,mixed> $identity řádek historie identity
     * @param array<string,mixed> $identifiers birth_number/ecp/vcp → hodnota nebo null
     * @return list<array{field:string,label:string,message:string,panel:string,target:string}>
     */
    public static function missing(
        ?string $agenda,
        array $identity,
        array $identifiers,
        ?bool $hasBirthSurname = null,
    ): array {
        $missing = [];
        foreach (self::COMMON_FIELDS as $field) {
            $present = $field === 'birth_surname' && $hasBirthSurname !== null
                ? $hasBirthSurname
                : self::filled($identity[$field] ?? null);
            if (!$present) {
                $missing[] = self::identityProblem($field);
            }
        }
        if ($agenda === self::AGENDA_REGZEC
            && !in_array($identity['sex'] ?? null, ['male', 'female'], true)
        ) {
            $missing[] = self::identityProblem('sex');
        }

        $hasBno = self::filled($identifiers['birth_number'] ?? null)
            || self::filled($identifiers['ecp'] ?? null);
        if ($agenda === self::AGENDA_PREZEC && !$hasBno) {
            $missing[] = self::identifierProblem();
        }
        if ($agenda === self::AGENDA_REGZEC
            && !$hasBno
            && !self::filled($identifiers['vcp'] ?? null)
        ) {
            // Bez identifikátoru musí REGZEC nést úplnou zahraniční identitu.
            foreach (['birth_date', 'birth_country_code'] as $field) {
                if (!self::filled($identity[$field] ?? null)) {
                    $missing[] = self::identityProblem($field);
                }
            }
        }

        return $missing;
    }

    /**
     * Údaje, které sekce „Údaje pro registraci zaměstnance" na kartě osoby
     * potřebuje mít vyplněné, aby smělo svítit „Doplněno".
     *
     * Karta neví, kterou agendou se bude podávat (rozhoduje o tom nástup
     * a občanství), takže se ptá na přísnější REGZEC — co projde tam, projde
     * i PREZEC. Identifikátory se tu nehodnotí: mají vlastní sekci karty.
     *
     * @param array<string,mixed> $identity
     * @return list<string> klíče chybějících údajů
     */
    public static function missingForProfile(
        array $identity,
        bool $hasBirthSurname,
    ): array {
        $missing = self::missing(
            self::AGENDA_REGZEC,
            $identity,
            // Identifikátor se tu schválně „má" — jinak by karta chtěla
            // zahraniční identitu i po každém českém zaměstnanci.
            ['birth_number' => 'x'],
            $hasBirthSurname,
        );

        return array_values(array_map(
            static fn (array $problem): string => $problem['field'],
            $missing,
        ));
    }

    /**
     * Věta pro souhrnnou výjimku přípravy registrace: jmenuje všechny
     * chybějící údaje lidsky, bez technických názvů.
     *
     * @param list<array{field:string,label:string,message:string,panel:?string,target:string}> $problems
     */
    public static function summary(array $problems): string
    {
        $labels = array_map(
            static fn (array $problem): string => mb_strtolower(
                mb_substr($problem['label'], 0, 1),
            ) . mb_substr($problem['label'], 1),
            $problems,
        );

        return 'Registraci zatím nejde sestavit, chybí: '
            . implode(', ', array_values(array_unique($labels)))
            . '. U každého údaje je odkaz, který otevře přesně to pole.';
    }

    /**
     * Údaj zaměstnavatele (variabilní symbol, kód OSSZ) — zadává se
     * v nastavení mezd, ne na kartě osoby.
     *
     * @return array{field:string,label:string,message:string,panel:null,target:string}
     */
    public static function employerProblem(string $field, string $reason): array
    {
        $label = PayrollRegistrationFieldVocabulary::label($field);

        return [
            'field' => $field,
            'label' => $label,
            'message' => $label . ' ' . $reason . ' Doplňte v '
                . PayrollRegistrationFieldVocabulary::WHERE_EMPLOYER . '.',
            'panel' => null,
            'target' => self::TARGET_EMPLOYER_SETTINGS,
        ];
    }

    /** @return array{field:string,label:string,message:string,panel:string,target:string} */
    private static function identityProblem(string $field): array
    {
        $label = PayrollRegistrationFieldVocabulary::label($field);

        return [
            'field' => 'identity.' . $field,
            'label' => $label,
            'message' => $label . ' chybí. Doplňte na '
                . self::whereFor($field) . '.',
            'panel' => self::PANEL_IDENTITY,
            'target' => self::TARGET_PERSON,
        ];
    }

    /** @return array{field:string,label:string,message:string,panel:string,target:string} */
    private static function identifierProblem(): array
    {
        return [
            'field' => 'identifier.value',
            'label' => 'Rodné číslo nebo EČP',
            'message' => 'Rodné číslo nebo EČP chybí — částečné přihlášení '
                . 'PREZEC bez něj nejde podat. Doplňte na '
                . PayrollRegistrationFieldVocabulary::WHERE_IDENTIFIERS . '.',
            'panel' => self::PANEL_IDENTIFIERS,
            'target' => self::TARGET_PERSON,
        ];
    }

    private static function whereFor(string $field): string
    {
        return PayrollRegistrationFieldVocabulary::where($field)
            ?? PayrollRegistrationFieldVocabulary::WHERE_IDENTITY;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
