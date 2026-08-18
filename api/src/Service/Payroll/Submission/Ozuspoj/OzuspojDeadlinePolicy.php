<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůta oznámení záměru uplatňovat slevu na pojistném (OZUSPOJ).
 *
 * Dolní hranice — § 7a odst. 5 věta druhá: „Záměr uplatňovat slevu na pojistném
 * za jednotlivého zaměstnance může zaměstnavatel oznámit nejdříve 1 měsíc přede
 * dnem, od kterého bude slevu na pojistném za tohoto zaměstnance uplatňovat,
 * ne však dříve než dnem podání oznámení o nástupu tohoto zaměstnance do
 * zaměstnání podle zákona o nemocenském pojištění." Druhá polovina věty je
 * proto samostatný parametr `registrationSubmittedOn` — je to den podání
 * přihlášky (PREZEC/REGZEC), ne den nástupu do práce.
 *
 * Horní hranice — § 7a odst. 5 věta první žádá oznámení „nejpozději
 * s uplatněním této slevy" a § 7c odst. 2 dovoluje slevu uplatnit „jen do dne
 * splatnosti pojistného za kalendářní měsíc, za který sleva na pojistném
 * náleží". Pojistné je podle § 9 odst. 1 splatné do dvacátého dne následujícího
 * kalendářního měsíce. Popis datové věty OZUSPOJ23 tutéž hranici formuluje
 * technicky: datum doručení „musí být menší nebo rovno 20. kalendářnímu dnu
 * měsíce následujícího po datu záměru od (v případě, že tento den připadne na
 * svátek, sobotu, neděli, posouvá se na nejbližší následující pracovní den)".
 *
 * Lhůta se počítá od `intentFrom`, tedy od PRVNÍHO měsíce, za který se sleva
 * uplatní. Pro pozdější měsíce už žádná další lhůta neběží — jednou oznámený
 * a nezrušený záměr platí dál (§ 23f odst. 3 písm. b) eviduje jen den, od
 * kterého záměr platí).
 */
final class OzuspojDeadlinePolicy
{
    private const RULESET_ID = 'cz-ozuspoj-intent-notification-2023-02.v1';
    private const SOURCES = [
        'law' => '§ 7a odst. 5, § 7c odst. 2 a § 9 odst. 1 zákona č. 589/1992 Sb.',
        'cssz_document' => 'Datová věta OZUSPOJ23, položky zamer/datumOd a zamer/datumDo',
    ];

    /**
     * `intentFrom` je den, od kterého bude zaměstnavatel slevu uplatňovat
     * (`zamer/datumOd`). `registrationSubmittedOn` je den podání přihlášky
     * zaměstnance do registru ČSSZ; je-li známý, posouvá dolní hranici, protože
     * dřív než v ten den záměr oznámit nelze.
     */
    public function forIntentStart(
        string $intentFrom,
        ?string $registrationSubmittedOn = null,
    ): OzuspojNotificationWindow {
        $from = $this->exactDate(
            $intentFrom,
            'Den, od kterého se sleva uplatní, musí být datum ve tvaru RRRR-MM-DD.',
        );
        $earliest = $this->oneMonthBefore($from);
        if ($registrationSubmittedOn !== null) {
            $registered = $this->exactDate(
                $registrationSubmittedOn,
                'Den podání přihlášky zaměstnance musí být datum ve tvaru RRRR-MM-DD.',
            );
            if ($registered > $earliest) {
                $earliest = $registered;
            }
        }
        // `first day of next month` a teprve pak +19 dnů, nikoli `+1 month`:
        // u 31. ledna by `+1 month` skočilo na 3. března a lhůta by vyšla
        // o měsíc pozdě.
        $due = CzechWorkingDays::shiftToWorkingDay(
            $from->modify('first day of next month')->modify('+19 days'),
        );
        if ($due < $earliest) {
            // Nemůže nastat u dat, která projdou kontrolou výš, ale kdyby se
            // pravidla někdy rozešla, nesmí vzniknout okno, které končí dřív,
            // než začíná — registr povinností takový interval odmítne až
            // hluboko ve validaci a chyba by se přisoudila jinam.
            throw new OzuspojException(
                'ozuspoj_notification_window_invalid',
                'Okno pro oznámení záměru vyšlo prázdné; zkontrolujte den, od kterého se sleva uplatní.',
            );
        }

        return new OzuspojNotificationWindow(
            $earliest->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::RULESET_ID,
            $this->rulesetHash(),
        );
    }

    /**
     * Lhůta oznámení SKONČENÍ uplatňování slevy — § 23e odst. 2: „ve lhůtě
     * 8 dnů po skončení kalendářního měsíce, ve kterém slevu na pojistném za
     * tohoto zaměstnance uplatnil naposledy". Věta poslední téhož odstavce
     * z povinnosti vyjímá skončení zaměstnání: „Při skončení zaměstnání
     * zaměstnance se skončení uplatňování slevy na pojistném za tohoto
     * zaměstnance neoznamuje." Tuhle výjimku vyhodnocuje volající — politika
     * jen počítá lhůtu, když se oznamovat má.
     */
    public function forIntentEnd(string $intentTo): OzuspojNotificationWindow
    {
        $to = $this->exactDate(
            $intentTo,
            'Den skončení uplatňování slevy musí být datum ve tvaru RRRR-MM-DD.',
        );
        $monthEnd = $to->modify('last day of this month');

        return new OzuspojNotificationWindow(
            $to->format('Y-m-d'),
            $monthEnd->modify('+8 days')->format('Y-m-d'),
            'calendar_days',
            self::RULESET_ID,
            $this->rulesetHash(),
        );
    }

    /**
     * „Nejdříve 1 měsíc přede dnem" u 31. března znamená 28. února, ne
     * 3. března. Prosté `-1 month` v PHP přeteče do dalšího měsíce a okno by se
     * o tři dny zúžilo — oznámení podané zcela po zákonu by aplikace odmítla
     * jako předčasné.
     */
    private function oneMonthBefore(
        \DateTimeImmutable $date,
    ): \DateTimeImmutable {
        $shifted = $date->modify('-1 month');
        if ($shifted->format('d') === $date->format('d')) {
            return $shifted;
        }

        return $date->modify('first day of last month')
            ->modify('last day of this month');
    }

    private function rulesetHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-ozuspoj-deadline-policy.v1',
            'ruleset_id' => self::RULESET_ID,
            'earliest_months_before_intent_start' => 1,
            'due_day_of_following_month' => 20,
            'due_shift' => 'next_czech_working_day',
            'end_notification_calendar_days_after_month' => 8,
            'sources' => self::SOURCES,
        ]));
    }

    private function exactDate(string $value, string $message): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new OzuspojException(
                'ozuspoj_date_invalid',
                $message,
            );
        }

        return $date;
    }
}
