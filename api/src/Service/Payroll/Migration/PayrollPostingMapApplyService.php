<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator;

/**
 * Potvrzení návrhu předkontací účetní.
 *
 * Zapisuje se VÝHRADNĚ přes běžnou cestu nastavení zaměstnavatele
 * ({@see PayrollEmployerSettingsValidator} + {@see PayrollEmployerSettingsRepository::save()}),
 * tedy přes tentýž validátor, který hlídá existenci účtu v osnově, jeho typ i
 * kolizní prefixy. Vlastní UPDATE do `payroll_employer_settings` by tyhle
 * kontroly obešel a firma by se o chybné předkontaci dozvěděla až u zaúčtování
 * mzdy.
 *
 * Promítne se jen to, co účetní výslovně potvrdila. Doporučení, které nikdo
 * nepotvrdil, nastavení nemění - viz {@see PayrollPostingMapProposalBuilder::confirmedAccounts()}.
 */
final class PayrollPostingMapApplyService
{
    public function __construct(
        private readonly PayrollPostingMapProposalStore $proposals,
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly PayrollEmployerSettingsValidator $validator,
    ) {}

    /**
     * @param array<string,string> $confirmations předkontace => účet
     * @return array{settings:array<string,mixed>,proposal:array<string,mixed>}
     */
    public function apply(
        int $supplierId,
        ?int $userId,
        string $source,
        array $confirmations,
        int $expectedRowVersion,
    ): array {
        $proposal = $this->proposals->find($supplierId, $source);
        if ($proposal === null) {
            throw new \InvalidArgumentException(
                'Pro tenhle zdroj není uložený žádný návrh mzdových předkontací.',
            );
        }
        if ($confirmations === []) {
            throw new \InvalidArgumentException(
                'Nebyla potvrzena žádná předkontace, není co uložit.',
            );
        }

        $current = $this->settings->get($supplierId);
        $offices = $current['offices'] ?? [];
        if (!is_array($offices) || $offices === []) {
            throw new \InvalidArgumentException(
                'Firma nemá založenou mzdovou účtárnu. Nastavte ji v Mzdy → Nastavení a potvrzení zopakujte.',
            );
        }

        /** @var array<string,string> $currentAccounts */
        $currentAccounts = is_array($current['accounts'] ?? null) ? $current['accounts'] : [];
        $accounts = PayrollPostingMapProposalBuilder::confirmedAccounts($currentAccounts, $confirmations);

        $payload = [
            'default_office_code' => $current['default_office_code'],
            'employer_registration_number' => $current['employer_registration_number'] ?? null,
            'social_security_office_code' => $current['social_security_office_code'] ?? null,
            'default_health_insurer_code' => $current['default_health_insurer_code'] ?? null,
            'payroll_contact_name' => $current['payroll_contact_name'] ?? null,
            'payroll_contact_email' => $current['payroll_contact_email'] ?? null,
            'payroll_contact_phone' => $current['payroll_contact_phone'] ?? null,
            'accounts' => $accounts,
            'offices' => array_map(
                // Klíč `social_security_variable_symbol` se ZÁMĚRNĚ vynechává:
                // validátor jeho přítomnost bere jako pokus přepsat ostrý VS
                // ČSSZ, který se spravuje jen účinnou historií registrace.
                static fn (array $office): array => [
                    'code' => $office['code'],
                    'name' => $office['name'],
                    'test_social_security_variable_symbol' => $office['test_social_security_variable_symbol'] ?? null,
                    'is_active' => (bool) $office['is_active'],
                ],
                $offices,
            ),
        ];

        $saved = $this->settings->save(
            $supplierId,
            $this->validator->validate($supplierId, $payload),
            $expectedRowVersion,
        );

        $this->proposals->markConfirmed((int) $proposal['id'], $confirmations, $userId);
        $stored = $this->proposals->find($supplierId, $source);

        return ['settings' => $saved, 'proposal' => $stored ?? $proposal];
    }
}
