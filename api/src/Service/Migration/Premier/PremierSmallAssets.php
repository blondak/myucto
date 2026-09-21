<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Drobný a ostatní majetek v záloze PREMIER - co je evidence majetku a co jen náklad.
 *
 * Karty majetku (`MAJETEK`, `MAJ_H`) nesou řadu dokladu, jejíž typ (`DOKL_PU.TOK`) říká,
 * o jakou evidenci jde: 41 hmotný a 42 nehmotný dlouhodobý majetek, 43 a 44 drobný
 * neodpisovaný hmotný a nehmotný, 45 finanční majetek, 46 leasing, 47 ostatní majetek,
 * 48 ostatní evidence, 49 rezervy. Operativní evidence ostatního majetku je `MAJ_OST`.
 *
 * Když PREMIER evidenci drobného majetku nevede (karty řad 43/44 ani `MAJ_OST` nejsou),
 * odvodí se z položek přijatých dokladů zaúčtovaných na účet, který osnova PREMIER
 * pojmenovává jako drobný majetek (`501200 Spotřeba materiálu - dr. majetek`). Kartu
 * dostane položka s cenou za kus aspoň {@see THRESHOLD} Kč, levnější zůstane materiálem.
 */
final class PremierSmallAssets
{
    /** Hranice ceny za kus (Kč bez DPH), od které se položka eviduje jako drobný majetek. */
    public const THRESHOLD = 1000.0;

    public const REGISTER_LONG_TERM = 'long_term';
    public const REGISTER_SMALL = 'small';
    public const REGISTER_OTHER = 'other';

    /** Typ řady → druh evidence a druh karty. */
    private const TOK = [
        41 => [self::REGISTER_LONG_TERM, 'tangible'], 42 => [self::REGISTER_LONG_TERM, 'intangible'],
        43 => [self::REGISTER_SMALL, 'tangible'], 44 => [self::REGISTER_SMALL, 'intangible'],
        45 => [self::REGISTER_OTHER, null], 46 => [self::REGISTER_OTHER, null], 47 => [self::REGISTER_OTHER, null],
        48 => [self::REGISTER_OTHER, null], 49 => [self::REGISTER_OTHER, null],
    ];

    /** Výchozí zkratky řad PREMIER, když řada v číselníku chybí. */
    private const DEFAULT_SERIES = ['HM' => 41, 'NM' => 42, 'DH' => 43, 'DN' => 44, 'FM' => 45, 'LEA' => 46, 'OSM' => 47, 'OST' => 48, 'REZ' => 49];

    /**
     * @param array<string,int> $seriesTok řada → typ řady
     * @param array<string,string> $accounts účet PREMIER drobného majetku → `tangible` / `intangible`
     */
    private function __construct(
        private readonly array $seriesTok,
        private readonly array $accounts,
        public readonly bool $hasEvidence,
    ) {}

    public static function fromBackup(PremierBackup $backup): self
    {
        $seriesTok = self::DEFAULT_SERIES;
        foreach ($backup->rows('DOKL_PU') as $r) {
            $tok = (int) ($r['TOK'] ?? 0);
            $code = strtoupper(trim((string) ($r['DOKLAD'] ?? '')));
            if ($code !== '' && isset(self::TOK[$tok])) {
                $seriesTok[$code] = $tok;
            }
        }
        $accounts = [];
        foreach ($backup->rows('OSNOVA') as $r) {
            $code = trim((string) ($r['UCET'] ?? '')) . trim((string) ($r['ANALYT'] ?? ''));
            $kind = self::accountKind($code, (string) ($r['TEXT'] ?? ''));
            if ($kind !== null) {
                $accounts[$code] = $kind;
            }
        }
        $self = new self($seriesTok, $accounts, false);
        $evidence = $backup->hasRows('MAJ_OST');
        foreach (['MAJETEK', 'MAJ_H'] as $table) {
            foreach ($backup->rows($table) as $card) {
                $evidence = $evidence || $self->register((string) ($card['DOKLAD'] ?? ''))[0] === self::REGISTER_SMALL;
            }
        }
        return new self($seriesTok, $accounts, $evidence);
    }

    /**
     * @param array<string,int> $seriesTok
     * @param array<string,string> $accounts
     */
    public static function fromParts(array $seriesTok, array $accounts, bool $hasEvidence): self
    {
        return new self($seriesTok + self::DEFAULT_SERIES, $accounts, $hasEvidence);
    }

    /**
     * Druh evidence karty podle řady. Karta bez řady nebo s neznámou řadou je dlouhodobý
     * hmotný majetek (výchozí agenda PREMIER).
     *
     * @return array{0:string,1:?string} druh evidence, druh karty (`tangible` / `intangible`)
     */
    public function register(string $series): array
    {
        $tok = $this->seriesTok[strtoupper(trim($series))] ?? 41;
        return self::TOK[$tok] ?? [self::REGISTER_LONG_TERM, 'tangible'];
    }

    /**
     * Druh nákladu položky přijatého dokladu (`purchase_invoice_items.expense_kind`), když
     * PREMIER evidenci drobného majetku nevede. `null` = účet drobného majetku to není.
     */
    public function expenseKind(string $account, float $unitPriceCzk): ?string
    {
        if ($this->hasEvidence || !isset($this->accounts[$account])) {
            return null;
        }
        if (abs($unitPriceCzk) < self::THRESHOLD) {
            return 'material';
        }
        return $this->accounts[$account] === 'intangible' ? 'small_intangible' : 'small_asset';
    }

    /** @return array<string,string> */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * Účet drobného majetku podle názvu v osnově: „drobný", „dr. majetek", DHM / DNM
     * - jen nákladové účty (třída 5).
     */
    public static function accountKind(string $code, string $name): ?string
    {
        if (!str_starts_with($code, '5')) {
            return null;
        }
        $name = mb_strtolower($name);
        if (preg_match('/drob|dr\.\s*(hm\.?|nehm\.?)?\s*maj|\bd[dn]?hm\b|\bd[dn]?nm\b/u', $name) !== 1) {
            return null;
        }
        return preg_match('/nehm|\bd[dn]?nm\b|software|licen/u', $name) === 1 ? 'intangible' : 'tangible';
    }
}
