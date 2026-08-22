<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Infrastructure\Config\Config;

/**
 * H-05 — pojmenované profily retence záloh.
 *
 * Výchozí retence (30 denních / 365 měsíčních) dává smysl u self-hostu, kde
 * jsme jediná záloha, kterou zákazník má. Ve spravovaném provozu na cizí
 * infrastruktuře je ale zálohuje hosting a naše dumpy ujídají zaplacenou
 * kvótu — držet rok dozadu vlastní kopii je platba za totéž dvakrát.
 *
 * ⚠️ DVĚ JEDNOTKY, KTERÉ SE NESMÍ ZAMĚNIT
 *
 * Dokud běžela záloha jednou denně, „7 dní" a „7 kusů" bylo totéž. Od H-25
 * běží 4× denně a rozdíl je čtyřnásobný:
 *
 *   mode = 'days'   → 7 znamená 7 KALENDÁŘNÍCH DNŮ = při 4×/den 28 souborů,
 *   mode = 'copies' → 7 znamená 7 SOUBORŮ         = při 4×/den necelé 2 dny.
 *
 * Obojí je legitimní volba, ale musí být řečená explicitně. Proto profil vždy
 * nese obojí — jednotku i číslo — a `describe()` to vypíše do logu zálohy, ať
 * se provozovatel nemusí dohadovat, co si vlastně nastavil.
 *
 * Profil `managed` je ten z H-05: 7 denních záloh, žádné měsíční. V jednotce
 * KUSY, protože při 4×/den je to přesně ta varianta, která drží velikost
 * v kvótě — 7 dnů by znamenalo 28 dumpů.
 */
final class BackupRetentionPolicy
{
    public const MODE_DAYS   = 'days';
    public const MODE_COPIES = 'copies';

    public const PROFILE_DEFAULT = 'default';
    public const PROFILE_MANAGED = 'managed';

    /**
     * Pojmenované profily.
     *
     * @var array<string,array{mode:string,daily:int,monthly:int,note:string}>
     */
    private const PROFILES = [
        // Self-host: jsme jediná záloha, kterou zákazník má.
        self::PROFILE_DEFAULT => [
            'mode'    => self::MODE_DAYS,
            'daily'   => 30,
            'monthly' => 365,
            'note'    => '30 dnů denních + 1. v měsíci rok — chování před H-05.',
        ],
        // Spravovaná instalace: zálohuje hosting, naše kopie je jen rychlý
        // rollback po naší vlastní chybě (migrace, hromadná operace).
        self::PROFILE_MANAGED => [
            'mode'    => self::MODE_COPIES,
            'daily'   => 7,
            'monthly' => 0,
            'note'    => '7 POSLEDNÍCH SOUBORŮ, žádné měsíční — při 4×/den (H-25) necelé dva dny zpět.',
        ],
    ];

    private function __construct(
        public readonly string $profile,
        public readonly string $mode,
        public readonly int $daily,
        public readonly int $monthly,
    ) {}

    public static function named(string $profile): self
    {
        if (!isset(self::PROFILES[$profile])) {
            throw new InvalidArgumentException(sprintf(
                "Neznámý profil retence záloh '%s'. Známé: %s.",
                $profile,
                implode(', ', array_keys(self::PROFILES)),
            ));
        }
        $p = self::PROFILES[$profile];

        return new self($profile, $p['mode'], $p['daily'], $p['monthly']);
    }

    /** @return list<string> */
    public static function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    public static function note(string $profile): string
    {
        return self::PROFILES[$profile]['note'] ?? '';
    }

