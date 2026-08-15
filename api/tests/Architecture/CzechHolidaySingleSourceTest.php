<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jeden zdroj pravdy o českých svátcích.
 *
 * ## Proč to hlídat
 *
 * Na otázku „je tenhle den pracovní" odpovídají dva vstupní body:
 * {@see CzechWorkingDays} pro lhůty podání (§ 33/4 daňového řádu) a
 * {@see CzechHolidayCalendar} pro fond pracovní doby ve mzdách. Dokud měl každý
 * z nich vlastní seznam svátků, byl rozchod jen otázkou času — a projevil by se
 * posunutou lhůtou nebo o den chybným fondem, tedy číslem, které vypadá
 * normálně a nikoho nenapadne ho zpochybnit.
 *
 * Duplicita je odstraněná: kalendář je nad `CzechWorkingDays` jen pojmenovaný
 * pohled. Tenhle test hlídá, aby se někdo v budoucnu nevrátil ke kopii — porovnává
 * odpovědi OBOU vstupních bodů den po dni na dlouhém úseku, ne jen na vzorku.
 *
 * ## Proč tak dlouhý úsek
 *
 * Pevné svátky by sedly i na jednom roce. Rozejít se umí právě pohyblivá část:
 * Velký pátek a Velikonoční pondělí se počítají algoritmem a jejich datum se
 * mezi roky posouvá o týdny. Sto let dnů projde i to.
 */
#[Group('architecture')]
final class CzechHolidaySingleSourceTest extends TestCase
{
    private const FIRST_YEAR = 1950;
    private const LAST_YEAR = 2100;

    public function testBothEntryPointsAgreeOnEveryDay(): void
    {
        $calendar = new CzechHolidayCalendar();
        $mismatches = [];
        $holidayCount = 0;

        for ($year = self::FIRST_YEAR; $year <= self::LAST_YEAR; $year++) {
            $named = $calendar->forYear($year);
            $day = new \DateTimeImmutable(sprintf('%04d-01-01', $year));

            while ((int) $day->format('Y') === $year) {
                $iso = $day->format('Y-m-d');
                $byCalendar = isset($named[$iso]);
                $byWorkingDays = CzechWorkingDays::isPublicHoliday($day);

                if ($byCalendar !== $byWorkingDays) {
                    $mismatches[] = sprintf(
                        '%s: kalendář=%s, pracovní dny=%s',
                        $iso,
                        $byCalendar ? 'svátek' : 'ne',
                        $byWorkingDays ? 'svátek' : 'ne',
                    );
                }
                if ($byCalendar) {
                    $holidayCount++;
                }

                $day = $day->modify('+1 day');
            }
        }

        // Pojistka proti prázdné shodě: kdyby oba zdroje přestaly svátky vracet,
        // porovnání by prošlo a nehlídalo nic.
        self::assertSame(
            13 * (self::LAST_YEAR - self::FIRST_YEAR + 1),
            $holidayCount,
            'Rok musí mít 13 svátků — 11 pevných a 2 velikonoční.',
        );

        self::assertSame([], array_slice($mismatches, 0, 20), sprintf(
            "Zdroje svátků se rozešly (%d dnů):\n  %s\n\n"
                . 'Kalendář svátků nesmí mít vlastní seznam — je to pohled na CzechWorkingDays.',
            count($mismatches),
            implode("\n  ", array_slice($mismatches, 0, 20)),
        ));
    }

    /**
     * Fond pracovní doby potřebuje ke každému svátku i kód a název. Test hlídá,
     * že sjednocení o ten popis nepřišlo — bez něj by se v docházce místo
     * „Velký pátek" zobrazil prázdný řádek.
     */
    public function testEveryHolidayCarriesCodeAndName(): void
    {
        $calendar = new CzechHolidayCalendar();
        $incomplete = [];

        foreach ($calendar->forYear(2026) as $date => $holiday) {
            if (trim($holiday['code']) === '' || trim($holiday['name']) === '') {
                $incomplete[] = $date;
            }
        }

        self::assertSame([], $incomplete, 'Svátek bez kódu nebo názvu.');
        self::assertArrayHasKey('2026-04-03', $calendar->forYear(2026), 'Velký pátek 2026.');
        self::assertArrayHasKey('2026-04-06', $calendar->forYear(2026), 'Velikonoční pondělí 2026.');
    }

    /**
     * V repozitáři nesmí vzniknout třetí seznam svátků. Sada pevných dnů se pozná
     * podle dvojice `07-05` a `11-17`, kterou jinde v kódu nic jiného nespojuje.
     */
    public function testNoOtherFileDeclaresItsOwnHolidayList(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/src';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with(str_replace('\\', '/', $path), 'Service/Report/CzechWorkingDays.php')) {
                continue; // jediný povolený zdroj
            }
            $code = (string) file_get_contents($path);
            if (str_contains($code, '07-05') && str_contains($code, '11-17')) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Vlastní seznam českých svátků mimo CzechWorkingDays:\n  %s\n\n"
                . 'Použij CzechWorkingDays::holidaysForYear() — dva seznamy se rozejdou.',
            implode("\n  ", $offenders),
        ));
    }
}
