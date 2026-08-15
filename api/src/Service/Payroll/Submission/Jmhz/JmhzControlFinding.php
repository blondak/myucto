<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Nález jedné kontroly. Nese vedle výsledku i to, co ČSSZ z kontroly odvozuje:
 * propustnost (jestli vada způsobí neúčinnost) a rozsah (co přesně se zamítá).
 *
 * `part` a `formOrdinal` váží nález na konkrétní součást podání, aby uživatel
 * nedostal „něco je špatně" bez informace, u kterého zaměstnance.
 */
final readonly class JmhzControlFinding
{
    public function __construct(
        public int $controlId,
        public string $name,
        public JmhzControlOutcome $outcome,
        public JmhzControlScope $scope,
        public JmhzControlPassability $passability,
        public bool $technical,
        public string $part,
        public ?int $formOrdinal,
        public string $message,
        /** @var list<string> */
        public array $attributeIds,
        public ?int $errorCode,
    ) {}

    /**
     * Nepropustná vada způsobuje neúčinnost podání, části nebo součásti —
     * takové podání nemá smysl odesílat.
     */
    public function blocksSubmission(): bool
    {
        return $this->outcome === JmhzControlOutcome::Failed
            && $this->passability === JmhzControlPassability::Blocking;
    }

    /**
     * Propustná vada podání nezneplatní, ale konzumenti dostanou chybná data
     * a mohou postihovat ve své agendě — musí být vidět jako varování.
     */
    public function warnsOnly(): bool
    {
        return $this->outcome === JmhzControlOutcome::Failed
            && $this->passability !== JmhzControlPassability::Blocking;
    }

    /**
     * @return array{
     *     control_id:int,name:string,outcome:string,scope:string,passability:string,
     *     technical:bool,part:string,form_ordinal:?int,message:string,
     *     attribute_ids:list<string>,error_code:?int
     * }
     */
    public function toArray(): array
    {
        return [
            'control_id' => $this->controlId,
            'name' => $this->name,
            'outcome' => $this->outcome->value,
            'scope' => $this->scope->value,
            'passability' => $this->passability->value,
            'technical' => $this->technical,
            'part' => $this->part,
            'form_ordinal' => $this->formOrdinal,
            'message' => $this->message,
            'attribute_ids' => $this->attributeIds,
            'error_code' => $this->errorCode,
        ];
    }
}
