<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Service\System\DiagnosticsLogReader;
use PHPUnit\Framework\TestCase;

/**
 * Sanitizace výřezu logu.
 *
 * Aplikační log nese u DB chyb navázané parametry (tj. obsah řádků databáze),
 * u neošetřených výjimek stack trace s useknutými argumenty funkcí a u SMTP
 * transcriptu i `AUTH` v base64. Nic z toho nesmí opustit instalaci.
 *
 * Všechna data v testu jsou syntetická — repozitář je veřejný.
 */
final class DiagnosticsLogReaderTest extends TestCase
{
    public function testBoundParametersAreRemoved(): void
    {
        $record = '[2026-01-01T00:00:00+01:00] myinvoice.ERROR: DB error: duplicate '
            . '{"sqlstate":"23000","sql":"INSERT INTO clients (email) VALUES (?)",'
            . '"params":["novak@example.test"],"caller":"a.php:1"} []';

        $clean = DiagnosticsLogReader::sanitize($record);

        self::assertStringNotContainsString('novak@example.test', $clean);
        self::assertStringContainsString('"params":"<odstraněno>"', $clean);
        // SQL samotné je diagnosticky nejcennější část — musí zůstat.
        self::assertStringContainsString('INSERT INTO clients', $clean);
        self::assertStringContainsString('"caller":"a.php:1"', $clean);
    }

    /**
     * Parametry mohou obsahovat vnořené struktury i uvozovky se zavírací
     * závorkou uvnitř řetězce. Naivní regex `\[.*?\]` by uřízl špatně a nechal
     * v logu zbytek hodnot — proto scanner respektující JSON.
     */
    public function testNestedAndQuotedParametersAreRemovedWholly(): void
    {
        $record = '[2026-01-01T00:00:00+01:00] myinvoice.ERROR: DB error '
            . '{"params":[1,{"nested":["leak-a","]"]},"leak-b"],"caller":"x"} []';

        $clean = DiagnosticsLogReader::sanitize($record);

        self::assertStringNotContainsString('leak-a', $clean);
        self::assertStringNotContainsString('leak-b', $clean);
        self::assertStringContainsString('"params":"<odstraněno>","caller":"x"', $clean);
    }

    public function testStackTraceArgumentsAreRemoved(): void
    {
        $record = "[2026-01-01T00:00:00+01:00] myinvoice.ERROR: Slim Application Error Type: PDOException "
            . "File: /app/x.php Line: 36 Trace: #0 /app/x.php(36): PDO->__construct('mysql:host=127...', "
            . "'dbuser', 'dbsecret123456...')";

        $clean = DiagnosticsLogReader::sanitize($record);

        self::assertStringNotContainsString('dbsecret123456', $clean);
        self::assertStringNotContainsString('dbuser', $clean);
        self::assertStringEndsWith('Trace: <odstraněno>', $clean);
        // Typ a místo výjimky zůstávají — bez nich by záznam neměl cenu.
        self::assertStringContainsString('PDOException', $clean);
        self::assertStringContainsString('Line: 36', $clean);
    }

    public function testRecordWithoutSensitiveContentIsUnchanged(): void
    {
        $record = '[2026-01-01T00:00:00+01:00] myinvoice.WARNING: license.renew.rejected {"error":"clone_suspected"} []';

        self::assertSame($record, DiagnosticsLogReader::sanitize($record));
    }

    public function testLevelWeightOrdersMonologLevels(): void
    {
        self::assertGreaterThan(
            DiagnosticsLogReader::levelWeight('INFO'),
            DiagnosticsLogReader::levelWeight('WARNING')
        );
        self::assertGreaterThan(
            DiagnosticsLogReader::levelWeight('WARNING'),
            DiagnosticsLogReader::levelWeight('ERROR')
        );
        self::assertSame(
            DiagnosticsLogReader::levelWeight('ERROR'),
            DiagnosticsLogReader::levelWeight('error')
        );
    }

    public function testLevelsCoverMonologRange(): void
    {
        self::assertSame('DEBUG', DiagnosticsLogReader::levels()[0]);
        self::assertContains('WARNING', DiagnosticsLogReader::levels());
        self::assertContains('EMERGENCY', DiagnosticsLogReader::levels());
    }

    public function testUnknownLevelDoesNotFilterEverythingOut(): void
    {
        // Neznámá úroveň má váhu 0, tedy nejnižší práh — nikdy nesmí způsobit,
        // že se do balíčku nedostane vůbec nic bez upozornění.
        self::assertSame(0, DiagnosticsLogReader::levelWeight('NEEXISTUJE'));
    }
}
