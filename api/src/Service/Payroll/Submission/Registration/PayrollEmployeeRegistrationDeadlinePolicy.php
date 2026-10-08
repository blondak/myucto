<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Lhůty registrační povinnosti u ZAMĚSTNANCE (PREZEC/REGZEC).
 *
 * Proč to není `EmployerRegistrationDeadlinePolicy`: ta počítá lhůtu
 * ZAMĚSTNAVATELE — přihlášku do evidence zaměstnavatelů podle § 17, tedy
 * dva pracovní dny před nástupem prvního zaměstnance a nejdříve 15 dnů
 * předem. Pravidlo „dva pracovní dny" se v žádném dokumentu k PREZEC ani
 * REGZEC nevyskytuje a přenést ho na zaměstnance je doložená záměna
 * (viz varování v `private/Mzdy/15-HANDOFF-2026-08-06.md`). U zaměstnance
 * platí § 19 odst. 1: přihlásit PŘED zahájením práce, nejdříve osm dnů předem.
 *
 * Obě lhůty jsou proto samostatné třídy se samostatným rulesetem a hashem.
 * Nic dalšího se tu neodvozuje: opravy, storna a náhradní lhůty zůstávají
 * fail-closed, dokud nebudou svázané s účinným pravidlem.
 */
final class PayrollEmployeeRegistrationDeadlinePolicy
{
    /**
     * Registrační povinnost u zaměstnance je účinná od 1. 7. 2026. Pro dřívější
     * nástup se lhůta neodvozuje vůbec — raději žádná než vymyšlená.
     */
    public const SUPPORTED_FROM = '2026-07-01';

    public const REGISTRATION_RULESET_ID =
        'cz-employee-registration-2026-07.v1';
    private const NO_SHOW_RULESET_ID =
        'cz-employee-registration-no-show-2026-07.v1';
    private const FOLLOW_UP_RULESET_ID =
        'cz-regzec-follow-up-2026-04.v1';
    private const AFTER_PRE_REGISTRATION_RULESET_ID =
        'cz-regzec-after-prezec-2026-07.v1';
    private const UNKNOWN_START_RULESET_ID =
        'cz-employee-registration-unknown-start-2026-07.v1';
    private const UNKNOWN_START_DAYS = 8;

    /**
     * Storno A8 má vlastní rulesety. Dřív spadalo pod
     * {@see self::FOLLOW_UP_RULESET_ID} s jednotnými osmi dny a zdrojem
     * „§ 17 odst. 5" — to je ale nenastoupení PRVNÍHO zaměstnance do evidence
     * zaměstnavatelů. Osm dnů platí jen pro nenastoupení zaměstnance
     * (§ 19 odst. 4), jiné storno lhůtu nemá.
     */
    private const CANCELLATION_NO_SHOW_RULESET_ID =
        'cz-regzec-cancellation-no-show-2026-07.v1';
    private const CANCELLATION_OTHER_RULESET_ID =
        'cz-regzec-cancellation-other-2026-07.v1';

    /** Druh pracovního oprávnění (ID 10106): povolení k zaměstnání, zaměstnanecká a modrá karta. */
    public const FOREIGN_PERMIT_TYPES = ['1', '2', '4'];
    private const FOREIGN_PERMIT_RULESET_ID =
        'cz-regzec-foreign-permit-labour-office-2026-07.v1';
    private const FOREIGN_PERMIT_NO_SHOW_DAYS = 10;
    private const FOREIGN_CARD_NO_SHOW_DAYS = 45;
    private const FOREIGN_PERMIT_EARLY_END_DAYS = 10;

    /**
     * Doplnění plné registrace po předregistraci: osm dnů PO nástupu.
     *
     * Smysl PREZEC je přihlásit člověka, u kterého ještě nemáte všechny údaje.
     * Kdyby plná registrace musela odejít v den nástupu, nebylo by na to kdy
     * ty údaje sehnat a předregistrace by ztratila smysl.
     */
    private const AFTER_PRE_REGISTRATION_DAYS = 8;
    private const FOLLOW_UP_SUPPORTED_FROM = '2026-04-01';

    /**
     * Osm KALENDÁŘNÍCH dnů, ne pracovních. Kdyby se počítaly pracovní, okno by
     * se u svátků roztáhlo přes zákonnou hranici a podání by prošlo dřív, než
     * ho zákon připouští. Stejnou hodnotu drží `PayrollRegistrationXmlValidator`
     * pro okno PREZEC P1, takže se obě vrstvy nesmí rozejít.
     */
    private const EARLIEST_DAYS_BEFORE_START = 8;

