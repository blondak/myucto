<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use RuntimeException;

/**
 * Rozvrh zálohy překročil smluvní strop (H-25).
 *
 * Není to provozní chyba — je to pokus nastavit něco, co není zaplacené.
 * Vlastní typ existuje proto, aby to volající uměl odlišit od „záloha selhala"
 * a nabídl uživateli správnou akci: dodatek ke smlouvě, ne restart cronu.
 */
final class BackupScheduleContractException extends RuntimeException {}
