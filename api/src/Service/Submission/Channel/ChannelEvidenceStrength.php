<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Jak silný důkaz kanál vůbec DOKÁŽE vrátit.
 *
 * Není to popis, je to oprávnění. {@see \MyInvoice\Service\Submission\SubmissionOutboxService}
 * podle téhle hodnoty rozhoduje, jestli kanál smí posunout osu vyřízení:
 * kanál s `DeliveryOnly` nemá jak zjistit, co úřad rozhodl, takže jeho tvrzení
 * o přijetí se zahodí — a to i kdyby ho adaptér omylem (nebo po výměně
 * knihovny) začal vracet.
 */
enum ChannelEvidenceStrength: string
{
    /**
     * Kanál vrací strukturovaný protokol o zpracování (přijato / odmítnuto
     * s chybami). Typicky EPO.
     */
    case ProcessingProtocol = 'processing_protocol';

    /**
     * Kanál umí doložit jen doručení. Typicky datová schránka: doručenka je
     * důkaz, že zpráva dorazila do schránky úřadu, ne že ji úřad zpracoval.
     */
    case DeliveryOnly = 'delivery_only';

    public function canProveAcceptance(): bool
    {
        return $this === self::ProcessingProtocol;
    }
}
