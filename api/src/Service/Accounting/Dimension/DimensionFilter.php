<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Filtr sestav na hodnotu dimenze včetně celé její větve (podřízené hodnoty).
 *
 * Filtruje ŘÁDKY deníku, ne celé zápisy — výsledovka po projektu má obsahovat jen
 * náklady a výnosy projektu, ne protistranu 321/311 téhož zápisu. Předvaha s filtrem
 * proto záměrně nemusí být vyvážená.
 *
 * U typu Středisko se počítají i řádky, které hodnotu dimenze nemají, ale nesou
 * textový kód navázaného střediska (`journal_entry_lines.cost_center` — mzdy, starší
 * ruční zápisy). Řádek s dimenzí téhož typu rozhoduje dimenzí, ne textem, aby se
 * jeden řádek nezapočítal pod dvě hodnoty.
 *
 * Poddotazy se vážou jen přes id řádku (primární klíč vazby); firmu omezuje vnější
 * dotaz. Podmínka na supplier_id navíc svádí optimalizátor k indexu přes celou firmu.
 */
final class DimensionFilter
{
    /**
     * @param list<int> $valueIds hodnota a její podřízené
     * @param list<string> $costCenterCodes kódy středisek navázaných na tyto hodnoty
     */
    public function __construct(
        public readonly int $typeId,
        public readonly int $valueId,
        public readonly array $valueIds,
        public readonly array $costCenterCodes = [],
    ) {}

    /**
     * SQL fragment (s vedoucím ' AND ') nad aliasem řádku deníku.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function sql(string $lineAlias): array
    {
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = " AND (EXISTS (SELECT 1 FROM journal_entry_line_dimensions dim_f
                        WHERE dim_f.line_id = {$lineAlias}.id
                          AND dim_f.dimension_type_id = ? AND dim_f.dimension_value_id IN ({$marks}))";
        $params = [$this->typeId, ...$this->valueIds];
        if ($this->costCenterCodes !== []) {
            [$ccSql, $ccParams] = $this->costCenterCondition($lineAlias);
            $sql .= " OR ({$ccSql})";
            array_push($params, ...$ccParams);
        }
        return [$sql . ')', $params];
    }

    /**
     * Samostatná podmínka (bez vedoucího AND) nad aliasem ZÁPISU deníku: zápis projde,
     * nese-li hodnotu (nebo podřízenou) aspoň jeden jeho řádek. Stejná sémantika řádku
     * jako {@see sql()}, jen povýšená na zápis, protože seznam deníku ukazuje celé zápisy.
     *
     * Hodnoty jdou přes nekorelovaný IN poddotaz: optimalizátor ho zmaterializuje
     * jednou z indexu idx_jeld_type_value a pro každý zápis firmy pak jen hledá v
     * dočasné tabulce, místo aby pro každý zápis procházel jeho řádky.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function entrySql(string $entryAlias): array
    {
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = "({$entryAlias}.id IN (SELECT jel_f.entry_id
                          FROM journal_entry_line_dimensions dim_f
                          JOIN journal_entry_lines jel_f ON jel_f.id = dim_f.line_id
                         WHERE dim_f.dimension_type_id = ? AND dim_f.dimension_value_id IN ({$marks}))";
        $params = [$this->typeId, ...$this->valueIds];
        if ($this->costCenterCodes !== []) {
            [$lineSql, $lineParams] = $this->costCenterCondition('jel_c');
            $sql .= " OR EXISTS (SELECT 1 FROM journal_entry_lines jel_c
                                  WHERE jel_c.entry_id = {$entryAlias}.id AND {$lineSql})";
            array_push($params, ...$lineParams);
        }
        return [$sql . ')', $params];
    }

    /**
     * Řádek bez dimenze tohoto typu, který nese textový kód navázaného střediska.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function costCenterCondition(string $lineAlias): array
    {
        $ccMarks = implode(',', array_fill(0, count($this->costCenterCodes), '?'));
        return [
            "{$lineAlias}.cost_center IN ({$ccMarks})
              AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions dim_c
                   WHERE dim_c.line_id = {$lineAlias}.id
                     AND dim_c.dimension_type_id = ?)",
            [...$this->costCenterCodes, $this->typeId],
        ];
    }

    /** @return array{type_id:int, value_id:int, value_ids:list<int>} */
    public function toArray(): array
    {
        return ['type_id' => $this->typeId, 'value_id' => $this->valueId, 'value_ids' => $this->valueIds];
    }
}
