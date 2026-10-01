<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;

/**
 * Jediný název souboru mzdového podání — pro přílohu datové zprávy i pro
 * stažení.
 *
 * Tvar `{AGENDA}_{RRRR-MM}_{příjemce}_{IČO}[_opravne|_storno][_N].{přípona}`,
 * např. `PPPZ_2026-09_VZP-111_12345678.pdf` nebo `JMHZ_2026-09_CSSZ_12345678.xml`.
 * Dřív odcházela příloha jako `mzdove-podani-{id podání}-{id artefaktu}.pdf`:
 * interní čísla, která nic neřeknou ani podatelně pojišťovny, ani účetní,
 * která soubor hledá ve stažených.
 *
 * Jen ASCII bez diakritiky a mezer (ISDS a podatelny s nimi zachází různě)
 * a nejvýš {@see self::MAX_LENGTH} znaků. Předepsaný název souboru pro tyto
 * agendy u ČSSZ ani u pojišťoven doložený není; kdyby vznikl, patří sem.
 */
final class PayrollSubmissionFilename
{
    public const MAX_LENGTH = 60;

    public static function build(
        string $agendaCode,
        string $periodStart,
        string $subjectReference,
        ?string $businessId,
        string $submissionKind,
        string $extension,
        ?int $sequence = null,
    ): string {
        $parts = [
            self::agenda($agendaCode),
            preg_match('/^[0-9]{4}-[0-9]{2}/D', $periodStart) === 1
                ? substr($periodStart, 0, 7)
                : 'obdobi',
            self::recipient($agendaCode, $subjectReference),
        ];
        $ico = preg_replace('/\D+/', '', (string) $businessId) ?? '';
        if ($ico !== '') {
            $parts[] = $ico;
        }
        $suffix = match ($submissionKind) {
            'correction' => 'opravne',
            'cancellation' => 'storno',
            default => null,
        };
        if ($suffix !== null) {
            $parts[] = $suffix;
        }
        if ($sequence !== null && $sequence > 1) {
            $parts[] = (string) $sequence;
        }
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $extension));
        $base = self::ascii(implode('_', $parts));
        $limit = self::MAX_LENGTH - ($extension === '' ? 0 : strlen($extension) + 1);

        return rtrim(substr($base, 0, $limit), '_-')
            . ($extension === '' ? '' : '.' . $extension);
    }

    /** Přípona podle MIME typu artefaktu. */
    public static function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/json' => 'json',
            'text/plain' => 'txt',
            default => 'xml',
        };
    }

    private static function agenda(string $agendaCode): string
    {
        $canonical = PayrollIsdsAgendaCatalog::canonical($agendaCode);

        return match (true) {
            $canonical === HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW => 'PPPZ',
            $canonical === HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION => 'HOZ',
            str_starts_with($canonical, 'JMHZ') => 'JMHZ',
            default => (string) preg_replace('/_?[0-9]{2,4}$/', '', $canonical) ?: 'PODANI',
        };
    }

    private static function recipient(string $agendaCode, string $subjectReference): string
    {
        $canonical = PayrollIsdsAgendaCatalog::canonical($agendaCode);
        $health = [
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
        ];
        if (!in_array($canonical, $health, true)) {
            return 'CSSZ';
        }
        $segments = explode(':', $subjectReference);
        $code = (string) end($segments);
        if (preg_match('/^[0-9]{3}$/D', $code) !== 1) {
            return 'ZP';
        }
        $abbreviation = HealthInsurers::abbreviation($code);

        return $abbreviation === null
            ? 'ZP-' . $code
            : strtoupper(self::ascii($abbreviation)) . '-' . $code;
    }

    private static function ascii(string $value): string
    {
        $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I',
            'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U',
            'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
        ];
        $value = strtr($value, $map);

        return (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value);
    }
}
