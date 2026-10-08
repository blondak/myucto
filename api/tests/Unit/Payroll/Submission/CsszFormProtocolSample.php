<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

/**
 * Protokoly ČSSZ k podání jednoho formuláře (NEMPRI25, HZUPN20, OZUSPOJ23).
 *
 * Tvar je opsaný z protokolů zkušebních podání do testovacího prostředí ČSSZ
 * z 8. 10. 2026 (kolo 2): obálka GovTalk, podepsaná časová značka v hlavičce
 * `Message`, `ProcessingResult` s obecnou položkou a jediným formulářem.
 * Rodné číslo, CorrelationID i podpis jsou syntetické; podpis se v testech
 * ověřuje dvojníkem stejně jako u ostatních testů parseru.
 */
final class CsszFormProtocolSample
{
    public const CORRELATION = 'B0000000000000000000000000000001';
    public const BIRTH_NUMBER = '7001010001';

    /** Text chyby 103 tak, jak ho ČSSZ vrací (třída se do něj dosazuje). */
    public static function serviceAuthorizationText(string $class): string
    {
        return "Pověření k dané e-službě ('{$class}') není zaznamenáno v registru podávajících"
            . ' na OSSZ nebo certifikát, kterým je e-podání podepsáno, není zaznamenán'
            . ' v registru podávajících na OSSZ. Kontaktujte pracovníka OSSZ.';
    }

    /** Přijaté podání: obecná položka a formulář s výsledkem OK. */
    public static function accepted(string $class, string $form, string $sequence = '1'): string
    {
        return self::protocol($class, 'response', 'OK', [
            self::item($form, '', '', 'OK'),
            self::item($form, $sequence, self::BIRTH_NUMBER, 'OK'),
        ]);
    }

    /**
     * Chyba komunikace 103: podání se nezpracovalo, `ProcessingResult` nese
     * kód a holý text, jediná položka je prázdná, obálka nese `GovTalkErrors`.
     */
    public static function serviceAuthorizationMissing(string $class): string
    {
        $text = self::serviceAuthorizationText($class);

        return self::protocol(
            $class,
            'error',
            'ERROR',
            [self::item('', '', '', '')],
            $text,
            '103',
            0,
            '<GovTalkErrors><Error Id="0"><RaisedBy>CSSZDIS</RaisedBy><Number>103</Number>'
                . '<Text>' . htmlspecialchars($text, ENT_XML1) . '</Text><Type>business</Type>'
                . '</Error></GovTalkErrors>',
        );
    }

    /** Odmítnutý formulář s kódem chyby v textu. */
    public static function rejectedForm(string $class, string $form, string $message, string $code): string
    {
        return self::protocol($class, 'error', 'ERROR', [
            self::item($form, '', '', 'OK'),
            self::item($form, '1', self::BIRTH_NUMBER, 'ERROR', $message, $code),
        ], $message, $code);
    }

    public static function item(
        string $subtype,
        string $sequence,
        string $identifier,
        string $result,
        string $errMsg = '',
        string $errNum = '',
    ): string {
        return sprintf(
            '<Item sqnr="%s" identifier="%s" subtype="%s" period="" result="%s" errMsg="%s" errNum="%s" />',
            $sequence,
            $identifier,
            $subtype,
            $result,
            htmlspecialchars($errMsg, ENT_XML1 | ENT_QUOTES),
            $errNum,
        );
    }

    /** @param list<string> $items */
    public static function protocol(
        string $class,
        string $qualifier,
        string $result,
        array $items,
        string $errMsg = '',
        string $errNumber = '0',
        int $warnings = 0,
        string $govTalkErrors = '',
        string $envelopeClass = '',
    ): string {
        $type = trim($class);
        $errors = count(array_filter($items, static fn (string $item): bool => str_contains($item, 'result="ERROR"')));

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"'
            . ' xmlns:xsig="http://www.w3.org/2000/09/xmldsig#">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
            . '<Class>' . ($envelopeClass === '' ? $class : $envelopeClass) . '</Class>'
            . "<Qualifier>{$qualifier}</Qualifier><Function>submit</Function><TransactionID />"
            . '<CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="60">https://t-epodani.cssz.cz/VREP/submission</ResponseEndPoint>'
            . '<Transformation>XML</Transformation>'
            . '<GatewayTimestamp>2026-10-08T04:05:00.000</GatewayTimestamp>'
            . '</MessageDetails></Header><GovTalkDetails><Keys><Key Type="SpokeName">VREP</Key></Keys>'
            . $govTalkErrors . '</GovTalkDetails>'
            . '<Body Id="1"><Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="response">'
            . '<Header><Signature xmlns="http://www.cssz.cz/emp/timestamp" Version="1.0">'
            . '<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha512" />'
            . '<TimeStamp><date>20261008</date><time>02:04:31</time></TimeStamp>'
            . '<SignatureValue>U1lOVEVUSUNLWQ==</SignatureValue></Signature></Header><Body>'
            . sprintf(
                '<ProcessingResult type="%s" version="1,0" result="%s" errMsg="%s" errNumber="%s"'
                    . ' count="1" countErr="%d" countWar="%d">',
                $type,
                $result,
                htmlspecialchars($errMsg, ENT_XML1 | ENT_QUOTES),
                $errNumber,
                max($errors, $result === 'ERROR' ? 1 : 0),
                $warnings,
            )
            . '<Error><RaisedBy>' . ($errNumber === '0' ? '' : 'CSSZDIS') . '</RaisedBy>'
            . '<Number>' . $errNumber . '</Number><Type>' . $type . '</Type>'
            . '<Text>' . htmlspecialchars($errMsg, ENT_XML1) . '</Text></Error>'
            . '<Details>' . implode('', $items) . '</Details></ProcessingResult>'
            . '</Body></Message></Body></GovTalkMessage>';
    }
}
