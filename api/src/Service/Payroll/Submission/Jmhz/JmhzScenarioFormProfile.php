<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;

/**
 * Které datové scénáře aplikace v měsíčním hlášení SESTAVÍ a jakým formulářem.
 *
 * Jediné místo pro otázku „umíme tenhle vztah vykázat": ptá se na ni selektor
 * scénáře (připravenost), zmrazení ELDP a běžné evidence, resolver
 * normalizovaného dokumentu i serializér. Dřív měl každý z nich vlastní výčet
 * `scenario_1`/`scenario_3` a nový scénář se musel doplnit na šesti místech.
 *
 * Zdroj zařazení je list „Datové scénáře" souboru Datové scénáře, interakce
 * a povinnosti MH 1.4.0.2 (sloupec XSD) a kontrola 343 katalogu 1.4.2.10;
 * samotné zařazení podle druhu činnosti a bližšího určení dělá
 * {@see JmhzScenarioSelectorResolver}.
 */
final class JmhzScenarioFormProfile
{
    /**
     * Klíč scénáře → lokální jméno elementu, který `xs:choice` součásti volí.
     *
     * @var array<string,string>
     */
    public const FORM_ELEMENTS = [
        'scenario_1' => 'bezPriznaku',
        'scenario_2' => 'pestoun',
        'scenario_3' => 'cinnostKS',
        'scenario_4' => 'vezen',
        'scenario_5' => 'jinyPrijem',
        'scenario_6' => 'mezinarodniPronajemSily',
        'scenario_7' => 'ozpTpp',
        'scenario_8' => 'odlozenyPrijem',
    ];

    /**
     * Scénáře, které nesou formuláře běžného normalizovaného dokumentu
     * ({@see JmhzScenario1NormalizedDocument}). Odložený příjem (8) se volí
     * ručně, ostatní podle druhu činnosti.
     */
    private const ORDINARY_DOCUMENT_SCENARIOS = [
        'scenario_1',
        'scenario_3',
        'scenario_4',
        'scenario_5',
        'scenario_6',
        'scenario_8',
    ];

    /**
     * Formuláře bez bloku `pojisteni`: matice scénářů 5 a 6 nemají žádný
     * atribut třídy pojištění ani ELDP.
     */
    private const WITHOUT_INSURANCE = ['scenario_5', 'scenario_6'];

    /** @return list<string> */
    public static function ordinaryDocumentScenarios(): array
    {
        return self::ORDINARY_DOCUMENT_SCENARIOS;
    }

    public static function isOrdinaryDocumentScenario(mixed $scenarioKey): bool
    {
        return in_array($scenarioKey, self::ORDINARY_DOCUMENT_SCENARIOS, true);
    }

    public static function formElement(string $scenarioKey): string
    {
        return self::FORM_ELEMENTS[$scenarioKey]
            ?? throw new \UnexpectedValueException("Scénář {$scenarioKey} nemá formulář.");
    }

    /** Nese formulář scénáře blok `pojisteni` (trvání, ELDP, pojistné)? */
    public static function carriesInsurance(string $scenarioKey): bool
    {
        return !in_array($scenarioKey, self::WITHOUT_INSURANCE, true);
    }

    /**
     * Sestaví aplikace formulář scénáře zařazeného podle druhu činnosti pro
     * tenhle vztah? Druh vztahu je `null` tam, kde ho volající nezná
     * (selektor); pak rozhodují jen kódy.
     *
     * Odložený příjem (scénář 8) se tu neposuzuje: vybírá se ručně potvrzením
     * odloženého příjmu a jeho podmínky drží zmrazení ELDP.
     */
    public static function preparable(
        string $scenarioKey,
        ?string $activityCode,
        ?string $relationshipDetailCode,
        ?string $relationType = null,
    ): bool {
        $relationIs = static fn (array $types): bool
            => $relationType === null || in_array($relationType, $types, true);
        $workActivity = is_string($activityCode)
            && preg_match('/^[1-9]$/D', $activityCode) === 1;

        return match ($scenarioKey) {
            'scenario_1' => true,
            'scenario_3' => PayrollEmploymentJmhzActivityFamily::isCorporateBodyActivity($activityCode)
                && $relationshipDetailCode === '1'
                && $relationIs(['partner_dependent', 'statutory_body']),
            'scenario_4' => $workActivity
                && $relationshipDetailCode === PayrollEmploymentJmhzActivityFamily::PRISONER_RELATIONSHIP_DETAIL
                && $relationIs(['employment', 'small_scale_employment']),
            'scenario_5' => in_array($activityCode, ['11', '13', '14'], true)
                && $relationIs(['employment']),
            'scenario_6' => $activityCode === '12'
                && $relationIs(['employment']),
            default => false,
        };
    }
}
