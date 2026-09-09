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
            'isds_gateway_registrations' => 'instance_gateway_registration_and_server_certificate',
            'login_attempts' => 'instance_login_rate_limit_state',
            'login_otps' => 'instance_one_time_login_challenges',
            'mfa_recovery_codes' => 'instance_user_account_recovery_credentials',
            'mfa_step_up_proofs' => 'instance_purpose_bound_step_up_proofs',
            'password_resets' => 'instance_account_recovery_tokens',
            'rate_limit_counters' => 'instance_request_rate_limit_windows',
            'role_permissions' => 'instance_role_permission_assignments',
            'roles' => 'instance_authorization_roles_not_tenant_configuration',
            'sessions' => 'instance_browser_sessions_and_step_up_proofs',
            'submission_isds_auth_flows' => 'instance_one_time_isds_interactive_authentication',
            'supplier_domain_login_requests' => 'instance_domain_login_authorization_requests',
            'trusted_devices' => 'instance_trusted_browser_credentials',
            'webauthn_ceremonies' => 'instance_ephemeral_webauthn_challenges',
            'webauthn_credentials' => 'instance_user_passkey_registrations',
            'work_report_link_codes' => 'instance_work_report_one_time_email_codes',
            'work_report_link_sessions' => 'instance_work_report_verified_browser_sessions',
        ];
        $definitions = [];
        foreach ($reasons as $table => $reason) {
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table, TenantDataPolicy::InstanceOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                ['primary_key' => match ($table) {
                    'webauthn_ceremonies' => ['flow_token_hash'],
                    'mfa_step_up_proofs' => ['token_hash'],
                    'rate_limit_counters' => ['bucket_key', 'window_start'],
                    'role_permissions' => ['role_id', 'permission_key'],
                    default => ['id'],
                }, 'feature_group' => 'identity', 'reason' => $reason],
            );
        }
        return $definitions;
    }
}
