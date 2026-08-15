<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Osa DOPRAVY — co víme o cestě zprávy k příjemci.
 *
 * Záměrně neobsahuje nic o tom, jak podání dopadlo u úřadu; to je
 * {@see AcceptanceState}. Rozdělení je jádro celého modulu: datová schránka
 * vrací doručenku, tedy důkaz o doručení, ne o zpracování.
 */
enum DispatchState: string
{
    /** Připraveno, čeká na potvrzení člověkem. Automat sem smí, dál ne. */
    case Ready = 'ready';
    /** Člověk potvrdil, volání kanálu běží. */
    case Sending = 'sending';
    /**
     * Volání se přerušilo a NEVÍME, jestli zpráva odešla. Vlastní stav proto,
     * že „failed" by svedl k odeslání duplicity a „sent" by ztratil podání,
     * které nikdy neodešlo. Dořeší se dohledáním přes correlation reference.
     */
    case SendUncertain = 'send_uncertain';
    /** Kanál zprávu přijal a vrátil její identifikátor. */
    case Sent = 'sent';
    /** Máme důkaz o doručení (u ISDS doručenku). Pořád NEJDE o přijetí úřadem. */
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Stav, ze kterého už doprava sama od sebe nikam nepokročí. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /** Odešla zpráva prokazatelně ven? */
    public function hasLeft(): bool
    {
        return match ($this) {
            self::Sent, self::Delivered => true,
            default => false,
        };
    }
}
