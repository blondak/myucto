<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Návod ke konfiguraci pro dodavatele hostingu musí jmenovat klíče, které
 * aplikace SKUTEČNĚ čte.
 *
 * ⚠️ Není to kosmetika. Návod dlouho uváděl `db.password`, `smtp.password`
 * a `smtp.from`, kdežto aplikace čte `db.pass`, `smtp.pass` a `smtp.from_email`.
 * Instance postavená podle něj by se **nepřipojila k databázi** a neodeslala by
 * jedinou zprávu — a přišlo by se na to až u zákazníka, protože podle toho
 * návodu nikdo instanci nikdy nepostavil.
 *
 * Test čte návod jako data: vytáhne z něj konfigurační blok a u každého klíče
 * ověří, že se na něj někde ve zdrojácích ptáme přes `Config::get()`.
 *
 * Návod žije v `private/`, které není v repozitáři. Když chybí, test se
 * přeskočí — na cizím stroji není co ověřovat.
 */
final class VendorConfigGuideTest extends TestCase
{
    private const GUIDE = '/private/Hosting/CFG-LOCAL-DODAVATEL.txt';

    /**
     * Klíče, které se v návodu objevují, ale aplikace je nečte přes Config.
     *
     * `app.managed_provider` čte diagnostika instance přes vlastní cestu,
     * `redis.enabled` čte tovární třída podmíněně jinde než ostatní klíče —
     * obojí je ověřené ručně a nemá smysl kvůli tomu ohýbat hledání.
     *
     * @var list<string>
     */
    private const NOT_VIA_CONFIG_GET = [];

    public function testEveryDocumentedKeyIsActuallyReadByTheApplication(): void
    {
        $guide = $this->guide();
        $src = dirname(__DIR__, 2) . '/src';
        $haystack = $this->sourceText($src);

        $missing = [];
        foreach ($this->documentedKeys($guide) as $key) {
            if (in_array($key, self::NOT_VIA_CONFIG_GET, true)) {
                continue;
            }
            if (!str_contains($haystack, "'" . $key . "'") && !str_contains($haystack, '"' . $key . '"')) {
                $missing[] = $key;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Návod pro dodavatele jmenuje klíče, které aplikace nikde nečte: ' . implode(', ', $missing),
        );
    }

    /**
     * Opačný směr: klíče, bez kterých spravovaná instance nefunguje, musí
     * v návodu být. Seznam je krátký a záměrně ruční — jsou to ty, jejichž
     * chybějící hodnota se projeví až v provozu, ne při startu.
     */
    public function testKeysWithoutWhichAManagedInstanceIsBrokenAreDocumented(): void
    {
        $guide = $this->guide();
        $documented = $this->documentedKeys($guide);

        $required = [
            'db.pass',            // bez něj se instance nepřipojí k databázi
            'smtp.pass',
            'smtp.auth_enabled',  // bez něj se přihlašovací údaje k SMTP vůbec nepoužijí
            'smtp.from_email',
            'app.managed',
            'app.pepper',
            'app.secret_encryption_key',
            'setup.provision_token',
        ];

        $absent = array_values(array_diff($required, $documented));
        self::assertSame([], $absent, 'V návodu chybí klíče: ' . implode(', ', $absent));
    }

    private function guide(): string
    {
        $path = dirname(__DIR__, 3) . self::GUIDE;
        if (!is_file($path)) {
            $this->markTestSkipped('Návod pro dodavatele není k dispozici (private/ není v repozitáři).');
        }
        return (string) file_get_contents($path);
    }

    /**
     * Vytáhne z návodu tečkované klíče (`db.pass`, `smtp.from_email`, …).
     *
     * Čte se blok `'sekce' => array ( 'klíč' => … )`, tedy přesně to, co má
     * dodavatel opsat do `cfg.local.php`.
     *
     * @return list<string>
     */
    private function documentedKeys(string $guide): array
    {
        $keys = [];
        $section = null;
        foreach (preg_split('/\R/', $guide) ?: [] as $line) {
            if (preg_match("/^\s*'([a-z_]+)'\s*=>\s*array\s*\(/", $line, $m) === 1) {
                $section = $m[1];
                continue;
            }
            if ($section !== null && preg_match('/^\s*\)/', $line) === 1) {
                $section = null;
                continue;
            }
            if ($section !== null && preg_match("/^\s*'([a-z_]+)'\s*=>/", $line, $m) === 1) {
                $keys[] = $section . '.' . $m[1];
            }
        }
        return array_values(array_unique($keys));
    }

    private function sourceText(string $dir): string
    {
        $out = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out .= (string) file_get_contents($file->getPathname());
            }
        }
        return $out;
    }
}
