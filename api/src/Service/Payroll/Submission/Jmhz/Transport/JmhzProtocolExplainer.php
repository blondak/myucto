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
 * katalog: skutečný protokol vrátil kód 20022, jehož kontrola ve slovníku
 * 1.4.1.6 vůbec není. Kdyby se doplnění dělalo fail-closed, shodil by takový
 * protokol celé zpracování odpovědi — tedy přesně ve chvíli, kdy uživatel
 * potřebuje vědět, proč mu podání neprošlo. Neznámá kontrola proto zůstane
 * nedoplněná a hláška z protokolu se ukáže tak, jak přišla.
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
        $explained = [];
        foreach ($report->errors as $error) {
            $explained[] = $this->describe($error, null, null, null);
        }
        foreach ($report->parts as $part) {
            foreach ($part->errors as $error) {
                $explained[] = $this->describe(
                    $error,
                    $part->formGuid,
                    $part->ikMpsv,
                    $part->idPpv,
                );
            }
        }

        return $explained;
    }

    /** @return array<string,mixed> */
    private function describe(
        JmhzProtocolError $error,
        ?string $formGuid,
        ?string $ikMpsv,
        ?string $idPpv,
    ): array {
        $described = [
            'code' => $error->code,
            'origin' => $error->origin->value,
            'message' => $error->message,
            'control_id' => $error->controlId?->value,
            'form_guid' => $formGuid,
            'ik_mpsv' => $ikMpsv,
            'id_ppv' => $idPpv,
            'control' => null,
        ];
        if ($error->controlId === null) {
            return $described;
        }
        $catalog = $this->catalog ?? JmhzControlSourceCatalog::load();
        try {
            $definition = $catalog->definition($error->controlId->value);
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