    /**
     * Účinná politika podle konfigurace.
     *
     * Pořadí:
     *   1. `cron.backup.retention_profile` = pojmenovaný profil (základ),
     *   2. jednotlivé klíče ho přebijí, když jsou v cfg vyplněné.
     *
     * Ruční klíče zůstávají kvůli zpětné kompatibilitě: instalace, které si
     * `daily_retention_days` nastavily ručně, se profilem nesmí přepsat.
     */
    public static function fromConfig(Config $config): self
    {
        $profileName = (string) $config->get('cron.backup.retention_profile', self::PROFILE_DEFAULT);
        $policy = isset(self::PROFILES[$profileName])
            ? self::named($profileName)
            : self::named(self::PROFILE_DEFAULT);

        $mode = (string) $config->get('cron.backup.retention_mode', $policy->mode);
        if ($mode !== self::MODE_DAYS && $mode !== self::MODE_COPIES) {
            $mode = $policy->mode;
        }

        $daily = $mode === self::MODE_COPIES
            ? $config->get('cron.backup.daily_retention_copies')
            : $config->get('cron.backup.daily_retention_days');
        $monthly = $mode === self::MODE_COPIES
            ? $config->get('cron.backup.monthly_retention_copies')
            : $config->get('cron.backup.monthly_retention_days');

        return new self(
            $profileName,
            $mode,
            is_numeric($daily) ? max(0, (int) $daily) : $policy->daily,
            is_numeric($monthly) ? max(0, (int) $monthly) : $policy->monthly,
        );
    }

    /** Věta do logu zálohy — jednotka musí být vidět, ne se odvozovat. */
    public function describe(): string
    {
        $unit = $this->mode === self::MODE_COPIES ? 'ks' : 'dnů';

        return sprintf(
            'retence [%s]: denní %d %s, měsíční %s',
            $this->profile,
            $this->daily,
            $unit,
            $this->monthly === 0 ? 'vypnuté' : $this->monthly . ' ' . $unit,
        );
    }

    /**
     * Kolik souborů profil fakticky udrží při dané frekvenci dumpu.
     *
     * Existuje kvůli tomu, aby šlo dopad H-25 spočítat, ne odhadnout:
     * „7" v režimu dnů znamená při 4×/den 28 souborů, v režimu kusů 7.
     */
    public function expectedDailyFiles(int $runsPerDay): int
    {
        $runsPerDay = max(1, $runsPerDay);

        return $this->mode === self::MODE_COPIES
            ? $this->daily
            : $this->daily * $runsPerDay;
    }

    /**
     * Které soubory smazat.
     *
     * @param array<string,DateTimeImmutable> $files cesta => čas zálohy (z názvu souboru)
     * @return list<string> cesty ke smazání
     */
    public function purgeList(array $files, DateTimeImmutable $now): array
    {
        if ($files === []) {
            return [];
        }

        // Nejnovější první — v režimu kusů rozhoduje pořadí, v režimu dnů stáří.
        uasort($files, static fn (DateTimeImmutable $a, DateTimeImmutable $b) => $b <=> $a);

        $monthly = [];
        $daily   = [];
        foreach ($files as $path => $at) {
            if ($this->monthly > 0 && $at->format('d') === '01') {
                $monthly[$path] = $at;
            } else {
                $daily[$path] = $at;
            }
        }

        return array_merge(
            $this->purgeBucket($daily, $this->daily, $now),
            $this->purgeBucket($monthly, $this->monthly, $now),
        );
    }

    /**
     * @param array<string,DateTimeImmutable> $bucket už seřazený od nejnovějšího
     * @return list<string>
     */
    private function purgeBucket(array $bucket, int $keep, DateTimeImmutable $now): array
    {
        if ($bucket === []) {
            return [];
        }

        // keep = 0 znamená „tuhle kategorii nedržet vůbec" (profil managed
        // nedrží měsíční zálohy). Není to „drž všechno" — mlčky obrácený
        // význam nuly je přesně ta chyba, kterou H-05 řeší.
        if ($keep === 0) {
            return array_keys($bucket);
        }

        if ($this->mode === self::MODE_COPIES) {
            return array_values(array_slice(array_keys($bucket), $keep));
        }

        $cutoff = $now->modify('-' . $keep . ' days');
        $purge = [];
        foreach ($bucket as $path => $at) {
            if ($at < $cutoff) {
                $purge[] = $path;
            }
        }

        return $purge;
    }
}
