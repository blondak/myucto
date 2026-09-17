<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Odkrytí OIČ / IK MPSV osoby a ID PPV vztahu na kartě pracovního vztahu.
 *
 * Karta ukazovala jen masku a jediná cesta k hodnotě vedla přes „Opravit",
 * které číslo přepíše. Účetní tak musela správnou hodnotu zahodit jen proto,
 * aby si ji mohla porovnat s protokolem ČSSZ.
 *
 * Brána je stejná jako u {@see PayrollPersonSensitiveRevealService}: právo
 * `payroll.person.read_sensitive`, textový důvod a auditní stopa. Účel
 * dešifrování zůstává {@see PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL} —
 * je to přesně „výslovné odhalení na žádost uživatele", jen nad jinou evidencí,
 * takže vlastní case by stopu jen rozdrobil.
 */
final class PayrollJmhzIdentifierRevealService
{
    public function __construct(
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PermissionChecker $permissionChecker,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array{
     *   person_external_identifier:?array{id:int,value:string},
     *   employment_external_identifier:?array{id:int,value:string}
     * }
     */
    public function reveal(
        int $supplierId,
        int $employmentId,
        int $actorUserId,
        EffectiveRole $role,
        string $environment,
        string $onDate,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $this->positive($actorUserId, 'actor_user_id');
        $this->permissionChecker->require(
            $role,
            'payroll.person.read_sensitive',
            AccessLevel::READ,
        );
        $reason = $this->reason($reason);

        $revealed = $this->identities->jmhzIdentifierPlaintextAt(
            $supplierId,
            $employmentId,
            $environment,
            $onDate,
        );
        $person = $revealed['person_external_identifier'];
        $employment = $revealed['employment_external_identifier'];

        /*
         * Zapisuje se, CO se odkrylo, ne hodnota — auditní stopa nesmí být
         * druhým místem, odkud se identifikátory dají přečíst.
         */
        $this->activity->log(
            action: 'payroll.jmhz_identity.revealed',
            userId: $actorUserId,
            entityType: 'payroll_employment',
            entityId: $employmentId,
            payload: [
                'reason' => $reason,
                'environment' => $environment,
                'on_date' => $onDate,
                'employee_id' => $revealed['employee_id'],
                'identifiers' => array_values(array_filter([
                    $person === null ? null : 'ik_mpsv',
                    $employment === null ? null : 'id_ppv',
                ])),
            ],
            ip: $ip,
            userAgent: $userAgent,
            supplierId: $supplierId,
        );

        return [
            'person_external_identifier' => $person,
            'employment_external_identifier' => $employment,
        ];
    }

    private function positive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$field} musí být kladné.");
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 10 || $length > 500) {
            throw new \InvalidArgumentException(
                'Důvod odhalení musí mít 10 až 500 znaků.',
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            throw new \InvalidArgumentException(
                'Důvod odhalení obsahuje řídicí znak.',
            );
        }

        return $reason;
    }
}
