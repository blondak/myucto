<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Lhůty podání zdravotní pojišťovně.
 *
 * Základní lhůta hromadného oznámení je osm DNŮ od vzniku skutečnosti, ne
 * osm pracovních dnů — § 10 zákona č. 48/1997 Sb. mluví o dnech. Dvě výjimky
 * (dohody a mateřská/rodičovská) posouvají lhůtu na 20. den následujícího
 * měsíce; obě jsou doložené publikacemi VZP, ne textem zákona, a katalog to
 * u nich říká stavem pramene.
 *
 * Přehled o platbě má lhůtu shodnou se splatností pojistného, tedy 20. den
 * následujícího kalendářního měsíce podle § 25 odst. 3 zákona č. 592/1992 Sb.
 *
 * Posun lhůty připadající na víkend nebo svátek se ZÁMĚRNĚ nemodeluje.
 * Podklady o něm mlčí a chyba na stranu dřívějšího podání je bezpečná —
 * posunout lhůtu dopředu bez opory by naopak mohla znamenat penále.
 */
final class HealthNotificationDeadlinePolicy
{
    public const RULESET_ID = 'cz-health-insurance-notification-deadlines.v1';

    private const BASIS_CALENDAR_DAYS = 'calendar_days';

    private const NOTIFICATION_DAYS = 8;
    private const MONTHLY_DUE_DAY = 20;

    private const SOURCE_NOTIFICATION = '§ 10 zákona č. 48/1997 Sb.';
    private const SOURCE_PAYMENT_OVERVIEW =
        '§ 25 odst. 3 zákona č. 592/1992 Sb.';
    private const SOURCE_AGREEMENT_EXCEPTION =
        'metodika VZP k oznamovací povinnosti u dohod (DPP a DPČ)';
    private const SOURCE_PARENTAL_EXCEPTION =
        'metodika VZP k souhrnnému oznámení zahájení a ukončení mateřské '
        . 'a rodičovské dovolené';

    private const STATUTE_VERIFIED = 'statute_verified';
    private const EXTERNAL_UNVERIFIED = 'external_unverified';

    /**
     * Druhy vztahu, u kterých se místo osmi dnů uplatní 20. den následujícího
     * měsíce. Vazba na doménové názvy vztahů, ne na volný text.
     */
    private const AGREEMENT_RELATION_TYPES = ['dpp', 'dpc'];

    /** Povinnosti, které se podle metodiky VZP oznamují souhrnně měsíčně. */
    private const MONTHLY_DUTY_KINDS = [
        HealthNotificationDutyKind::MaternityLeaveStart,
        HealthNotificationDutyKind::ParentalLeaveStart,
        HealthNotificationDutyKind::MaternityOrParentalLeaveEnd,
    ];

    /** Lhůta hromadného oznámení pro jednu oznamovanou skutečnost. */
    public function forNotification(
        HealthNotificationDutyKind $kind,
        string $occurredOn,
        ?string $relationType = null,
    ): HealthNotificationDeadlineWindow {
        $occurred = $this->date($occurredOn);
        if (in_array($kind, self::MONTHLY_DUTY_KINDS, true)) {
            return $this->window(
                $occurredOn,
                $this->twentiethOfNextMonth($occurred),
                self::SOURCE_PARENTAL_EXCEPTION,
                self::EXTERNAL_UNVERIFIED,
            );
        }
        if ($relationType !== null
            && in_array($relationType, self::AGREEMENT_RELATION_TYPES, true)
        ) {
            return $this->window(
                $occurredOn,
                $this->twentiethOfNextMonth($occurred),
                self::SOURCE_AGREEMENT_EXCEPTION,
                self::EXTERNAL_UNVERIFIED,
            );
        }

        return $this->window(
            $occurredOn,
            $occurred
                ->add(new \DateInterval('P' . self::NOTIFICATION_DAYS . 'D'))
                ->format('Y-m-d'),
            self::SOURCE_NOTIFICATION,
            self::STATUTE_VERIFIED,
        );
    }

    /** Lhůta přehledu o platbě pojistného za mzdové období `YYYY-MM`. */
    public function forPaymentOverview(
        string $period,
    ): HealthNotificationDeadlineWindow {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období přehledu o platbě musí mít tvar RRRR-MM.',
            );
        }
        $periodEnd = $this->date($period . '-01')
            ->modify('last day of this month');
        if ($periodEnd === false) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období přehledu o platbě není platné.',
            );
        }

        return $this->window(
            $periodEnd->format('Y-m-d'),
            $this->twentiethOfNextMonth($periodEnd),
            self::SOURCE_PAYMENT_OVERVIEW,
            self::STATUTE_VERIFIED,
        );
    }

    public function rulesetHash(): string
    {
        return hash('sha256', self::RULESET_ID . '|'
            . self::NOTIFICATION_DAYS . '|' . self::MONTHLY_DUE_DAY);
    }

    private function window(
        string $earliest,
        string $dueOn,
        string $source,
        string $sourceStatus,
    ): HealthNotificationDeadlineWindow {
        return new HealthNotificationDeadlineWindow(
            earliestSubmissionOn: $earliest,
            dueOn: $dueOn,
            calendarBasis: self::BASIS_CALENDAR_DAYS,
            rulesetId: self::RULESET_ID,
            rulesetHash: $this->rulesetHash(),
            source: $source,
            sourceStatus: $sourceStatus,
        );
    }

    private function twentiethOfNextMonth(
        \DateTimeImmutable $reference,
    ): string {
        $nextMonth = $reference->modify('first day of next month');

        return $nextMonth
            ->setDate(
                (int) $nextMonth->format('Y'),
                (int) $nextMonth->format('n'),
                self::MONTHLY_DUE_DAY,
            )
            ->format('Y-m-d');
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HealthNotificationException(
                'zp_date_invalid',
                'Datum oznamované skutečnosti musí mít tvar RRRR-MM-DD.',
            );
        }

        return $date;
    }
}
