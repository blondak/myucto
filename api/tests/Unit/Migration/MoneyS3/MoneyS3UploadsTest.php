<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Rozbalená agenda (celé účetnictví firmy) nesmí na disku ležet, dokud ji neuklidí další
 * upload téže firmy — denní úklid ji po týdnu nečinnosti smaže u všech firem.
 */
final class MoneyS3UploadsTest extends TestCase
{
    private const SUPPLIER_STALE = 2147480001;
    private const SUPPLIER_FRESH = 2147480002;

    protected function tearDown(): void
    {
        foreach ([self::SUPPLIER_STALE, self::SUPPLIER_FRESH] as $supplierId) {
            $base = MoneyS3Uploads::base($supplierId);
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($base);
        }
    }

    public function testDailyCleanupRemovesStaleUploadsOfEveryCompany(): void
    {
        $stale = $this->upload(self::SUPPLIER_STALE, str_repeat('a', 16), time() - 8 * 86400);
        $fresh = $this->upload(self::SUPPLIER_FRESH, str_repeat('b', 16), time() - 3600);

        $removed = MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryDoesNotExist($stale);
        self::assertDirectoryExists($fresh);
        self::assertGreaterThanOrEqual(1, $removed);
    }

    public function testRecentlyAttachedReportKeepsOldUploadAlive(): void
    {
        $dir = $this->upload(self::SUPPLIER_STALE, str_repeat('c', 16), time() - 8 * 86400);
        mkdir($dir . '/reports', 0755, true);
        file_put_contents($dir . '/reports/2024.csv', 'účet;PS');

        MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryExists($dir);
    }

    /** Rozpracované nahrávání po částech ještě nemá meta.json — živé ho drží čerstvá část. */
    public function testChunkedUploadInProgressIsKeptWhileItsPartIsFresh(): void
    {
        $dir = $this->chunkedUpload(self::SUPPLIER_STALE, str_repeat('d', 16), time() - 8 * 86400, time() - 60);

        MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryExists($dir);
    }

    public function testAbandonedChunkedUploadIsRemovedAfterAWeek(): void
    {
        $dir = $this->chunkedUpload(self::SUPPLIER_STALE, str_repeat('e', 16), time() - 8 * 86400, time() - 8 * 86400);

        MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testUploadBeingProcessedByJobIsNotRemoved(): void
    {
        $token = str_repeat('f', 16);
        $dir = $this->upload(self::SUPPLIER_STALE, $token, time() - 8 * 86400);
        $lock = MoneyS3Uploads::acquireJobLock(self::SUPPLIER_STALE, $token);
        self::assertNotNull($lock);
        self::assertNull(MoneyS3Uploads::acquireJobLock(self::SUPPLIER_STALE, $token), 'Druhý job téže zálohy zámek nedostane.');

        MoneyS3Uploads::purgeStaleAll();
        self::assertDirectoryExists($dir);

        MoneyS3Uploads::releaseJobLock($lock);
        MoneyS3Uploads::purgeStaleAll();
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testChunkIsAcceptedOnlyWhereTheServerStopped(): void
    {
        $token = str_repeat('1', 16);
        $this->chunkedUpload(self::SUPPLIER_FRESH, $token, time(), time(), 10);
        $streams = new StreamFactory();

        self::assertSame(5, MoneyS3Uploads::appendChunk(self::SUPPLIER_FRESH, $token, 0, $streams->createStream('12345'), 8));

        $repeated = $this->chunkError(fn () => MoneyS3Uploads::appendChunk(self::SUPPLIER_FRESH, $token, 0, $streams->createStream('12345'), 8));
        self::assertSame([409, 'chunk_offset_mismatch', 5], [$repeated->getCode(), $repeated->errorCode, $repeated->context['received']]);

        $tooLong = $this->chunkError(fn () => MoneyS3Uploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('678901'), 8));
        self::assertSame([422, 'chunk_exceeds_size'], [$tooLong->getCode(), $tooLong->errorCode]);
        self::assertSame(5, MoneyS3Uploads::partSize(self::SUPPLIER_FRESH, $token), 'Odmítnutá část na disku nezůstane.');

        $tooBig = $this->chunkError(fn () => MoneyS3Uploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('67890'), 4));
        self::assertSame(413, $tooBig->getCode());

        self::assertSame(10, MoneyS3Uploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('67890'), 8));
        self::assertSame(10, MoneyS3Uploads::state(self::SUPPLIER_FRESH, $token)['received']);
        self::assertSame('1234567890', file_get_contents(MoneyS3Uploads::partPath(self::SUPPLIER_FRESH, $token)));
    }

    private function chunkError(callable $append): MoneyS3Exception
    {
        try {
            $append();
        } catch (MoneyS3Exception $e) {
            return $e;
        }
        self::fail('Část zálohy měla být odmítnuta.');
    }

    private function chunkedUpload(int $supplierId, string $token, int $stateMtime, int $partMtime, int $size = 100): string
    {
        $dir = MoneyS3Uploads::dir($supplierId, $token);
        mkdir($dir, 0755, true);
        MoneyS3Uploads::writeState($supplierId, $token, ['file_name' => 'agenda.lz', 'size' => $size, 'received' => 0, 'status' => MoneyS3Uploads::STATUS_UPLOADING]);
        touch($dir . '/upload.json', $stateMtime);
        touch(MoneyS3Uploads::partPath($supplierId, $token), $partMtime);
        @touch($dir, min($stateMtime, $partMtime));
        return $dir;
    }

    private function upload(int $supplierId, string $token, int $mtime): string
    {
        $dir = MoneyS3Uploads::dir($supplierId, $token);
        mkdir($dir . '/agenda', 0755, true);
        file_put_contents($dir . '/agenda/UcDenik.DAT', 'data');
        file_put_contents($dir . '/meta.json', '{}');
        touch($dir . '/meta.json', $mtime);
        return $dir;
    }
}
