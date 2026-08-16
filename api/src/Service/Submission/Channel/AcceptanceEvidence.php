<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Slovník důkazů, kterými smí být podložený posun na ose {@see AcceptanceState}.
 *
 * ⚠️ Doručenka tu ZÁMĚRNĚ nemá hodnotu a nikdy mít nebude.
 *
 * Doručenka je potvrzení datové schránky, že zpráva byla dodána a přihlášením
 * oprávněné osoby doručena. O tom, jestli úřad podání zpracoval a přijal,
 * neříká nic. Kdyby tu byla položka `isds_delivery_receipt`, dřív nebo později
 * ji někdo použije — a podání se začne tvářit jako přijaté, aniž by o něm úřad
 * cokoliv rozhodl. Tenhle výčet je poslední místo, kde jde takové slovo
 * nevymyslet, a proto se nevymýšlí. Stejný výčet drží i DB ENUM
 * `submission_outbox.acceptance_evidence_kind` (migrace 1381).
 */
enum AcceptanceEvidence: string
{
    /** Strukturovaný protokol o přijetí z EPO. */
    case EpoProtocol = 'epo_protocol';
    /** Protokol/vyrozumění od úřadu doručené jako samostatná zpráva. */
    case AgencyProtocolMessage = 'agency_protocol_message';
    /** Člověk viděl potvrzení mimo aplikaci a zapsal ho na svou odpovědnost. */
    case ManualConfirmation = 'manual_confirmation';
}