    /**
     * Nenastoupení zaměstnance: nejpozději do osmi dnů od předpokládaného dne
     * nástupu (§ 19 odst. 4 zákona č. 323/2025 Sb.). Nezaměňovat s § 17
     * odst. 5, který stejnou lhůtu ukládá ZAMĚSTNAVATELI přihlášenému do
     * evidence zaměstnavatelů, když nenastoupil nikdo — to počítá
     * `EmployerRegistrationDeadlinePolicy::noShowNotificationDueOn`.
     */
    private const NO_SHOW_NOTIFICATION_DAYS = 8;

    /**
     * Zákonný podklad jednotlivých lhůt, ověřený proti konsolidovanému znění
     * zákona č. 323/2025 Sb. účinnému od 1. 7. 2026:
     *
     *  - přihlášení: § 19 odst. 1 písm. a) — před nástupem, nejdříve 8 dnů předem,
     *  - doplnění údajů po částečném přihlášení: § 19 odst. 2 věta poslední —
     *    do 8 dnů od nástupu,
     *  - nenastoupení: § 19 odst. 4 — do 8 dnů od předpokládaného nástupu,
     *  - změna evidovaného údaje (A3): § 19 odst. 5 — do 8 dnů ode dne,
     *    kdy se zaměstnavatel o změně dozvěděl,
     *  - odhlášení při skončení (A2): § 19 odst. 6 písm. a) — do 8 dnů ode
     *    dne skončení zaměstnání.
     *
     * Do otisku rulesetu tahle mapa NEVSTUPUJE. Otisk je součástí otisku
     * požadavku na evidovanou povinnost, takže oprava citace by u už
     * založených povinností vyrobila nový požadavek se stejným klíčem.
     *
     * @var array<string,string>
     */
    public const LEGAL_BASIS = [
        'registration' => '§ 19 odst. 1 písm. a) zákona č. 323/2025 Sb.',
        'after_pre_registration' => '§ 19 odst. 2 zákona č. 323/2025 Sb.',
        'no_show' => '§ 19 odst. 4 zákona č. 323/2025 Sb.',
        'change' => '§ 19 odst. 5 zákona č. 323/2025 Sb.',
        'termination' => '§ 19 odst. 6 písm. a) zákona č. 323/2025 Sb.',
    ];

    /**
     * Zdroje ZMRAŽENÉ v otisku rulesetu verze v1 — beze změny, protože otisk
     * nese evidovaná povinnost (viz {@see self::LEGAL_BASIS}). Citace
     * `no_show_law` a poznámka „Paragraf k doložení" jsou překonané:
     * nenastoupení upravuje § 19 odst. 4 a navazující odhlášení § 19 odst. 6
     * písm. a). Platné citace drží {@see self::LEGAL_BASIS}; přepsat je sem
     * lze jen spolu s novým identifikátorem rulesetu.
     */
    private const SOURCES = [
        'law' => '323/2025 Sb. § 19 odst. 1',
        'no_show_law' => '323/2025 Sb. § 17 odst. 5',
        'cssz_document' =>
            'Metodika PREZEC 1.4 — částečné přihlášení před nástupem',
        'follow_up_document' =>
            'Leták ČSSZ „Předregistrace a registrace zaměstnance" '
            . '(private/Mzdy/podklady/JMHZ_predregistrace_a_registrace.pdf); '
            . 'lhůtu potvrdila účetní vedoucí agendu 2. 9. 2026. Paragraf '
            . 'k doložení.',
    ];

    /**
     * Lhůta pro přihlášení pracovního vztahu (PREZEC P1 i REGZEC A1).
     *
     * `dueOn` je DEN NÁSTUPU, ne den před ním: zákon váže povinnost na okamžik
     * zahájení práce, takže podání v den nástupu před nástupem do práce je
     * včas. Posouvat lhůtu o den dopředu „pro jistotu" by z včasného podání
     * udělalo opožděné a hlásilo by se zpoždění, které nenastalo.
     */
    public function forEmploymentStart(
        string $startOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $transitional = $this->transitional($startOn);
        if ($transitional !== null) {
            return $transitional;
        }
        $start = $this->supportedDate($startOn);
        $earliest = $start->modify(
            '-' . self::EARLIEST_DAYS_BEFORE_START . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $start->format('Y-m-d'),
            'calendar_days',
            self::REGISTRATION_RULESET_ID,
            $this->rulesetHash(self::REGISTRATION_RULESET_ID, [
                'earliest_days_before_start' =>
                    self::EARLIEST_DAYS_BEFORE_START,
                'due_on' => 'employment_start_date',
            ]),
        );
    }

