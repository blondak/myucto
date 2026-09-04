<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Explicitní způsob vyřešení externí reference při obnově. */
enum CompanyBackupReferenceDecisionAction: string
{
    case MapExisting = 'map_existing';
    case UseRestoreActor = 'restore_actor';
    case SetNull = 'null';
    case Omit = 'omit';
}
