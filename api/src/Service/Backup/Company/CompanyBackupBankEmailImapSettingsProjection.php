<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupBankEmailImapSettingsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'name',
            'enabled',
            'host',
            'port',
            'encryption',
            'validate_cert',
            'require_email_auth',
            'allow_forwarded',
            'forwarded_from',
            'email_auth_serv_id',
            'username',
            'folder',
            'max_messages_per_run',
            'process_from_date',
            'success_action',
            'success_flag',
            'success_move_folder',
            'failure_action',
            'failure_flag',
            'failure_move_folder',
            'retry_failed',
            'max_attempts',
            'last_scan_at',
            'last_scan_status',
            'last_scan_message',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}

