<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Příchozí cesta kanálu — vyzvednutí seznamu nových zpráv a jejich stažení.
 *
 * Oddělené od {@see SubmissionChannel} schválně: EPO příchozí schránku nemá,
 * takže by ho jedno společné rozhraní nutilo implementovat metody, které
 * nedávají smysl, a někdo by je jednou vyplnil prázdným polem.
 */
interface SubmissionInboxChannel
{
    public function code(): string;

    /**
     * Seznam nových (nestažených) zpráv.
     *
     * @throws SubmissionChannelException když se dotaz nepovedl. Prázdný
     *         seznam znamená VÝHRADNĚ „ve schránce nic nového není".
     */
    public function listNew(ChannelContext $context): InboxListing;

    /**
     * Stáhne zprávu jako ZFO (PKCS#7 obálka s ISDS XML).
     *
     * @throws SubmissionChannelException
     */
    public function download(string $externalMessageId, ChannelContext $context): string;
}
