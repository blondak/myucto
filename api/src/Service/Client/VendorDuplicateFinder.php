<?php

declare(strict_types=1);

namespace MyInvoice\Service\Client;

use MyInvoice\Support\CompanyIdNormalizer;

/**
 * FR 2 (vendor bugreport 2026-08-06) — přehled dodavatelů se stejným
 * IČO/DIČ v jiném zápisu (mezera, chybějící úvodní nula), založených jako dvě
 * samostatné karty. Čistá funkce (bez DB) nad už načtenými řádky klientů —
 * volá ji {@see \MyInvoice\Repository\ClientRepository::findDuplicateGroups()}
 * (report v přehledu dodavatelů) i create/update guard pro jednotlivou kartu.
 */
final class VendorDuplicateFinder
{
    /**
     * Seskupí klienty se shodným normalizovaným IČO nebo DIČ (v rámci jednoho
     * volání — typicky už scoped na jednoho dodavatele/tenanta).
     *
     * @param list<array{id:int, company_name:string, ic:?string, dic:?string}> $clients
     * @return list<array{key_type:'ic'|'dic', key_value:string, clients:list<array{id:int, company_name:string, ic:?string, dic:?string}>}>
     */
    public static function findGroups(array $clients): array
    {
        $byIc = [];
        $byDic = [];
        foreach ($clients as $client) {
            $icNorm = CompanyIdNormalizer::ic($client['ic'] ?? null);
            if ($icNorm !== null) {
                $byIc[$icNorm][] = $client;
            }
            $dicNorm = CompanyIdNormalizer::dic($client['dic'] ?? null);
            if ($dicNorm !== null) {
                $byDic[$dicNorm][] = $client;
            }
        }

        $groups = [];
        foreach ($byIc as $key => $members) {
            if (count($members) > 1) {
                $groups[] = ['key_type' => 'ic', 'key_value' => $key, 'clients' => $members];
            }
        }
        foreach ($byDic as $key => $members) {
            if (count($members) > 1) {
                $groups[] = ['key_type' => 'dic', 'key_value' => $key, 'clients' => $members];
            }
        }

        return $groups;
    }

    /**
     * Najde mezi `$clients` ty, jejichž normalizované IČO nebo DIČ odpovídá
     * `$ic`/`$dic` — pro guard při zakládání/editaci jedné konkrétní karty.
     * `$excludeClientId` vyřadí samotnou editovanou kartu ze srovnání.
     *
     * @param list<array{id:int, company_name:string, ic:?string, dic:?string}> $clients
     * @return list<array{id:int, company_name:string, match_field:'ic'|'dic'}>
     */
    public static function findMatches(array $clients, ?string $ic, ?string $dic, ?int $excludeClientId = null): array
    {
        $icNorm = CompanyIdNormalizer::ic($ic);
        $dicNorm = CompanyIdNormalizer::dic($dic);
        if ($icNorm === null && $dicNorm === null) {
            return [];
        }

        $out = [];
        foreach ($clients as $client) {
            if ($excludeClientId !== null && (int) $client['id'] === $excludeClientId) {
                continue;
            }
            $rowIcNorm = CompanyIdNormalizer::ic($client['ic'] ?? null);
            $rowDicNorm = CompanyIdNormalizer::dic($client['dic'] ?? null);
            $matchField = null;
            if ($icNorm !== null && $rowIcNorm === $icNorm) {
                $matchField = 'ic';
            } elseif ($dicNorm !== null && $rowDicNorm === $dicNorm) {
                $matchField = 'dic';
            }
            if ($matchField !== null) {
                $out[] = ['id' => (int) $client['id'], 'company_name' => (string) $client['company_name'], 'match_field' => $matchField];
            }
        }

        return $out;
    }
}
