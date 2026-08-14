<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Šest doložených stavů měsíčního hlášení v cJMHZ. Kódy 1–6 jsou číselník
 * z odpovědi DZMH, texty jsou z pravidel podání; číselník stavů konkrétního
 * podání je tentýž bez dvojky, protože „nebylo přijato" u jednoho podání
 * nedává smysl.
 */
enum JmhzSubmissionStatus: int
{
    case ProcessedAndComplete = 1;
    case NotAccepted = 2;
    case Rejected = 3;
    case PartiallyAccepted = 4;
    case Processing = 5;
    case ContainsPassableErrors = 6;

    public function label(): string
    {
        return $this->labels()[0];
    }

    /**
     * Číselník DZMH a pravidla podání píší tytéž stavy o slovo jinak
     * („zpracováno a je úplné" versus „zpracováno a úplné"), proto se uznávají
     * obě doložené varianty.
     *
     * @return non-empty-list<string>
     */
    private function labels(): array
    {
        return match ($this) {
            self::ProcessedAndComplete => ['zpracováno a je úplné', 'zpracováno a úplné'],
            self::NotAccepted => ['nebylo přijato'],
            self::Rejected => ['zamítnuto'],
            self::PartiallyAccepted => ['částečně přijato'],
            self::Processing => ['ve zpracování'],
            self::ContainsPassableErrors => ['obsahuje propustné chyby'],
        };
    }

    public static function fromCode(int $code): self
    {
        $status = self::tryFrom($code);
        if ($status === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_status_unknown',
                "Stav podání s kódem {$code} není mezi šesti doloženými stavy.",
            );
        }

        return $status;
    }

    /**
     * Číselník DZMH zkracuje názvy na „Hlášení…" a „Podání…", pravidla podání
     * píší „Měsíční hlášení…". Porovnává se proto jen doložený zbytek věty.
     */
    public static function fromDocumentedLabel(string $label): self
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $normalized = preg_replace(
            '/^(Měsíční hlášení|Hlášení|Podání)\s+(je|bylo)?\s*/u',
            '',
            $normalized,
        ) ?? '';
        $normalized = mb_strtolower(trim($normalized), 'UTF-8');
        foreach (self::cases() as $case) {
            if (in_array($normalized, $case->labels(), true)) {
                return $case;
            }
        }

        throw new JmhzTransportException(
            'jmhz_protocol_status_unknown',
            'Stav podání v protokolu neodpovídá žádnému ze šesti doložených stavů.',
        );
    }

    /**
     * Mapování na `payroll_submissions.status`. „Nebylo přijato" i „zamítnuto"
     * padají na `rejected` — platforma je nerozlišuje a v obou případech musí
     * zaměstnavatel poslat nové hlášení. „Obsahuje propustné chyby" je podle
     * pravidel přijaté podání, chyby zůstávají jen v protokolu.
     */
    public function payrollRemoteStatus(): string
    {
        return match ($this) {
            self::ProcessedAndComplete, self::ContainsPassableErrors => 'accepted',
            self::NotAccepted, self::Rejected => 'rejected',
            self::PartiallyAccepted => 'partially_accepted',
            self::Processing => 'processing',
        };
    }
}
