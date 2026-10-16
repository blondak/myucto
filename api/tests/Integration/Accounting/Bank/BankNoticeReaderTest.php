<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Bank\BankMovementListService;
use MyInvoice\Service\Bank\BankPaymentCandidateScope;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use MyInvoice\Service\Bank\UnmatchedBankExportService;

final class BankNoticeReaderTest extends BankPostingTestCase
{
    public function testConfirmationFiltersAndPaginationKeepReviewVisible(): void
    {
        [$notice, $target] = $this->confirmedPair();
        $filters = ['scope' => 'all', 'account' => self::ACCOUNT, 'year' => self::YEAR];
        $page = $this->suggestionRepo->paginateUnposted($this->supplierId, 1, 0, $filters);
        self::assertSame(1, $page['total']);
        self::assertSame([$target], array_column($page['items'], 'id'));
        $history = $this->suggestionRepo->paginateUnposted($this->supplierId, 1, 0, $filters + ['confirmation_state' => 'confirmed']);
        self::assertSame(1, $history['total']);
        self::assertSame([$notice], array_column($history['items'], 'id'));
        self::assertSame(2, $this->suggestionRepo->paginateUnposted($this->supplierId, 0, 0, $filters + ['show_confirmed' => true])['total']);

        $this->invalidate($notice);
        $review = $this->suggestionRepo->paginateUnposted($this->supplierId, 1, 0, $filters + ['confirmation_state' => 'review']);
        self::assertSame(1, $review['total']);
        self::assertSame([$notice], array_column($review['items'], 'id'));
        $list = $this->container->get(BankMovementListService::class)->list($this->supplierId, $filters + ['confirmation_state' => 'review'], 'all');
        self::assertSame('review', $list['items'][0]['confirmation_state']);
        self::assertSame('authoritative_removed', $list['items'][0]['confirmation_reason']);
    }

    public function testCardCandidatesAndExportCannotReuseConfirmedOrInvalidatedNotice(): void
    {
        [$notice, $target] = $this->confirmedPair(-200.0);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4='1234' WHERE id IN (?, ?)")->execute([$notice, $target]);
        $cards = $this->container->get(CardPaymentOverview::class);
        $export = new UnmatchedBankExportService($this->db);
        foreach ([false, true] as $invalidated) {
            if ($invalidated) $this->invalidate($notice);
            $query = $this->db->pdo()->prepare('SELECT bt.id FROM bank_transactions bt WHERE bt.id IN (?, ?) AND ' . BankPaymentCandidateScope::sql($this->supplierId));
            $query->execute([$notice, $target]);
            self::assertSame([$target], array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN)));
            self::assertNull($cards->findCardTransaction($this->supplierId, $notice));
            self::assertNotNull($cards->findCardTransaction($this->supplierId, $target));
            self::assertSame(1, $cards->unmatched($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31')['count']);
            self::assertSame(1, $export->buildAll($this->supplierId, ['year' => self::YEAR, 'account' => self::ACCOUNT])['count']);
        }
    }

    /** @return array{int,int} */
    private function confirmedPair(float $amount = 200.0): array
    {
        $statement = $this->statement();
        $noticeStatement = $this->statement();
        $this->db->pdo()->prepare("UPDATE bank_statements SET source='email_notice' WHERE id=?")->execute([$noticeStatement]);
        $notice = $this->transaction($noticeStatement, $amount);
        $target = $this->transaction($statement, $amount);
        $sourceRef = 'synthetic-confirmed-' . $notice;
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source='email_notice',source_ref=? WHERE id=?")->execute([$sourceRef, $notice]);
        // Stav párování je nezávislý na potvrzení: i starší záznam s 'unmatched' se nesmí započítat.
        $this->db->pdo()->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,authoritative_transaction_id,original_authoritative_transaction_id,state,confirmed_at) VALUES (?,?,?,?,?,?,'confirmed',NOW())")
            ->execute([$this->supplierId, $notice, $sourceRef, hash('sha256', $sourceRef), $target, $target]);
        return [$notice, $target];
    }

    private function invalidate(int $notice): void
    {
        $this->db->pdo()->prepare("UPDATE bank_notice_confirmations SET state='review',reason='authoritative_removed',authoritative_transaction_id=NULL WHERE notice_transaction_id=?")
            ->execute([$notice]);
    }
}
