<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Souborový zámek „jeden běžící export na firmu".
 *
 * Databázový UNIQUE index (`uq_instance_exports_active`, migrace 1520) hlídá, že
 * nevzniknou dva aktivní joby téže firmy. Sám o sobě ale nestačí: export jde spustit
 * i z CLI bez jobu (`export-instance.php --supplier=N`) a mezi „přečti si stav" a
 * „zapiš si běh" je vždycky okno. Zámek přes `flock()` je druhá, procesní vrstva —
 * drží se po celou dobu běhu a spadne s procesem, takže po killu nezůstane viset.
 *
 * Zámek je per firma, ne globální: účetní kancelář si smí exportovat dvě různé firmy
 * současně, jen ne dvakrát tutéž.
 *
 * Neblokující (`LOCK_NB`) záměrně — druhý požadavek se má odmítnout hned s „už běží",
 * ne se zařadit do fronty. Fronta by z exportu udělala DoS vektor: deset požadavků =
 * deset čekajících PHP procesů.
 */
final class InstanceExportLock
{
    /** @var resource|null */
    private $handle = null;

    private function __construct(private readonly string $path) {}

    /**
     * Zkusí získat zámek firmy. Vrací null, když už export běží.
     */
    public static function tryAcquire(int $supplierId): ?self
    {
        $dir = RuntimePaths::storage('locks');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new InstanceExportException('lock_dir_failed', 'Nelze vytvořit adresář zámků: ' . $dir);
        }
        $path = $dir . '/instance-export-sup' . $supplierId . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new InstanceExportException('lock_open_failed', 'Nelze otevřít zámek exportu: ' . $path);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        $lock = new self($path);
        $lock->handle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid() . ' ' . date('c') . "\n");
        fflush($handle);
        return $lock;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
        // Soubor necháváme být — jeho existence nic neznamená, zámek drží jen flock.
        // Mazání by otevřelo klasický race (dva procesy nad různými inody téhož jména).
    }

    public function __destruct()
    {
        $this->release();
    }

    public function path(): string
    {
        return $this->path;
    }
}
