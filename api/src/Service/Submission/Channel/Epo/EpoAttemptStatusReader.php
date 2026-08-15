<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Epo;

/**
 * Čtecí port nad existující EPO cestou.
 *
 * Stejný princip jako {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsTransport}:
 * kanál nezná ani tabulku, ani službu, jen tenhle tvar. Díky tomu jde
 * {@see EpoChannel} testovat bez databáze.
 */
interface EpoAttemptStatusReader
{
    /**
     * @return array{status:string,submission_ref:?string,decided_at:?string,error_message:?string}|null
     *         null = takový pokus neexistuje
     */
    public function findAttempt(int $supplierId, string $attemptReference): ?array;

    /** @return array{filename:string,mime:string,bytes:string}|null */
    public function confirmation(int $supplierId, string $attemptReference): ?array;
}
