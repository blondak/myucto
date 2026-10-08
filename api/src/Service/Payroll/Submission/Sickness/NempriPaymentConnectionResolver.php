<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payment\CzechBankAccountValidator;

/**
 * Způsob výplaty mzdy z výplatního profilu zaměstnance → `platebniSpojeni`.
 *
 * § 97 odst. 2 věta druhá zák. č. 187/2006 Sb.: zaměstnavatel zasílá
 * s podklady pro výpočet „údaje o způsobu výplaty mzdy, platu nebo odměny“.
 * Chybí-li, ČSSZ si je vyžádá výzvou a výplata dávky se zdrží. Proto:
 *
 *  - mzda na účet (i částečně) → účet, na který mzda chodí; český účet jako
 *    `ucetCZ`, zahraniční IBAN jako `ucetZahranicni`,
 *  - mzda v hotovosti → adresa bydliště (`vyplatitAdresa`),
 *  - mzda vyplácená přes partnera (vyrovnání s jiným subjektem) → podání se
 *    ZASTAVÍ: DV NEMPRI25 vyžaduje platební spojení u každé věty s akcí vznik,
 *    zaměstnavatel nezná účet, na který mzda doopravdy dojde, a vymyslet ho
 *    nesmí. Pravidlo „dávka jde tam, kam mzda" na partnera nepasuje, takže
 *    účet nebo adresu pro výplatu dávky musí doplnit účetní.
 *
 * Volající resolver nevolá u ošetřovného a dlouhodobého ošetřovného bez akce
 * vznik — tam DV platební spojení zakazuje.
 *
 * Třída je čistá: plaintext účtu i adresu dostane hotové.
 */
final readonly class NempriPaymentConnectionResolver
{
    public function __construct(
        private CzechBankAccountValidator $czechAccounts = new CzechBankAccountValidator(),
    ) {}

    /**
     * @param array{street_line:string,city:string,postal_code:string,country_code:string}|null $address
     */
    public function resolve(
        ?string $payoutMethod,
        ?string $accountPlaintext,
        ?array $address,
    ): NempriPaymentConnection {
        if ($payoutMethod === 'partner_settlement') {
            throw new SicknessException(
                'nempri_payment_connection_partner_settlement',
                'Mzda zaměstnance se vyplácí přes partnera, takže MyÚčto nezná účet ani adresu, '
                . 'kam má ČSSZ poslat dávku. NEMPRI platební spojení vyžaduje (§ 97 odst. 2 zákona '
                . 'o nemocenském pojištění) a vymyslet ho nelze. Je-li mzda ve skutečnosti '
                . 'vyplácena na účet nebo v hotovosti, opravte způsob výplaty ve výplatním profilu '
                . 'osoby; jinak oznámení podejte mimo aplikaci.',
            );
        }
        if ($payoutMethod === 'cash') {
            return $this->fromAddress($address);
        }
        if ($accountPlaintext !== null) {
            return $this->fromAccount($accountPlaintext);
        }
        if ($payoutMethod === null && $address !== null) {
            return $this->fromAddress($address);
        }

        throw new SicknessException(
            'nempri_payment_connection_missing',
            'Zaměstnanec nemá ve výplatním profilu účet, na který se mu vyplácí mzda. '
            . 'NEMPRI nese způsob výplaty mzdy (§ 97 odst. 2 zákona o nemocenském pojištění); '
            . 'bez něj si ho ČSSZ vyžádá výzvou a dávka se zdrží. Doplňte účet na kartě osoby.',
        );
    }

    public function fromAccount(string $plaintext): NempriPaymentConnection
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $plaintext));
        if (preg_match('/^CZ\d{2}(\d{4})(\d{6})(\d{10})$/D', $compact, $match) === 1) {
            $prefix = ltrim($match[2], '0');
            $number = ltrim($match[3], '0');

            return new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_CZ,
                accountPrefix: $prefix === '' ? null : $prefix,
                accountNumber: $number,
                bankCode: $match[1],
            );
        }
        if (preg_match('/^([A-Z]{2})\d{2}[0-9A-Z]{1,30}$/D', $compact, $match) === 1) {
            // `ucetZahranicni/stat` nesmí být CZ (DV NEMPRI25). Český IBAN, který
            // nejde rozložit na předčíslí, číslo a kód banky, je chybný účet,
            // ne zahraniční.
            if ($match[1] === 'CZ') {
                throw new SicknessException(
                    'nempri_payment_connection_invalid',
                    'Výplatní účet zaměstnance je český IBAN v neplatném tvaru (CZ, 2 kontrolní '
                    . 'číslice a 20 číslic účtu), takže ho nelze zapsat do NEMPRI. Opravte ho na kartě osoby.',
                );
            }

            return new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_FOREIGN,
                iban: $compact,
                countryCode: $match[1],
            );
        }
        try {
            $parsed = $this->czechAccounts->parse($plaintext);
        } catch (\InvalidArgumentException) {
            throw new SicknessException(
                'nempri_payment_connection_invalid',
                'Výplatní účet zaměstnance není platný český účet ani IBAN, takže ho nelze '
                . 'zapsat do NEMPRI. Opravte ho na kartě osoby.',
            );
        }

        return new NempriPaymentConnection(
            NempriPaymentConnection::KIND_ACCOUNT_CZ,
            accountPrefix: $parsed['prefix'],
            accountNumber: $parsed['base'],
            bankCode: $parsed['bank_code'],
        );
    }

    /**
     * Adresa bydliště pro výplatu dávky poštou.
     *
     * `CtAdresa` chce ulici, číslo popisné, orientační a PSČ zvlášť, evidence
     * drží ulici s čísly v jednom řádku. Řádek se rozloží podle českého zápisu
     * „Ulice 123/4a“; nejde-li to, podání se zastaví s výzvou k opravě adresy
     * — hádat číslo popisné by poslalo dávku jinam.
     *
     * @param array{street_line:string,city:string,postal_code:string,country_code:string}|null $address
     */
    public function fromAddress(?array $address): NempriPaymentConnection
    {
        $message = 'Mzda se vyplácí v hotovosti, a tak NEMPRI nese adresu bydliště pro výplatu '
            . 'dávky. Adresa zaměstnance chybí, není česká nebo nemá rozlišitelné číslo popisné '
            . 'a PSČ („Ulice 123/4“). Opravte ji na kartě osoby.';
        if ($address === null || strtoupper(trim($address['country_code'])) !== 'CZ') {
            throw new SicknessException('nempri_payment_address_invalid', $message);
        }
        $line = trim($address['street_line']);
        if (preg_match(
            '/^(.*?)[\s,]*(?:č\.\s*p\.\s*)?(\d{1,4}[a-zA-Z]?)(?:\s*\/\s*(\d{1,4}[a-zA-Z]?))?$/uD',
            $line,
            $match,
        ) !== 1) {
            throw new SicknessException('nempri_payment_address_invalid', $message);
        }
        $postal = (string) preg_replace('/\s+/', '', $address['postal_code']);
        $city = trim($address['city']);
        if ($city === '' || preg_match('/^\d{5}$/D', $postal) !== 1) {
            throw new SicknessException('nempri_payment_address_invalid', $message);
        }
        $street = trim($match[1]);

        return new NempriPaymentConnection(
            NempriPaymentConnection::KIND_ADDRESS,
            city: $city,
            street: $street === '' ? null : $street,
            houseNumber: $match[2],
            orientationNumber: ($match[3] ?? '') === '' ? null : $match[3],
            postalCode: $postal,
        );
    }
}
