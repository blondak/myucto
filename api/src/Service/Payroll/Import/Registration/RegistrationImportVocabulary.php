<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzTakeoverPlanner;

/**
 * Hodnoty, které import registrací posílá klientovi. Klientské výčty
 * (`web/src/api/payrollImports.ts`) se s nimi párují v PayrollEnumContractTest,
 * takže nová operace nebo stav bez úpravy klienta neprojde.
 */
final class RegistrationImportVocabulary
{
    public const ENVIRONMENTS = ['production', 'test'];
    public const DOCUMENT_TYPES = ['REGZEC25', 'PREZEC26', 'CSSZ_EXPORT', 'JMHZ', 'JMHZ_DERIVED'];
    public const RELATION_TYPES = ['employment', 'small_scale_employment', 'dpc', 'dpp', 'statutory_body'];
    public const MATCH_STATUSES = ['new', 'matched', 'ambiguous', 'not_found'];
    public const MATCHED_BY = ['birth_number', 'oic', 'id_ppv', 'name_birth_date', 'manual'];
    public const OPERATIONS = [
        'create_person',
        'create_employment',
        'update',
        'terminate',
        'assign_identifiers',
        'pair_required',
        'none',
        'unsupported',
    ];
    public const RESULT_STATUSES = ['applied', 'failed', 'skipped'];
    public const RESULT_OPERATIONS = [
        'person_created',
        'employment_created',
        'identity_facts',
        'address',
        'terms',
        'activated',
        'identifiers',
        'terminated',
        'no_show',
        'health_insurer',
        'tax_declaration',
        'tax_credit_claims',
        'social_discount',
        'dependants',
    ];
    /** Typ podání a formuláře měsíčního hlášení (`files[].submission_type`). */
    public const SUBMISSION_TYPES = ['R', 'O', 'S'];
    /** Stav návrhu počátečních stavů (`opening_balances[].status`). */
    public const OPENING_BALANCE_STATUSES = ['ready', 'blocked', 'unchanged'];
    /** Stav návrhu průměrného výdělku (`averages[].status`). */
    public const AVERAGE_STATUSES = ['ready', 'blocked', 'exists'];
    /** Stav převzatého měsíce z hlášení (`takeover.months[].status`). */
    public const TAKEOVER_STATUSES = [
        JmhzTakeoverPlanner::STATUS_READY,
        JmhzTakeoverPlanner::STATUS_BLOCKED,
        JmhzTakeoverPlanner::STATUS_COMPUTED,
    ];
}
