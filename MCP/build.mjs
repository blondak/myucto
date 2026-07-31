/**
 * Sestaví jednosouborový build serveru do `dist/myucto-mcp.mjs`.
 *
 * K čemu to je: koncový uživatel pak nemusí mít u sebe repozitář ani spouštět
 * `npm install` — stáhne jeden soubor a spustí `node myucto-mcp.mjs`. Odpadá
 * `node_modules` (stovky souborů) a s ním i typická třída chyb „nainstaloval
 * jsem to jinam, než ukazuje konfigurace asistenta".
 *
 * POZOR: build NEODSTRAŇUJE potřebu Node. Je to pořád JavaScript, jen bez
 * externích závislostí — Node 20+ musí být nainstalovaný tak jako tak.
 * Samostatný spustitelný soubor bez Node by znamenal přibalit celý runtime
 * (desítky MB), což pro tenhle nástroj nedává smysl.
 */
import { build } from 'esbuild';
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
const outfile = join(root, 'dist', 'myucto-mcp.mjs');

mkdirSync(join(root, 'dist'), { recursive: true });

const result = await build({
  entryPoints: [join(root, 'src', 'index.mjs')],
  outfile,
  bundle: true,
  platform: 'node',
  format: 'esm',
  target: 'node20',
  // Bez minifikace schválně: soubor si někdo stáhne z instance a má mít možnost
  // se podívat, co spouští. Úspora pár set kB tady nestojí za neprůhlednost.
  minify: false,
  legalComments: 'inline',
  banner: {
    // Bez shebangu — esbuild ho vytáhne ze vstupního souboru na první řádek sám;
    // vlastní by se zdvojil a druhý výskyt je syntaktická chyba.
    js: `// MyÚčto MCP server v${pkg.version} — jednosouborový build (vyžaduje Node 20+).\n`
      + '// Konfigurace přes proměnné prostředí MYUCTO_API_URL a MYUCTO_API_TOKEN.\n'
      + '// Zdrojové kódy: složka MCP/src tohoto projektu.',
  },
  metafile: true,
});

const bytes = Object.values(result.metafile.outputs)[0].bytes;
process.stdout.write(
  `Hotovo: dist/myucto-mcp.mjs (${(bytes / 1024).toFixed(0)} kB)\n`
  + 'Spuštění: node dist/myucto-mcp.mjs\n',
);
