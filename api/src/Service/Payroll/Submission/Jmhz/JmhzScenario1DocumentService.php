<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzDeferralRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;

final readonly class JmhzScenario1DocumentService
{
    public function __construct(
        private JmhzPreparationSnapshotService $preparations,
        private JmhzPvpojPreviewService $pvpoj,
        private JmhzScenario1DocumentResolver $resolver,
        private JmhzScenario2DocumentResolver $scenario2Resolver,
        private JmhzSpecialScenarioDocumentResolver $specialScenarios,
        private PayrollEmployerSettingsRepository $employerSettings,
        private JmhzDeferralRepository $deferrals,
    ) {}

    public function resolveScenario2(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): JmhzScenario2Resolution {
        return $this->scenario2Resolver->resolve(
            $this->preparations->loadVerified(
                $supplierId,
                $environment,
                $preparationId,
            ),
        );
    }

    public function resolveSpecialScenarios(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): ?JmhzSpecialScenarioResolution {
        return $this->specialScenarios->resolve(
            $this->preparations->loadVerified(
                $supplierId,
                $environment,
                $preparationId,
            ),
        );
    }

    /**
     * @param int|null $officeId registrace u OSSZ, za kterou se hlášení
     *        sestavuje. Přehled o výši pojistného se podává za účtárnu, takže
     *        se jí musí ptát i tahle vrstva — bez toho spadne běh přes víc
     *        účtáren na `jmhz_scenario1_pvpoj_source_mismatch`. `null` zůstává
     *        jednoúčtárenským během.
     * @param JmhzFormExclusion|null $exclusion výslovně vynechané formuláře;
     *        `null` = vynechat vztahy, které účetní odložila z řádného hlášení
     *        této revize (payroll_jmhz_deferrals)
     */
    public function resolve(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?int $officeId = null,
        ?JmhzFormExclusion $exclusion = null,
    ): JmhzScenario1Resolution {
        $preparation = $this->preparations->loadVerified(
            $supplierId,
            $environment,
            $preparationId,
        );
        // Testovací prostředí ČSSZ má vlastní přidělený VS; produkce ho nikdy nedostane.
        $testVariableSymbols = $environment === 'test'
            ? $this->employerSettings->testVariableSymbols($supplierId)
            : [];
        if (!in_array(
            $preparation->builderVersion,
            JmhzScenario1DocumentResolver::SUPPORTED_BUILDER_VERSIONS,
            true,
        )) {
            return $this->resolver->resolve($preparation, null, null, $officeId, $testVariableSymbols);
        }
        $exclusion ??= $this->deferralExclusion($supplierId, $preparation, $officeId);
        try {
            $pvpoj = $this->pvpoj->preview(
                $supplierId,
                $preparation->sourceRevisionId,
                $officeId,
            );
            $failure = null;
        } catch (JmhzPvpojPreviewException $exception) {
            $pvpoj = null;
            $failure = $exception->validationCode === 'jmhz_pvpoj_source_not_found'
                ? 'jmhz_scenario1_pvpoj_unavailable'
                : 'jmhz_scenario1_pvpoj_source_mismatch';
        }
        if ($exclusion->isEmpty()) {
            return $this->resolver->resolveForSubmission(
                $preparation,
                $pvpoj,
                $failure,
                $officeId,
                $testVariableSymbols,
            );
        }

        return $this->resolver->resolveExcluding(
            $preparation,
            $pvpoj,
            $failure,
            $officeId,
            $testVariableSymbols,
            $exclusion,
        );
    }

    /**
     * Dokument pro obsahovou opravu: nález na vztahu, který se neopravuje,
     * opravu nezastaví.
     *
     * Posuzují se jen vybrané formuláře a firemní části (pojistná část,
     * souhrn). Zbylé vztahy s nálezem se z formulářů vynechají — u ČSSZ zůstává
     * jejich dřív přijatý formulář, nebo (u odloženého vztahu) dál chybí.
     * Vrátí-li se blokované rozhodnutí, je blokující nález firemní nebo leží
     * přímo na vybraném vztahu.
     *
     * @param list<string> $selectedEmploymentIdentifiers ID PPV vztahů, jejichž
     *        formulář oprava nese; prázdné = jen výpis kandidátů
     */
    public function resolveForCorrection(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?int $officeId,
        array $selectedEmploymentIdentifiers,
    ): JmhzScenario1Resolution {
        $full = $this->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            JmhzFormExclusion::correctionScope([]),
        );
        if ($full->status() === 'resolved' || $full->candidate === null) {
            return $full;
        }
        $employmentsByEmployee = [];
        $selectedEmploymentIds = [];
        $people = $full->candidate->payload['people'] ?? [];
        foreach (is_array($people) ? $people : [] as $person) {
            $employeeId = is_array($person) ? ($person['employee_id'] ?? null) : null;
            $employments = is_array($person) ? ($person['employments'] ?? []) : [];
            foreach (is_array($employments) ? $employments : [] as $employment) {
                $employmentId = is_array($employment) ? ($employment['employment_id'] ?? null) : null;
                if (is_int($employeeId) && is_int($employmentId)) {
                    $employmentsByEmployee[$employeeId][] = $employmentId;
                }
                $identifier = is_array($employment)
                    ? ($employment['identity']['employment_external_identifier'] ?? null)
                    : null;
                if (is_int($employmentId)
                    && is_string($identifier)
                    && in_array($identifier, $selectedEmploymentIdentifiers, true)
                ) {
                    $selectedEmploymentIds[] = $employmentId;
                }
            }
        }
        $blocked = [];
        foreach ($full->blockers as $blocker) {
            if (!$blocker->deferrable()) {
                return $full;
            }
            $ids = $blocker->entityType === 'employment'
                ? [(int) $blocker->entityId]
                : ($employmentsByEmployee[(int) $blocker->entityId] ?? []);
            foreach ($ids as $employmentId) {
                if (in_array($employmentId, $selectedEmploymentIds, true)) {
                    return $full;
                }
                $blocked[$employmentId] = true;
            }
        }
        if ($blocked === []) {
            return $full;
        }

        return $this->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            JmhzFormExclusion::correctionScope(array_keys($blocked)),
        );
    }

    /**
     * Aktivní odložení revize, za kterou se hlášení sestavuje.
     *
     * Odložení je vázané na revizi: nová revize běhu (přepočet) znamená nová
     * data a o odložení se musí rozhodnout znovu. Běh přes víc účtáren bere
     * jen odložení vztahů zvolené registrace.
     */
    private function deferralExclusion(
        int $supplierId,
        JmhzVerifiedPreparationSnapshot $preparation,
        ?int $officeId,
    ): JmhzFormExclusion {
        $employmentIds = [];
        $deferralIds = [];
        foreach ($this->deferrals->activeForRevision($supplierId, $preparation->sourceRevisionId) as $row) {
            if ($officeId !== null && $row['office_id'] !== $officeId) {
                continue;
            }
            $employmentIds[] = $row['employment_id'];
            $deferralIds[] = $row['id'];
        }

        return JmhzFormExclusion::deferral($employmentIds, $deferralIds);
    }
}
