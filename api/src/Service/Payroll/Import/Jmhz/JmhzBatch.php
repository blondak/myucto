<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Který formulář v dávce platí.
 *
 * ── Opravné a stornovací podání ─────────────────────────────────────────────
 * Pravidla podání ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidPolicy}):
 * oprava bezvadné součásti nese PŮVODNÍ GUID formuláře s typem „O" a úplné
 * tělo, storno součásti nese původní GUID s typem „S" a tělo nemá; stornující
 * podání (typ podání „S") formuláře nemá vůbec a váže se na GUID řádného
 * podání.
 *
 * Proto:
 *  - z formulářů se stejným obdobím a GUID platí ten s nejpozdějším datem
 *    vyplnění; ostatní jsou nahrazené a nic se z nich nepřebírá,
 *  - pozdější storno součásti (S) platný formulář zruší — nic se nepřebírá,
 *  - stornující podání zruší všechny formuláře podání se stejným GUID,
 *    které byly vyplněné dřív,
 *  - dva RŮZNÉ platné formuláře pro tentýž vztah (ID PPV) v jednom měsíci jsou
 *    konflikt: z dávky nejde poznat, který ČSSZ přijala, takže se neimportuje
 *    ani jeden.
 */
final class JmhzBatch
{
    public const EFFECTIVE = 'effective';
    public const SUPERSEDED = 'superseded';
    public const CANCELLED = 'cancelled';
    public const CANCELLATION = 'cancellation';
    public const EMPTY = 'empty';
    public const CONFLICT = 'conflict';

    /** @var array<string,JmhzBatchItem> */
    private array $items = [];
    /** @var array<string,string> */
    private array $states = [];
    /** @var array<string,string> */
    private array $notes = [];
    /** @var array<string,array{count:?int,ordinals:array<int,true>}> */
    private array $packages = [];

    /**
     * @param list<JmhzBatchItem> $items
     * @param list<array{file:JmhzReportFile,name:string}> $stornos stornující podání (bez formulářů)
     */
    public static function build(array $items, array $stornos): self
    {
        $batch = new self();
        $groups = [];
        foreach ($items as $item) {
            $batch->items[$item->key] = $item;
            $packageKey = $item->file->period() . '|' . $item->file->submissionGuid . '|' . $item->file->submissionType;
            $batch->packages[$packageKey]['count'] = $item->file->packageCount;
            $batch->packages[$packageKey]['ordinals'][$item->file->packageOrdinal ?? 1] = true;
            if ($item->form->formType === 'S') {
                $batch->states[$item->key] = self::CANCELLATION;
                $batch->notes[$item->key] = 'Storno součásti — z formuláře se nic nepřebírá.';
                continue;
            }
            if (!$item->form->hasBody()) {
                $batch->states[$item->key] = self::EMPTY;
                $batch->notes[$item->key] = 'Formulář nenese žádné údaje osoby.';
                continue;
            }
            $groups[$item->period() . '|' . $item->form->formGuid][] = $item;
        }

        foreach ($groups as $group) {
            usort($group, static fn (JmhzBatchItem $a, JmhzBatchItem $b): int => [
                $b->file->filledAt, $b->fileIndex, $b->form->position,
            ] <=> [
                $a->file->filledAt, $a->fileIndex, $a->form->position,
            ]);
            $winner = array_shift($group);
            $batch->states[$winner->key] = self::EFFECTIVE;
            foreach ($group as $older) {
                $batch->states[$older->key] = self::SUPERSEDED;
                $batch->notes[$older->key] = 'Formulář nahradilo pozdější podání v souboru „'
                    . $winner->fileName . '“; nic se z něj nepřebírá.';
            }
            foreach ($items as $candidate) {
                if ($candidate->form->formType === 'S'
                    && $candidate->period() === $winner->period()
                    && $candidate->form->formGuid === $winner->form->formGuid
                    && $candidate->file->filledAt >= $winner->file->filledAt
                ) {
                    $batch->states[$winner->key] = self::CANCELLED;
                    $batch->notes[$winner->key] = 'Formulář stornovalo podání v souboru „'
                        . $candidate->fileName . '“; nic se z něj nepřebírá.';
                }
            }
            foreach ($stornos as $storno) {
                if ($storno['file']->submissionGuid === $winner->file->submissionGuid
                    && $storno['file']->period() === $winner->period()
                    && $storno['file']->filledAt >= $winner->file->filledAt
                ) {
                    $batch->states[$winner->key] = self::CANCELLED;
                    $batch->notes[$winner->key] = 'Celé hlášení stornovalo podání v souboru „'
                        . $storno['name'] . '“; nic se z něj nepřebírá.';
                }
            }
        }

        $byEmployment = [];
        foreach ($batch->items as $key => $item) {
            if ($batch->states[$key] !== self::EFFECTIVE || $item->form->employmentIdentifier === null) {
                continue;
            }
            $byEmployment[$item->period() . '|' . $item->form->employmentIdentifier][] = $key;
        }
        foreach ($byEmployment as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            foreach ($keys as $key) {
                $batch->markConflict($key);
            }
        }

        return $batch;
    }

    public function markConflict(string $key): void
    {
        $this->states[$key] = self::CONFLICT;
        $this->notes[$key] = 'Za stejný měsíc je v dávce víc různých formulářů pro tentýž pracovní vztah. '
            . 'Nahrajte jen hlášení, které ČSSZ přijala; z těchto formulářů se nic nepřebírá.';
    }

    public function state(string $key): string
    {
        return $this->states[$key] ?? self::EMPTY;
    }

    public function note(string $key): ?string
    {
        return $this->notes[$key] ?? null;
    }

    /** @return list<JmhzBatchItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function item(string $key): ?JmhzBatchItem
    {
        return $this->items[$key] ?? null;
    }

    /** @return list<JmhzBatchItem> */
    public function effective(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (JmhzBatchItem $item): bool => $this->states[$item->key] === self::EFFECTIVE,
        ));
    }

    /**
     * Ostatní platné formuláře téže osoby (podle OIČ) v témže měsíci.
     *
     * @return list<JmhzBatchItem>
     */
    public function siblings(JmhzBatchItem $item): array
    {
        if ($item->form->personIdentifier === null) {
            return [];
        }

        return array_values(array_filter(
            $this->effective(),
            static fn (JmhzBatchItem $other): bool => $other->key !== $item->key
                && $other->period() === $item->period()
                && $other->form->personIdentifier === $item->form->personIdentifier,
        ));
    }

    /** Má dávka všechny dílčí balíky podání, ze kterého formulář pochází? */
    public function packageComplete(JmhzBatchItem $item): bool
    {
        $package = $this->packages[$item->period() . '|' . $item->file->submissionGuid . '|' . $item->file->submissionType] ?? null;
        if ($package === null || $package['count'] === null || $package['count'] <= 1) {
            return true;
        }

        return count($package['ordinals']) >= $package['count'];
    }
}
