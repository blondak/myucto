<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentLifecycle
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        /*
         * `planned → active` je zkratka pro nástup, který se prostě stal.
         *
         * Předregistrace odpovídá akci 9 – Předpokládaný nástup a dává smysl
         * u nástupu v BUDOUCNU. Jako povinná mezizastávka pro nástup starý rok
         * a půl znamenala, že vztah zůstal „plánovaný", nedostal skutečné datum
         * nástupu, a tím vypadl i z výplatní listiny — aniž by kdokoli řekl proč.
         */
        'planned' => ['preregistered', 'active', 'no_show'],
        'preregistered' => ['active', 'no_show'],
        'active' => ['suspended', 'ended'],
        'suspended' => ['active', 'ended'],
        'ended' => ['archived'],
        'no_show' => ['archived'],
        // Archiv není slepá ulička. Archivace je úklid, ne rozhodnutí o osudu
        // vztahu — omylem archivovaný vztah šel dřív jen smazat, a to u vztahu
        // s navázanými mzdami nejde vůbec. Vrací se do stavu, ze kterého se
        // archivovalo; „obnovit do aktivního" tu schválně NENÍ, protože takový
        // vztah má vyplněné datum konce a oživit ho znamená založit nový.
        'archived' => ['ended', 'no_show'],
    ];

    /** @return list<string> */
    public function allowedTargets(string $status): array
    {
        return self::TRANSITIONS[$status]
            ?? throw new \InvalidArgumentException('Neznámý stav pracovního vztahu.');
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, $this->allowedTargets($from), true)) {
            throw new \DomainException("Přechod pracovního vztahu {$from} → {$to} není povolen.");
        }
    }
}
