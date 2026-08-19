<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

/**
 * Rozsah měsíční exekuční evidence osoby: u každé ze tří evidencí říká, jestli
 * ji někdo doložil, jestli chybí, nebo proč se v tomto měsíci nevyžadovala.
 *
 * Každá z těch tří evidencí drží něco jiného, proto se vyhodnocují zvlášť:
 *
 *  • **rejstřík pohledávek** rozhoduje, KOMU a v jakém pořadí srážka připadne.
 *    Bez jediné aktivní pohledávky (a bez insolvence) není komu nic přidělit.
 *    Podstatná část tohoto příznaku je stejně per případ (`evidence_complete`
 *    na `payroll_enforcement_cases`) a do měsíčního příznaku se propisuje
 *    konjunkcí — u nulového počtu případů je konjunkce prázdná;
 *  • **vyživované osoby** a **manžel/ka** zvedají nezabavitelnou částku
 *    (§ 278 OSŘ, nařízení vlády č. 595/2006 Sb.). Neuplatněný nárok částku
 *    neposouvá, takže dokládat není co.
 *
 * Nezabavitelná částka se přitom počítá i v měsíci BEZ exekuce — odvozuje se
 * z ní strop dobrovolné dohody o srážkách ze mzdy (§ 148 odst. 2 zákoníku
 * práce). Rozsah proto nesmí být jen „nemá exekuci"; uplatněný a nedoložený
 * nárok se značí {@see EnforcementEvidenceSource::NothingWithheld} a kapacitu
 * dohod uzavře, místo aby shodil celý běh do ručního posouzení.
 */
final readonly class EnforcementEvidenceScope
{
    public function __construct(
        public EnforcementEvidenceSource $claimRegister,
        public EnforcementEvidenceSource $dependants,
        public EnforcementEvidenceSource $spouse,
    ) {}

    /**
     * Nezabavitelná částka stojí na nároku, který nikdo nedoložil — dobrovolná
     * dohoda o srážkách z ní tedy nesmí čerpat. Fail-closed: stejná nula, jakou
     * dnes vrací ruční posouzení, jen bez blokátoru na celém běhu.
     */
    public function protectedAmountIsUnattested(): bool
    {
        return $this->dependants === EnforcementEvidenceSource::NothingWithheld
            || $this->spouse === EnforcementEvidenceSource::NothingWithheld;
    }

    /** @return list<string> */
    public function issues(): array
    {
        $issues = [];
        if ($this->claimRegister === EnforcementEvidenceSource::Missing) {
            $issues[] = 'claim_register_evidence_incomplete';
        }
        if ($this->dependants === EnforcementEvidenceSource::Missing) {
            $issues[] = 'dependants_evidence_incomplete';
        }
        if ($this->spouse === EnforcementEvidenceSource::Missing) {
            $issues[] = 'spouse_allowance_evidence_incomplete';
        }

        return $issues;
    }

    /** @return array<string,string> */
    public function toCanonicalArray(): array
    {
        return [
            'claim_register' => $this->claimRegister->value,
            'dependants' => $this->dependants->value,
            'spouse' => $this->spouse->value,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        return new self(
            self::source($data, 'claim_register'),
            self::source($data, 'dependants'),
            self::source($data, 'spouse'),
        );
    }

    /** @param array<string,mixed> $data */
    private static function source(array $data, string $key): EnforcementEvidenceSource
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return EnforcementEvidenceSource::from($value);
    }
}
