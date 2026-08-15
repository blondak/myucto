<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver;

/**
 * Překlopení mzdového modulu ze `setup` do `active`.
 *
 * Historicky uměl stav zapsat jediný zapisovatel — `setActivation()` —
 * a ten zná jen `disabled` a `setup`. Hláška „Probíhá nastavení" proto
 * nezmizela nikdy a testy si `active` vkládaly ručním SQL.
 *
 * Spouště jsou dvě a nezávislé, platí ta, která nastane dřív:
 *  1. setup-check bez blokátorů (`ready === true`) — hlavní cesta, firma
 *     dokončila nastavení a ještě nemá žádný mzdový běh;
 *  2. první schválený mzdový běh — pojistka pro případ, kdy se běh schválí
 *     dřív, než setup-check projde.
 *
 * Přechod je jednosměrný a idempotentní: druhé vyhodnocení nezmění stav ani
 * nezapíše druhý auditní záznam, a pozdější blokátor v setup-checku modul
 * zpátky do `setup` nevrátí.
 */
final class PayrollModuleActivationService
{
    public const TRIGGER_SETUP_COMPLETE = 'setup_complete';
    public const TRIGGER_FIRST_APPROVED_RUN = 'first_approved_run';

    public function __construct(
        private readonly PayrollModuleStateRepository $state,
        private readonly ActivityLogger $logger,
        private readonly PayrollSetupFeaturesResolver $features,
        private readonly PayrollSetupCheckService $setupCheck,
    ) {}

    /**
     * Vyhodnotí spoušť „setup je hotový" nad aktuálním stavem. Volá se ze
     * čtecích endpointů, které stav modulu zobrazují (přehled schopností,
     * setup-check) — jinde se `ready === true` nedozvíme včas a badge by
     * lhal až do dalšího ručního zásahu. Když modul v `setup` není, neudělá
     * to vůbec nic (ani jeden dotaz navíc), takže cena je omezená na dobu
     * nastavování.
     *
     * @return array<string,mixed>|null nový stav, nebo null když se nic nezměnilo
     */
    public function activateWhenSetupComplete(
        int $supplierId,
        ?int $userId,
    ): ?array {
        $current = $this->state->get($supplierId);
        if ($current['status'] !== 'setup') {
            return null;
        }
        $effectiveOn = self::effectiveOn($current['start_period']);
        $check = $this->setupCheck->check(
            $supplierId,
            $effectiveOn,
            $this->features->resolve($supplierId, $effectiveOn),
        );
        if ($check['ready'] !== true || $check['blockers'] !== []) {
            return null;
        }

        return $this->promote(
            $supplierId,
            $userId,
            self::TRIGGER_SETUP_COMPLETE,
        );
    }

    /**
     * Spoušť „první schválený mzdový běh". Běží uvnitř transakce schválení,
     * takže rollback schválení vrátí i aktivaci.
     *
     * @return array<string,mixed>|null
     */
    public function activateAfterApprovedRun(
        int $supplierId,
        ?int $userId,
    ): ?array {
        return $this->promote(
            $supplierId,
            $userId,
            self::TRIGGER_FIRST_APPROVED_RUN,
        );
    }

    /** @return array<string,mixed>|null */
    private function promote(
        int $supplierId,
        ?int $userId,
        string $trigger,
    ): ?array {
        $state = $this->state->promoteToActive($supplierId, $userId);
        if ($state === null) {
            return null;
        }
        $this->logger->log(
            'payroll.activation.activated',
            $userId,
            'payroll_module_state',
            $supplierId,
            [
                'status' => $state['status'],
                'start_period' => $state['start_period'],
                'trigger' => $trigger,
            ],
            null,
            null,
            $supplierId,
        );

        return $state;
    }

    private static function effectiveOn(?string $startPeriod): string
    {
        $monthStart = date('Y-m-01');
        if ($startPeriod === null) {
            return $monthStart;
        }
        $start = substr($startPeriod, 0, 7) . '-01';

        return $start > $monthStart ? $start : $monthStart;
    }
}
