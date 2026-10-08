<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;

/**
 * Překlad protokolu ČSSZ do podoby, se kterou se dá něco udělat.
 *
 * Holá chybová hláška z protokolu říká, CO je špatně, ale ne kde to hledat.
 * Kód chyby přitom u kontrol nese ID kontroly (DIS = ID + 20000), a katalog
 * k němu zná dotčené atributy, oblast i celé znění pravidla — z „Pojistné
 * neodpovídá vyměřovacímu základu" se tak dá udělat „u zaměstnance X neodpovídá
 * atribut 10370 základu 10477".
 *
 * **Fail-open, a to schválně.** Prostor chybových kódů ČSSZ je širší než náš
 * katalog: skutečný protokol vrátil kód 20022 dřív, než katalog 1.4.2.10
 * kontrolu 22 zveřejnil. Kdyby se doplnění dělalo fail-closed, shodil by takový
 * protokol celé zpracování odpovědi — tedy přesně ve chvíli, kdy uživatel
 * potřebuje vědět, proč mu podání neprošlo. Neznámá kontrola proto zůstane
 * nedoplněná a hláška z protokolu se ukáže tak, jak přišla.
 *
 * Chyba 20022 ve variantě „shodné R nebo S už existuje" nese příznak
 * `original_at_cssz`: neznamená zamítnutí, ale to, že originál podání u ČSSZ
 * je ({@see JmhzProtocolError::reportsExistingIdenticalSubmission()}).
 *
 * U kontrol, ke kterým ČSSZ zveřejnila postup (FAQ 9. 6. 2026, Katalog
 * kontrol 1.4.2.10), se doplní i `remediation`: kód nápravy, místo
 * v aplikaci a atributy, které musí zůstat prázdné
 * ({@see JmhzProtocolRemediationCatalog}). Bez nápravy zůstává `null`.
 *
 * Doplňuje se jen to, co je doložené. Nic se nedopočítává a nic se nehádá:
 * u platformních kódů (odmítnutí na vstupu, obálka, podpis) žádná kontrola
 * neexistuje a odvodit ji z čísla by ukázalo na pravidlo, o které vůbec nešlo.
 */
final readonly class JmhzProtocolExplainer
{
    public function __construct(private ?JmhzControlSourceCatalog $catalog = null) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function explain(JmhzProtocolReport $report): array
    {
        // Protokoly nesou touž chybu dvakrát: jednou v souhrnu a jednou
        // u součásti, ke které patří. Vypsat obojí znamená ukázat uživateli
        // dvě chyby tam, kde je jedna — a on pak hledá druhou závadu, která
        // neexistuje. Zůstává ta UMÍSTĚNÁ, protože říká i to, koho se týká.
        $located = [];
        foreach ($report->parts as $part) {
            foreach ($part->errors as $error) {
                $located[] = $this->describe(
                    $error,
                    $part->formGuid,
                    $part->ikMpsv,
                    $part->idPpv,
                );
            }
        }
        $seen = [];
        foreach ($located as $item) {
            $seen[$item['code'] . '|' . $item['message']] = true;
        }

        $summary = [];
        foreach ($report->errors as $error) {
            if (isset($seen[$error->code . '|' . $error->message])) {
                continue;
            }
            $summary[] = $this->describe($error, null, null, null);
        }

        return [...$summary, ...$located];
    }

    /** @return array<string,mixed> */
    private function describe(
        JmhzProtocolError $error,
        ?string $formGuid,
        ?string $ikMpsv,
        ?string $idPpv,
    ): array {
        // Registrační protokol nese kód post DIS validace bez kontroly (viz
        // JmhzProtocolError::fromRegistrationCode); ke kontrole 262/263 se
        // dohledá přes touž mapu, kterou používá parser JMHZ.
        $controlId = $error->controlId ?? JmhzProtocolError::postDisValidationControl($error->code);
        $described = [
            'code' => $error->code,
            'origin' => $error->origin->value,
            'message' => $error->message,
            'control_id' => $controlId?->value,
            'form_guid' => $formGuid,
            'ik_mpsv' => $ikMpsv,
            'id_ppv' => $idPpv,
            'control' => null,
            'remediation' => null,
            'original_at_cssz' => $error->reportsExistingIdenticalSubmission(),
        ];
        if ($controlId === null) {
            return $described;
        }
        // Náprava nezávisí na připnutém katalogu: kontrolu 219 katalog
        // 1.4.2.10 zrušil, FAQ ČSSZ ji ale dál vysvětluje.
        $described['remediation'] = JmhzProtocolRemediationCatalog::forControl($controlId);
        $catalog = $this->catalog ?? JmhzControlSourceCatalog::load();
        try {
            $definition = $catalog->definition($controlId->value);
        } catch (\OutOfBoundsException) {
            // Kontrola, kterou náš slovník nezná. Viz docblock — nedoplní se
            // nic a jde se dál; hláška z protokolu uživateli zůstává.
            return $described;
        }
        $described['control'] = [
            'name' => $definition->name,
            'detail' => $definition->detail,
            'area' => $definition->area,
            'category' => $definition->category,
            'attribute_ids' => $definition->attributeIds,
        ];

        return $described;
    }
}
