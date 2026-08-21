<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

/**
 * Dělení dávky na zprávy o nejvýš {@see self::HARD_MAX_RECIPIENTS} příjemcích.
 *
 * ⚠️ TOHLE JE JEDINÁ VĚC, KTEROU FRONTA NEZACHRÁNÍ. Ostatní limity hostingu
 * končí dočasným odmítnutím (SMTP 451) a zpráva odejde později. Překročení
 * počtu příjemců na JEDNU zprávu je ale odmítnutí TRVALÉ — zpráva se ztratí
 * a nikdo ji nedoručí. Týká se to hromadných upomínek a rozesílek účetní
 * kanceláře, tedy přesně toho provozu, kvůli kterému brzda vůbec vzniká.
 *
 * Počítají se VŠICHNI příjemci obálky (To + Cc + Bcc) — hosting vidí RCPT TO,
 * ne hlavičky. Kopie dodavateli tedy do stropu patří.
 *
 * Balí se hladově v pořadí To → Cc → Bcc, takže:
 *   - 250 To bez kopií  → 3 zprávy (100 + 100 + 50),
 *   - 50 To + 1 Cc      → 1 zpráva (a v počítadle jedno odeslání, ne 51),
 *   - 95 To + 20 Cc     → 2 zprávy (95 To + 5 Cc, pak 15 Cc).
 *
 * Rozdělení Cc přes dvě zprávy vypadá nezvykle, ale je to jediná varianta,
 * která drží strop bez ztráty příjemce. Alternativa „Cc jen do první dávky"
 * by u dávky s víc než sto kopiemi tiše zahodila zbytek.
 */
final class MailRecipientBatcher
{
    /**
     * Tvrdý strop hostingu. Nastavitelný klíč `smtp.rate_limit.max_recipients_per_message`
     * smí jít jen DOLŮ — nahoru ne, protože nad tuhle hodnotu je odmítnutí
     * trvalé a žádná konfigurace to nezmění.
     */
    public const HARD_MAX_RECIPIENTS = 100;

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @return list<array{to:list<string>,cc:list<string>,bcc:list<string>}>
     */
    public static function split(array $to, array $cc, array $bcc, int $max = self::HARD_MAX_RECIPIENTS): array
    {
        $max = self::clamp($max);

        $to  = array_values($to);
        $cc  = array_values($cc);
        $bcc = array_values($bcc);

        if (count($to) + count($cc) + count($bcc) <= $max) {
            return [['to' => $to, 'cc' => $cc, 'bcc' => $bcc]];
        }

        /** @var list<array{0:string,1:string}> $slots */
        $slots = [];
        foreach ($to as $addr) {
            $slots[] = ['to', $addr];
        }
        foreach ($cc as $addr) {
            $slots[] = ['cc', $addr];
        }
        foreach ($bcc as $addr) {
            $slots[] = ['bcc', $addr];
        }

        $batches = [];
        foreach (array_chunk($slots, $max) as $chunk) {
            $batch = ['to' => [], 'cc' => [], 'bcc' => []];
            foreach ($chunk as [$kind, $addr]) {
                $batch[$kind][] = $addr;
            }
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Kolik příjemců smí jedna zpráva mít. Konfigurace nesmí strop zvednout —
     * `min()`, ne `max()`. Nula a záporná čísla jsou překlep v cfg, ne pokyn
     * „neposílej nic".
     */
    public static function clamp(int $configured): int
    {
        if ($configured < 1) {
            return self::HARD_MAX_RECIPIENTS;
        }

        return min($configured, self::HARD_MAX_RECIPIENTS);
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public static function envelopeSize(array $to, array $cc, array $bcc): int
    {
        return count($to) + count($cc) + count($bcc);
    }
}