    /**
     * Lhůta pro přihlášení zaměstnance, jehož nástup nebyl předem znám.
     *
     * § 19 odst. 1 písm. b) zákona č. 323/2025 Sb. (znění od 1. 7. 2026): nelze-li
     * použít lhůtu "před nástupem", přihlásí se do osmi dnů ode dne, kdy
     * zaměstnavateli vznikla povinnost poskytovat zaměstnanci plnění, nebo kdy
     * plnění poprvé poskytl. Takový den je den skutečného nástupu, od něj běží
     * osm kalendářních dnů a okno se otevírá tímtéž dnem.
     */
    public function forUnknownStart(
        string $startOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $transitional = $this->transitional($startOn);
        if ($transitional !== null) {
            return $transitional;
        }
        $start = $this->supportedDate($startOn);
        $due = $start->modify('+' . self::UNKNOWN_START_DAYS . ' days');

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $start->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::UNKNOWN_START_RULESET_ID,
            $this->rulesetHash(self::UNKNOWN_START_RULESET_ID, [
                'due_calendar_days_after_start' => self::UNKNOWN_START_DAYS,
                'due_on' => 'first_performance_date_plus_days',
                'legal_basis' => '§ 19 odst. 1 písm. b) zákona č. 323/2025 Sb.',
            ]),
        );
    }

    /**
     * Lhůta pro plnou registraci (REGZEC A1) po částečném přihlášení (PREZEC P1).
     *
     * PROČ SAMOSTATNĚ: dřív tahle interakce spadla do
     * {@see forEmploymentStart()}, takže termínem byl DEN NÁSTUPU. Aplikace
     * pak hlásila zpoždění, které nenastalo, a tlačila účetní podat dřív, než
     * musí — přesně u případu, kde předregistrace existuje proto, že údaje
     * ještě nejsou. Podle letáku ČSSZ (viz `cssz_document` v SOURCES) je to
     * nástup plus osm dnů.
     *
     * Okno se neotevírá dnem nástupu: doplnit údaje jde i dřív, jakmile je
     * zaměstnavatel má, takže nejdřívější den zůstává stejný jako u přihlášky.
     */
    public function forFullRegistrationAfterPreRegistration(
        string $startOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $transitional = $this->transitional($startOn);
        if ($transitional !== null) {
            return $transitional;
        }
        $start = $this->supportedDate($startOn);
        $earliest = $start->modify(
            '-' . self::EARLIEST_DAYS_BEFORE_START . ' days',
        );
        $due = $start->modify(
            '+' . self::AFTER_PRE_REGISTRATION_DAYS . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::AFTER_PRE_REGISTRATION_RULESET_ID,
            $this->rulesetHash(self::AFTER_PRE_REGISTRATION_RULESET_ID, [
                'earliest_days_before_start' =>
                    self::EARLIEST_DAYS_BEFORE_START,
                'due_calendar_days_after_start' =>
                    self::AFTER_PRE_REGISTRATION_DAYS,
                'due_on' => 'employment_start_date_plus_days',
            ]),
        );
    }

    /**
     * Lhůta pro oznámení, že zaměstnanec nenastoupil (PREZEC P2). Okno začíná
     * dnem předpokládaného nástupu — dřív se o nenastoupení nedá rozhodnout.
     */
    public function forNoShow(
        string $expectedStartOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $start = $this->supportedDate($expectedStartOn);
        $due = $start->modify(
            '+' . self::NO_SHOW_NOTIFICATION_DAYS . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $start->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::NO_SHOW_RULESET_ID,
            $this->rulesetHash(self::NO_SHOW_RULESET_ID, [
                'notification_calendar_days' =>
                    self::NO_SHOW_NOTIFICATION_DAYS,
                'window_opens_on' => 'expected_employment_start_date',
            ]),
        );
    }

    /**
     * Navazující oznámení REGZEC A2 až A8: osm kalendářních dnů od rozhodné
     * skutečnosti. Pro odhlášení při skončení (A2) je to § 19 odst. 6
     * písm. a), pro změnu údaje (A3) § 19 odst. 5, pro nenastoupení (A8)
     * § 19 odst. 4 — viz {@see self::LEGAL_BASIS}.
     */
    public function forFollowUp(
        int $actionCode,
        string $effectiveOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        if ($actionCode < 2 || $actionCode > 8) {
            // Kontrakt volajícího, ne vstup účetní: do formuláře se tenhle
            // kód nedostane, protože druh oznámení se vybírá z nabídky.
            throw new \InvalidArgumentException(
                'Lhůtu pro navazující oznámení umí spočítat jen oznámení '
                    . 'REGZEC A2 až A8, ne přihlášení ani částečné přihlášení.',
            );
        }
        $effective = $this->date($effectiveOn);
        $due = $effective->modify('+8 days');

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $effectiveOn,
            $due->format('Y-m-d'),
            'calendar_days',
            self::FOLLOW_UP_RULESET_ID,
            $this->rulesetHash(self::FOLLOW_UP_RULESET_ID, [
                'action_code' => $actionCode,
                'notification_calendar_days' => 8,
                'window_opens_on' => 'registration_event_effective_on',
            ]),
        );
    }

    /**
     * Storno přihlášení (REGZEC A8).
     *
     * - Zaměstnanec NENASTOUPIL: oznámit bez zbytečného odkladu, nejpozději
     *   do osmi dnů od předpokládaného dne nástupu (§ 19 odst. 4 zákona
     *   č. 323/2025 Sb.; zásady REGZEC 18-06-2026 — oznámení akcí 8).
     * - Jiný důvod (chybný variabilní symbol, nepovolená oprava druhu
     *   činnosti, soudní zneplatnění): storno „nemá časové omezení" (zásady
     *   REGZEC, kód akce 8). `dueOn` je jen informační milník — 20. den
     *   následujícího měsíce, do kdy jde spolu s ním stornovat i měsíční
     *   hlášení bez referentského zpracování.
     */
    public function forCancellation(
        bool $notStarted,
        string $triggerOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $trigger = $this->date($triggerOn);
        if ($notStarted) {
            $due = $trigger->modify(
                '+' . self::NO_SHOW_NOTIFICATION_DAYS . ' days',
            );

            return new PayrollEmployeeRegistrationDeadlineWindow(
                $triggerOn,
                $due->format('Y-m-d'),
                'calendar_days',
                self::CANCELLATION_NO_SHOW_RULESET_ID,
                $this->cancellationHash(self::CANCELLATION_NO_SHOW_RULESET_ID, [
                    'notification_calendar_days' => self::NO_SHOW_NOTIFICATION_DAYS,
                    'window_opens_on' => 'expected_employment_start_date',
                ], self::LEGAL_BASIS['no_show']),
            );
        }
        $milestone = $trigger->modify('first day of next month')
            ->modify('+19 days');

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $triggerOn,
            $milestone->format('Y-m-d'),
            'calendar_days',
            self::CANCELLATION_OTHER_RULESET_ID,
            $this->cancellationHash(self::CANCELLATION_OTHER_RULESET_ID, [
                'statutory_deadline' => false,
                'milestone' => 'twentieth_day_of_following_month',
            ], 'Všeobecné zásady REGZEC (verze 18-06-2026), kód akce 8'),
            true,
            'Storno z jiného důvodu než nenastoupení nemá zákonnou lhůtu. '
                . 'Uvedený den je jen milník: do 20. dne následujícího měsíce '
                . 'jde spolu se stornem opravit i měsíční hlášení bez '
                . 'referentského zpracování.',
            false,
        );
    }

    /**
     * Cizinec s povolením k zaměstnání (1), zaměstnaneckou (2) nebo modrou
     * kartou (4): podle § 88 odst. 1 zákona o zaměstnanosti (citováno
     * v zásadách REGZEC 1.4.6, úvod) se úřadu práce, kterému informaci dnes
     * předává REGZEC, oznamuje
     *
     *  - nenastoupení (A8): u povolení k zaměstnání do 10 dnů ode dne, kdy
     *    měl nastoupit, u karty do 45 dnů ode dne splnění podmínek pro vydání,
     *  - předčasné ukončení před koncem platnosti oprávnění (A2): do 10 dnů
     *    ode dne skončení.
     *
     * Jedno podání plní obě povinnosti, platí tedy dřívější z lhůt; osm dnů
     * podle § 19 zákona č. 323/2025 Sb. se nikdy neprodlužuje. Den splnění
     * podmínek pro kartu aplikace nezná: nejpozději je to první den
     * platnosti karty (`permit_from`), takže termín nejvýš 45 dnů od něj je
     * horní mez a `notice` žádá ověření podle rozhodnutí.
     *
     * Bez oprávnění 1, 2, 4 (nebo u A2 bez předčasného ukončení) vrací okno
     * beze změny, aby se otisk lhůty u ostatních zaměstnanců nehnul.
     *
     * @param array<string,mixed>|null $permit `type_code`, `permit_from`, `permit_to`
     */
    public function withForeignPermit(
        PayrollEmployeeRegistrationDeadlineWindow $window,
        int $actionCode,
        bool $notStarted,
        ?array $permit,
        string $triggerOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $type = is_array($permit) ? ($permit['type_code'] ?? null) : null;
        if (!is_string($type) || !in_array($type, self::FOREIGN_PERMIT_TYPES, true)) {
            return $window;
        }
        $trigger = $this->date($triggerOn);
        if ($actionCode === 8 && $notStarted && $type === '1') {
            $candidate = $trigger->modify('+' . self::FOREIGN_PERMIT_NO_SHOW_DAYS . ' days');
            $rule = ['case' => 'no_show_employment_permit', 'days' => self::FOREIGN_PERMIT_NO_SHOW_DAYS];
            $notice = 'Úřadu práce se nenastoupení cizince s povolením k zaměstnání '
                . 'oznamuje do ' . self::FOREIGN_PERMIT_NO_SHOW_DAYS . ' dnů ode dne, kdy měl '
                . 'nastoupit (§ 88 odst. 1 zákona o zaměstnanosti). Storno REGZEC A8 '
                . 'plní obě povinnosti, platí dřívější lhůta.';
        } elseif ($actionCode === 8 && $notStarted) {
            $from = is_string($permit['permit_from'] ?? null) ? $permit['permit_from'] : null;
            $candidate = $from === null
                ? null
                : $this->date($from)->modify('+' . self::FOREIGN_CARD_NO_SHOW_DAYS . ' days');
            $rule = ['case' => 'no_show_card', 'days' => self::FOREIGN_CARD_NO_SHOW_DAYS];
            $notice = 'U zaměstnanecké nebo modré karty se nenastoupení oznamuje do '
                . self::FOREIGN_CARD_NO_SHOW_DAYS . ' dnů ode dne, kdy byly splněny '
                . 'podmínky pro vydání karty (§ 88 odst. 1 zákona o zaměstnanosti). '
                . 'Ten den aplikace nezná' . ($from === null
                    ? ' ani nemá začátek platnosti karty v profilu A1'
                    : ', termín počítá od začátku platnosti karty ' . $from)
                . '. Ověřte ho podle rozhodnutí; nastal-li dřív, je lhůta kratší.';
        } elseif ($actionCode === 2 && self::endsBeforePermitExpiry($permit, $triggerOn)) {
            $candidate = $trigger->modify('+' . self::FOREIGN_PERMIT_EARLY_END_DAYS . ' days');
            $rule = ['case' => 'early_termination', 'days' => self::FOREIGN_PERMIT_EARLY_END_DAYS];
            $notice = 'Předčasné ukončení zaměstnání cizince před koncem platnosti '
                . 'oprávnění se úřadu práce oznamuje do ' . self::FOREIGN_PERMIT_EARLY_END_DAYS
                . ' dnů ode dne skončení (§ 88 odst. 1 zákona o zaměstnanosti). '
                . 'Odhláška REGZEC A2 s důvodem předčasného ukončení plní obě '
                . 'povinnosti, platí dřívější lhůta.';
        } else {
            return $window;
        }
        $dueOn = $candidate !== null && $candidate->format('Y-m-d') < $window->dueOn
            ? $candidate->format('Y-m-d')
            : $window->dueOn;

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $window->earliestRegistrationOn,
            $dueOn,
            $window->calendarBasis,
            self::FOREIGN_PERMIT_RULESET_ID,
            hash('sha256', CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-employee-registration-deadline-policy.v1',
                'ruleset_id' => self::FOREIGN_PERMIT_RULESET_ID,
                'base_ruleset_hash' => $window->rulesetHash,
                'action_code' => $actionCode,
                'permit_type_code' => $type,
                'rule' => $rule,
                'sources' => [
                    'law' => '435/2004 Sb. § 88 odst. 1 (citace: zásady REGZEC 1.4.6, úvod)',
                ],
            ])),
            $window->derived,
            trim(($window->notice === null ? '' : $window->notice . ' ') . $notice),
            $window->statutory,
        );
    }

    /**
     * Končí cizinec s oprávněním 1, 2, 4 dřív, než oprávnění vyprší? Stejná
     * podmínka rozhoduje o důvodu předčasného ukončení v A2 (EDV 1.4.0.6,
     * ID 10534) i o lhůtě pro úřad práce.
     *
     * @param array<string,mixed>|null $permit
     */
    public static function endsBeforePermitExpiry(?array $permit, string $endOn): bool
    {
        $permitTo = is_array($permit) ? ($permit['permit_to'] ?? null) : null;

        return is_array($permit)
            && in_array($permit['type_code'] ?? null, self::FOREIGN_PERMIT_TYPES, true)
            && is_string($permitTo)
            && $endOn < $permitTo;
    }

    /** @param array<string,mixed> $rule */
    private function cancellationHash(string $rulesetId, array $rule, string $source): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' =>
                'payroll-employee-registration-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'effective_from' => self::SUPPORTED_FROM,
            'calendar_basis' => 'calendar_days',
            'rule' => $rule,
            'sources' => ['law' => $source],
        ]));
    }

    /**
     * Přihláška za nástup PŘED 1. 7. 2026.
     *
     * Podle „Pravidel pro REGZEC" (ČSSZ, 30. 1. 2026) se událost, která
     * nastala do 31. 3. 2026 a nebyla do té doby ohlášena, od 1. 4. 2026
     * hlásí už jen přes REGZEC — týká se to i nástupů před 1. 1. 2026.
     * Podání proto blokovat nesmíme: cizí programy takové přihlášky podávaly
     * a ČSSZ je přijala. Lhůtu ale podle dnešních pravidel NEODVOZUJEME —
     * termínem je den nástupu, podání se tedy ukáže jako opožděné a důvod
     * nese `notice`. Ruleset zůstává přihláškový, protože podle něj se
     * v doručence poznává přijatá registrace (OIČ a ID PPV).
     */
    private function transitional(
        string $startOn,
    ): ?PayrollEmployeeRegistrationDeadlineWindow {
        $start = $this->date($startOn);
        if ($startOn >= self::SUPPORTED_FROM) {
            return null;
        }
        $earliest = $start->modify(
            '-' . self::EARLIEST_DAYS_BEFORE_START . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $start->format('Y-m-d'),
            'calendar_days',
            self::REGISTRATION_RULESET_ID,
            $this->rulesetHash(self::REGISTRATION_RULESET_ID, [
                'earliest_days_before_start' =>
                    self::EARLIEST_DAYS_BEFORE_START,
                'due_on' => 'not_derived_before_supported_window',
                'transitional_source' =>
                    'Pravidla pro REGZEC (ČSSZ, 30. 1. 2026) — události do '
                    . '31. 3. 2026 neohlášené do té doby se od 1. 4. 2026 '
                    . 'hlásí jen přes REGZEC',
            ]),
            false,
            'Nástup je dřívější než 1. 7. 2026, kdy začala platit dnešní '
                . 'registrační povinnost. Přihlášku REGZEC podat jde a je '
                . 'potřeba ji podat bez zbytečného odkladu, lhůtu ale '
                . 'aplikace podle tehdejších pravidel neodvozuje — podání '
                . 'je vedené jako po lhůtě.',
        );
    }

    private function supportedDate(string $value): \DateTimeImmutable
    {
        $date = $this->date($value);
        if ($value < self::SUPPORTED_FROM) {
            throw new PayrollRegistrationXmlException(
                'registration_deadline_before_supported_window',
                'Lhůtu pro přihlášení zaměstnance na ČSSZ appka počítá až '
                    . 'od 1. 7. 2026, kdy povinnost začala platit. Tenhle nástup '
                    . 'je dřívější, takže lhůtu určete podle tehdejších '
                    . 'pravidel.',
            );
        }

        return $date;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_deadline_start_date_invalid',
                'Datum registrační události chybí nebo není platné datum. '
                    . 'Zadejte ho ve tvaru RRRR-MM-DD, například 2026-08-05.',
            );
        }
        return $date;
    }

    /** @param array<string,mixed> $rule */
    private function rulesetHash(string $rulesetId, array $rule): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' =>
                'payroll-employee-registration-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'effective_from' => $rulesetId === self::FOLLOW_UP_RULESET_ID
                ? self::FOLLOW_UP_SUPPORTED_FROM
                : self::SUPPORTED_FROM,
            'calendar_basis' => 'calendar_days',
            'rule' => $rule,
            'sources' => self::SOURCES,
        ]));
    }
}
