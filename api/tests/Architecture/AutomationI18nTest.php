<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Accounting\OperationType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class AutomationI18nTest extends TestCase
{
    /**
     * Seznam typů operací v nastavení automatiky se skládá z toho, co vrátí API —
     * tedy z {@see OperationType}. Popisek se hledá dynamicky sestaveným klíčem
     * (`settings.automation.operation.${type.replaceAll('.', '_')}`) a komponenta
     * při chybějícím překladu tiše vypíše syrový klíč. Statická i18n brána
     * (`web/scripts/check-i18n.mjs`) na takový klíč nedosáhne — vidí jen proměnnou.
     * Tenhle test je proto jediné místo, kde se výčet a slovník potkají.
     */
    public function testEveryOperationTypeHasCzechAndEnglishLabel(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            foreach (OperationType::all() as $operationType) {
                $key = str_replace('.', '_', $operationType);
                $label = $messages['settings']['automation']['operation'][$key] ?? null;
                self::assertIsString(
                    $label,
                    "Chybí settings.automation.operation.{$key} v {$locale}.json",
                );
                self::assertNotSame(
                    '',
                    trim($label),
                    "Prázdný settings.automation.operation.{$key} v {$locale}.json",
                );
            }
        }
    }

    public function testEveryAutomationNoteCodeHasCzechAndEnglishSentence(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/web/src/api/automation.ts');
        self::assertMatchesRegularExpression('/AUTOMATION_NOTE_CODES\s*=\s*\[(.*?)\]\s*as const/s', $source);
        preg_match('/AUTOMATION_NOTE_CODES\s*=\s*\[(.*?)\]\s*as const/s', $source, $match);
        preg_match_all("/'([^']+)'/", $match[1], $codes);
        self::assertNotEmpty($codes[1]);

        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            foreach ($codes[1] as $code) {
                $sentence = $messages['automation']['reason'][$code] ?? null;
                self::assertIsString($sentence, "Chybí automation.reason.{$code} v {$locale}.json");
                self::assertNotSame('', trim($sentence), "Prázdný automation.reason.{$code} v {$locale}.json");
            }
        }
    }

    public function testEveryBankMatchSignalAndFlagHasCzechAndEnglishSentence(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/api/src/Service/Bank/Match/MatchScorer.php');
        foreach (['SIGNALS' => 'match_signal', 'BLOCKING_FLAGS' => 'match_flag'] as $constant => $section) {
            self::assertMatchesRegularExpression("/const {$constant} = \\[(.*?)\\];/s", $source);
            preg_match("/const {$constant} = \\[(.*?)\\];/s", $source, $match);
            preg_match_all("/'([^']+)'/", $match[1], $codes);
            self::assertNotEmpty($codes[1]);

            foreach (['cs', 'en'] as $locale) {
                $messages = json_decode(
                    (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                foreach ($codes[1] as $code) {
                    $sentence = $messages['bank'][$section][$code] ?? null;
                    self::assertIsString($sentence, "Chybí bank.{$section}.{$code} v {$locale}.json");
                    self::assertNotSame('', trim($sentence), "Prázdný bank.{$section}.{$code} v {$locale}.json");
                }
            }
        }
    }
}
