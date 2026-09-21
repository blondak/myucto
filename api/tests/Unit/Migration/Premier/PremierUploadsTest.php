<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Rozbalená záloha (celá databáze firmy) nesmí na disku ležet, dokud ji neuklidí další
 * upload téže firmy — denní úklid ji po týdnu nečinnosti smaže u všech firem.
 */
final class PremierUploadsTest extends TestCase
{
    private const SUPPLIER_STALE = 2147480011;
    private const SUPPLIER_FRESH = 2147480012;

    protected function tearDown(): void
    {
        foreach ([self::SUPPLIER_STALE, self::SUPPLIER_FRESH] as $supplierId) {
            $base = PremierUploads::base($supplierId);
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

        $removed = PremierUploads::purgeStaleAll();

        self::assertDirectoryDoesNotExist($stale);
        self::assertDirectoryExists($fresh);
        self::assertGreaterThanOrEqual(1, $removed);
    }

    /** Rozpracované nahrávání po částech ještě nemá meta.json — živé ho drží čerstvá část. */
    public function testChunkedUploadInProgressIsKeptWhileItsPartIsFresh(): void
    {
        $dir = $this->chunkedUpload(self::SUPPLIER_STALE, str_repeat('d', 16), time() - 8 * 86400, time() - 60);

        PremierUploads::purgeStaleAll();

        self::assertDirectoryExists($dir);
    }

    public function testAbandonedChunkedUploadIsRemovedAfterAWeek(): void
    {
        $dir = $this->chunkedUpload(self::SUPPLIER_STALE, str_repeat('e', 16), time() - 8 * 86400, time() - 8 * 86400);

        PremierUploads::purgeStaleAll();

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testUploadBeingProcessedByJobIsNotRemoved(): void
    {
        $token = str_repeat('f', 16);
        $dir = $this->upload(self::SUPPLIER_STALE, $token, time() - 8 * 86400);
        $lock = PremierUploads::acquireJobLock(self::SUPPLIER_STALE, $token);
        self::assertNotNull($lock);
        self::assertNull(PremierUploads::acquireJobLock(self::SUPPLIER_STALE, $token), 'Druhý job téže zálohy zámek nedostane.');

        PremierUploads::purgeStaleAll();
        self::assertDirectoryExists($dir);

        PremierUploads::releaseJobLock($lock);
        PremierUploads::purgeStaleAll();
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testChunkIsAcceptedOnlyWhereTheServerStopped(): void
    {
        $token = str_repeat('1', 16);
        $this->chunkedUpload(self::SUPPLIER_FRESH, $token, time(), time(), 10);
        $streams = new StreamFactory();

        self::assertSame(5, PremierUploads::appendChunk(self::SUPPLIER_FRESH, $token, 0, $streams->createStream('12345'), 8));

        $repeated = $this->chunkError(fn () => PremierUploads::appendChunk(self::SUPPLIER_FRESH, $token, 0, $streams->createStream('12345'), 8));
        self::assertSame([409, 'chunk_offset_mismatch', 5], [$repeated->getCode(), $repeated->errorCode, $repeated->context['received']]);

        $tooLong = $this->chunkError(fn () => PremierUploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('678901'), 8));
        self::assertSame([422, 'chunk_exceeds_size'], [$tooLong->getCode(), $tooLong->errorCode]);
        self::assertSame(5, PremierUploads::partSize(self::SUPPLIER_FRESH, $token), 'Odmítnutá část na disku nezůstane.');

        $tooBig = $this->chunkError(fn () => PremierUploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('67890'), 4));
        self::assertSame(413, $tooBig->getCode());

        self::assertSame(10, PremierUploads::appendChunk(self::SUPPLIER_FRESH, $token, 5, $streams->createStream('67890'), 8));
        self::assertSame(10, PremierUploads::state(self::SUPPLIER_FRESH, $token)['received']);
        self::assertSame('1234567890', file_get_contents(PremierUploads::partPath(self::SUPPLIER_FRESH, $token)));
    }

    private function chunkError(callable $append): PremierException
    {
        try {
            $append();
        } catch (PremierException $e) {
            return $e;
        }
        self::fail('Část zálohy měla být odmítnuta.');
    }

    private function chunkedUpload(int $supplierId, string $token, int $stateMtime, int $partMtime, int $size = 100): string
    {
        $dir = PremierUploads::dir($supplierId, $token);
        mkdir($dir, 0755, true);
        PremierUploads::writeState($supplierId, $token, ['file_name' => 'zaloha.izip', 'size' => $size, 'received' => 0, 'status' => PremierUploads::STATUS_UPLOADING]);
        touch($dir . '/upload.json', $stateMtime);
        touch(PremierUploads::partPath($supplierId, $token), $partMtime);
        @touch($dir, min($stateMtime, $partMtime));
        return $dir;
    }

    private function upload(int $supplierId, string $token, int $mtime): string
    {
        $dir = PremierUploads::dir($supplierId, $token);
        mkdir($dir . '/backup', 0755, true);
        file_put_contents($dir . '/backup/PUB_UCTO.DBF', 'data');
        file_put_contents($dir . '/meta.json', '{}');
        touch($dir . '/meta.json', $mtime);
        return $dir;
    }
}
