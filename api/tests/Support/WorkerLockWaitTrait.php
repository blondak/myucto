<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use Symfony\Component\Process\Process;

/**
 * Bariéra pro souběhové testy: druhý PHP proces (worker) se připojí k DB a test
 * potřebuje doložit, že worker opravdu čeká na zámek, který drží test.
 *
 * Start workeru a čekání na zámek mají ODDĚLENÉ lhůty. Dřív sdílely jeden
 * deadline, takže na vytíženém CI runneru spolkl bootstrap aplikace skoro celou
 * lhůtu a na zjištění čekání nezbyl čas — test padal, i když zámek fungoval.
 *
 * Čekání se dokládá třemi nezávislými signály (stačí kterýkoli):
 *   1. řádek v INNODB_LOCK_WAITS,
 *   2. `INNODB_TRX.trx_state = 'LOCK WAIT'` pro session workeru — kanonický stav
 *      transakce; hlásí ho i instalace, kde zůstává INNODB_LOCK_WAITS prázdné
 *      (odtud padal test na CI, zatímco lokálně procházel),
 *   3. worker aspoň 250 ms drží rozpracovaný právě ten zamykající příkaz (stejný
 *      záložní signál jako SalesOrderConfirmConcurrencyTest).
 * Bez zámku by takový příkaz doběhl v milisekundách; o správnosti serializace
 * pak rozhoduje až aserce výsledku.
 *
 * POZOR na vzor pro signál 3: `PROCESSLIST.INFO` ukazuje u zámku braného UVNITŘ
 * TRIGGERU příkaz z těla triggeru, ne vnější INSERT. Vzor proto musí popisovat
 * příkaz, který zámek opravdu bere — jinak záložní signál nemůže nikdy sepnout
 * a test visí na signálech 1–2.
 */
trait WorkerLockWaitTrait
{
    /** @return array<string,mixed> obsah řídicího souboru s `connection_id` */
    protected function awaitWorkerReady(Process $process, string $controlFile, float $timeoutSeconds = 20.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            clearstatcache(true, $controlFile);
            if (is_file($controlFile)) {
                $decoded = json_decode((string) file_get_contents($controlFile), true);
                if (is_array($decoded) && ($decoded['ready'] ?? false) === true && (int) ($decoded['connection_id'] ?? 0) > 0) {
                    return $decoded;
                }
            }
            if (!$process->isRunning()) {
                self::fail(sprintf(
                    'Worker skončil (exit %s) dřív, než zveřejnil ID databázové session. %s%s',
                    var_export($process->getExitCode(), true),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf(
            'Worker nezveřejnil ID databázové session do %.0f s. %s%s',
            $timeoutSeconds,
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    protected function assertWorkerWaitsOnLock(
        PDO $pdo,
        Process $process,
        int $connectionId,
        string $lockingStatementPattern,
        string $message,
        float $timeoutSeconds = 15.0,
    ): void {
        $probe = $pdo->prepare(
            'SELECT
                EXISTS(
                    SELECT 1
                      FROM information_schema.INNODB_LOCK_WAITS w
                      JOIN information_schema.INNODB_TRX waiter ON waiter.trx_id = w.requesting_trx_id
                     WHERE waiter.trx_mysql_thread_id = ?
                ) AS has_lock_wait,
                EXISTS(
                    SELECT 1
                      FROM information_schema.INNODB_TRX waiter
                     WHERE waiter.trx_mysql_thread_id = ? AND waiter.trx_state = \'LOCK WAIT\'
                ) AS trx_in_lock_wait,
                COALESCE((SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?), \'\') AS current_statement'
        );
        $deadline = microtime(true) + $timeoutSeconds;
        $statementSince = null;
        do {
            $probe->execute([$connectionId, $connectionId, $connectionId]);
            $row = $probe->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int) ($row['has_lock_wait'] ?? 0) > 0 || (int) ($row['trx_in_lock_wait'] ?? 0) > 0) {
                return;
            }
            if (preg_match($lockingStatementPattern, (string) ($row['current_statement'] ?? '')) === 1) {
                $statementSince ??= microtime(true);
                if (microtime(true) - $statementSince >= 0.25) {
                    return;
                }
            } else {
                $statementSince = null;
            }
            if (!$process->isRunning()) {
                self::fail(sprintf(
                    '%s Worker skončil (exit %s) dřív, než začal čekat na zámek. %s%s',
                    $message,
                    var_export($process->getExitCode(), true),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $state = $pdo->prepare('SELECT COMMAND, TIME, STATE, LEFT(INFO, 160) AS INFO FROM information_schema.PROCESSLIST WHERE ID = ?');
        $state->execute([$connectionId]);
        self::fail(sprintf(
            '%s Session workeru po %.0f s: %s %s%s',
            $message,
            $timeoutSeconds,
            json_encode($state->fetch(PDO::FETCH_ASSOC) ?: null, JSON_UNESCAPED_UNICODE),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }
}
