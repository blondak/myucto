<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\AgendaReceiptCapability;
use PHPUnit\Framework\TestCase;

/**
 * Co aplikace umí přečíst z potvrzení — a hlavně, u čeho přiznává, že neumí nic.
 *
 * Tenhle test existuje kvůli jedinému tvrzení: **neznámá agenda nesmí dostat
 * schopnost, kterou nemáme.** Parser napsaný podle dohadu o cizím schématu je
 * horší než žádný — žádný mlčí a věc řeší člověk, chybný tiše uzavře povinnost,
 * která uzavřená není.
 */
final class AgendaReceiptCapabilityTest extends TestCase
{
    /** ČSSZ vrací protokol o zpracování s doloženým XSD. */
    public function testCsszAgendasHaveAProcessingProtocol(): void
    {
        foreach (['JMHZ25', 'jmhz25', ' REGZEC25 ', 'PREZEC26'] as $agenda) {
            self::assertSame(
                AgendaReceiptCapability::ProcessingProtocol,
                AgendaReceiptCapability::forAgenda($agenda),
            );
        }
    }

    /** Neznámá agenda končí fail-closed, ne na nejbližší podobné. */
    public function testUnknownAgendaIsUndocumented(): void
    {
        foreach (['HOZ', 'PPPZ', 'VZP_PREHLED', '', 'NECO_NOVEHO'] as $agenda) {
            self::assertSame(
                AgendaReceiptCapability::Undocumented,
                AgendaReceiptCapability::forAgenda($agenda),
                'Neznámá agenda nesmí dostat schopnost, kterou nemáme doloženou.',
            );
        }
    }

    /**
     * Zdravotní pojišťovny zůstávají fail-closed vědomě: elektronické podání
     * u nich není podporované (`HealthPaymentOverviewService`) a doložený tvar
     * odpovědi nemáme, takže není co parsovat.
     */
    public function testHealthInsurerAgendasStayUndocumented(): void
    {
        self::assertSame(
            AgendaReceiptCapability::Undocumented,
            AgendaReceiptCapability::forChannel('health_portal', 'HOZ'),
        );
        self::assertStringContainsString('ručně', AgendaReceiptCapability::Undocumented->sentence());
    }

    /**
     * Kanál má přednost před agendou. Totéž přiznání k DPH má přes EPO
     * potvrzení s podacím číslem, ale odeslané ručně datovkou dostane jen
     * dodejku — slibovat u něj protokol by znamenalo čekat na něco, co nepřijde.
     */
    public function testChannelDecidesWhatCanBeRead(): void
    {
        self::assertSame(
            AgendaReceiptCapability::ProcessingProtocol,
            AgendaReceiptCapability::forChannel('epo', 'DPHDP3'),
        );
        self::assertSame(
            AgendaReceiptCapability::DeliveryReceiptOnly,
            AgendaReceiptCapability::forChannel('isds', 'DPHDP3'),
        );
        self::assertSame(
            AgendaReceiptCapability::ProcessingProtocol,
            AgendaReceiptCapability::forChannel('vrep_apep', 'JMHZ25'),
        );
        // ČSSZ posílá protokol do datové schránky jako samostatnou zprávu.
        self::assertSame(
            AgendaReceiptCapability::ProcessingProtocol,
            AgendaReceiptCapability::forChannel('isds', 'JMHZ25'),
        );
        self::assertSame(
            AgendaReceiptCapability::Undocumented,
            AgendaReceiptCapability::forChannel('manual_upload', 'JMHZ25'),
        );
    }

    /** Dodejka nikdy nesmí znít jako doklad o vyřízení. */
    public function testDeliveryReceiptSentenceNeverClaimsAcceptance(): void
    {
        $sentence = AgendaReceiptCapability::DeliveryReceiptOnly->sentence();

        self::assertStringContainsString('DORUČENÍ', $sentence);
        self::assertStringContainsString('neplyne', $sentence);
    }

    /** Každý stav umí říct, co po uživateli chce. */
    public function testEveryCapabilityExplainsItself(): void
    {
        foreach (AgendaReceiptCapability::cases() as $case) {
            self::assertNotSame('', trim($case->sentence()));
        }
    }
}
