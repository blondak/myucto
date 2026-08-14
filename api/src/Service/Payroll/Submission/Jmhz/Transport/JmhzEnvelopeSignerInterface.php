<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Šev pro podepisovací a šifrovací vrstvu. Transportní kostra obálku jen
 * sestaví; kryptografii dodává implementace tohoto rozhraní.
 *
 * Konkrétní algoritmus není v podkladech doložený. Jisté je jen to, že
 * VREP/APEP vyžaduje podepsanou a šifrovanou obálku (kvalifikovaný podpisový
 * certifikát + šifrovací certifikát ČSSZ DIS) a že protokol, který se vrací,
 * nese podpis jako base64 PKCS#7/CMS v `Message/Header/Signature/SignatureValue`
 * — nikoli jako strukturu XML-DSig. Odsud se nedá odvodit, co se podepisuje na
 * odchozí straně, takže tenhle šev nic nepředepisuje.
 *
 * Bez implementace se obálka nesmí prohlásit za odesílatelnou: nepodepsané
 * podání VREP odmítne a tichý fallback by z toho udělal ztracené podání
 * s promlčenou lhůtou.
 */
interface JmhzEnvelopeSignerInterface
{
    public function sign(string $envelopeXml): string;
}
