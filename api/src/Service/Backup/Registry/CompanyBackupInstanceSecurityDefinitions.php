<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Přístup k původní instanci není přenositelná konfigurace firmy. */
final class CompanyBackupInstanceSecurityDefinitions
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $reasons = [
            'api_token_ips' => 'instance_api_token_access_restrictions',
            'api_tokens' => 'instance_bearer_credentials_even_when_supplier_scoped',
            'login_attempts' => 'instance_login_rate_limit_state',
            'mfa_step_up_proofs' => 'instance_purpose_bound_step_up_proofs',
            'password_resets' => 'instance_account_recovery_tokens',
            'sessions' => 'instance_browser_sessions_and_step_up_proofs',
            'trusted_devices' => 'instance_trusted_browser_credentials',
            'webauthn_ceremonies' => 'instance_ephemeral_webauthn_challenges',
            'webauthn_credentials' => 'instance_user_passkey_registrations',
        ];
        $definitions = [];
        foreach ($reasons as $table => $reason) {
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table, TenantDataPolicy::InstanceOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                ['primary_key' => match ($table) {
                    'webauthn_ceremonies' => ['flow_token_hash'],
                    'mfa_step_up_proofs' => ['token_hash'],
                    default => ['id'],
                }, 'feature_group' => 'identity', 'reason' => $reason],
            );
        }
        return $definitions;
    }
}
