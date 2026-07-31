#!/usr/bin/env node
/**
 * MCP server pro MyÚčto.cz — stdio.
 *
 * Zpřístupní AI klientovi (Claude Code, Claude Desktop, IDE rozšíření) fakturaci,
 * e-shop se skladem a statistiku přes veřejné REST API `/api/v1`. Účetní vrstva
 * je záměrně mimo rozsah — viz komentář v `tools.mjs`.
 *
 * Konfigurace přes proměnné prostředí:
 *   MYUCTO_API_URL         povinné, např. https://ucto.firma.cz/api/v1
 *   MYUCTO_API_TOKEN       povinné, token `mi_pat_…` z aplikace (API tokeny)
 *   MYUCTO_SUPPLIER_ID     volitelné, firma pro tokeny nevázané na jednu firmu
 *   MYUCTO_READ_ONLY       volitelné, "1" = zakázat všechny zápisové nástroje
 *   MYUCTO_MAX_RPS         volitelné, strop požadavků za sekundu (výchozí 8)
 *   MYUCTO_MAX_CONCURRENT  volitelné, souběžná volání (výchozí 3)
 *   MYUCTO_TIMEOUT_MS      volitelné, timeout požadavku (výchozí 30000)
 *   MYUCTO_INSECURE_TLS    volitelné, "1" = nekontrolovat HTTPS certifikát (JEN vývoj)
 */

import tls from 'node:tls';

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import { ApiError, MyUctoClient, ReadOnlyError } from './client.mjs';
import { TOOLS, TOOLS_BY_NAME } from './tools.mjs';

const VERSION = '1.0.0';

function readConfig(env) {
  const apiUrl = (env.MYUCTO_API_URL ?? '').trim();
  const token = (env.MYUCTO_API_TOKEN ?? '').trim();
  const problems = [];

  if (!apiUrl) {
    problems.push('MYUCTO_API_URL není nastavená (např. https://ucto.firma.cz/api/v1).');
  } else if (!/^https?:\/\//i.test(apiUrl)) {
    problems.push(`MYUCTO_API_URL musí začínat http:// nebo https:// — dostal jsem "${apiUrl}".`);
  } else if (!/\/api\/v1\/?$/.test(apiUrl)) {
    // Nejčastější chyba při zprovozňování: zadaná adresa aplikace místo API.
    // Server by pak volal neexistující cesty a vracel HTML místo JSON.
    problems.push(`MYUCTO_API_URL má končit "/api/v1" — dostal jsem "${apiUrl}".`);
  }

  if (!token) {
    problems.push('MYUCTO_API_TOKEN není nastavený (token vygenerujete v aplikaci: Nastavení firmy → API tokeny).');
  } else if (!token.startsWith('mi_pat_')) {
    problems.push('MYUCTO_API_TOKEN nevypadá jako token MyÚčta — má začínat "mi_pat_".');
  }

  if (problems.length > 0) {
    throw new Error(`Chybná konfigurace MCP serveru:\n  - ${problems.join('\n  - ')}`);
  }

  return {
    baseUrl: apiUrl,
    token,
    supplierId: (env.MYUCTO_SUPPLIER_ID ?? '').trim() || null,
    readOnly: isTrue(env.MYUCTO_READ_ONLY),
    maxRps: positiveNumber(env.MYUCTO_MAX_RPS, 8),
    maxConcurrent: positiveNumber(env.MYUCTO_MAX_CONCURRENT, 3),
    timeoutMs: positiveNumber(env.MYUCTO_TIMEOUT_MS, 30_000),
    version: VERSION,
  };
}

function positiveNumber(raw, fallback) {
  const value = Number(raw);
  return Number.isFinite(value) && value > 0 ? value : fallback;
}

const isTrue = (raw) => ['1', 'true', 'yes'].includes(String(raw ?? '').toLowerCase());
const isFalse = (raw) => ['0', 'false', 'no'].includes(String(raw ?? '').toLowerCase());

/**
 * Přidá k důvěryhodným autoritám ty ze systémového úložiště.
 *
 * Node má vlastní zabudovaný seznam kořenových autorit a úložiště operačního
 * systému ve výchozím stavu NEČTE. Firemní nebo vlastnoručně vydaný certifikát
 * proto v prohlížeči projde (ten systému věří), ale tomuhle serveru spadne na
 * "unable to verify the first certificate" — a chyba přijde jako obyčejná
 * síťová, takže vypadá jako výpadek serveru.
 *
 * Node 22.15+ umí systémové autority načíst za běhu, takže to řešíme tady
 * a uživatel nemusí nic nastavovat. Na starším Nodu zbývá spustit proces
 * s `--use-system-ca`, případně `NODE_EXTRA_CA_CERTS`.
 *
 * @returns {string} popis výsledku pro diagnostický řádek na stderr
 */
function trustSystemCertificates(env) {
  if (isFalse(env.MYUCTO_SYSTEM_CA)) {
    return 'systémové certifikáty vypnuty (MYUCTO_SYSTEM_CA=0)';
  }
  if (typeof tls.getCACertificates !== 'function' || typeof tls.setDefaultCACertificates !== 'function') {
    return `systémové certifikáty nepodporuje ${process.version} (nutný Node 22.15+ nebo --use-system-ca)`;
  }

  try {
    const system = tls.getCACertificates('system');
    if (system.length === 0) {
      return 'systémové úložiště certifikátů je prázdné';
    }
    // Sjednocení, ne náhrada: zabudované autority musí zůstat, jinak by přestala
    // fungovat běžná veřejná HTTPS instance.
    const merged = new Set([...tls.getCACertificates('default'), ...system]);
    tls.setDefaultCACertificates([...merged]);
    return `+${system.length} systémových certifikátů`;
  } catch (e) {
    return `systémové certifikáty se nepodařilo načíst: ${e.message}`;
  }
}

/** Odpověď nástroje: JSON v textovém obsahu + strojově čitelný `structuredContent`. */
function toolResult(payload) {
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload && typeof payload === 'object' && !Array.isArray(payload)
      ? payload
      : { result: payload },
  };
}

/**
 * Chyba se vrací jako `isError` výsledek, ne jako výjimka protokolu: model tak
 * dostane text, na který může reagovat (doplnit chybějící parametr, přepnout
 * firmu), místo aby mu spadlo celé volání.
 */
function toolError(message) {
  return { content: [{ type: 'text', text: message }], isError: true };
}

/** Nápověda k 403 — kód rozliší, jestli jde o token, práva, nebo vypnutý modul. */
const FORBIDDEN_HINTS = {
  token_ip_forbidden: 'Token má nastavené omezení na IP adresy a tahle adresa mezi nimi není.',
  insufficient_scope: 'Token má rozsah jen pro čtení; zápis vyžaduje token „čtení a zápis".',
  token_endpoint_forbidden: 'Tenhle endpoint není přes API token dostupný.',
  // Modul je volitelný a pro danou firmu vypnutý — s oprávněními to nesouvisí,
  // takže obecná hláška „nemáte práva" by posílala uživatele špatným směrem.
  stock_disabled: 'Skladový a e-shopový modul není pro tuto firmu zapnutý — '
    + 'zapíná se v aplikaci v nastavení firmy. Nástroje pro zboží a zásoby do té doby nefungují.',
};

function describeApiError(e, toolName) {
  const hints = {
    401: 'Token je neplatný, zrušený nebo expirovaný — vygenerujte v aplikaci nový.',
    403: FORBIDDEN_HINTS[e.code] ?? 'Uživatel tokenu nemá pro tuto operaci oprávnění.',
    404: 'Záznam neexistuje nebo nepatří aktuální firmě.',
    429: 'Překročen limit požadavků — zkuste to za chvíli, nebo snižte MYUCTO_MAX_RPS.',
  };

  const hint = hints[e.status];
  const detail = e.detail ? `\nPodrobnosti: ${JSON.stringify(e.detail)}` : '';
  return `Nástroj ${toolName} selhal (HTTP ${e.status}, ${e.code}): ${e.message}`
    + (hint ? `\n${hint}` : '') + detail;
}

async function main() {
  // Musí proběhnout PŘED prvním requestem, ne až při chybě — jinak by první
  // volání spadlo na certifikát a agent to ohlásil jako nedostupný server.
  const caStatus = trustSystemCertificates(process.env);

  if (isTrue(process.env.MYUCTO_INSECURE_TLS)) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
    process.stderr.write(
      'VAROVÁNÍ: MYUCTO_INSECURE_TLS=1 — ověřování HTTPS certifikátu je vypnuté. '
      + 'Používejte jen proti vývojové instanci.\n',
    );
  }

  let config;
  try {
    config = readConfig(process.env);
  } catch (e) {
    // Konfigurační chyba musí jít na stderr — stdout patří MCP protokolu.
    process.stderr.write(`${e.message}\n`);
    process.exit(1);
    return;
  }

  const client = new MyUctoClient(config);

  const server = new Server(
    { name: 'myucto', version: VERSION },
    { capabilities: { tools: {} } },
  );

  // V režimu jen pro čtení zápisové nástroje vůbec nenabízíme — model si tak
  // nenaplánuje postup, který stejně nedokáže dokončit.
  const exposed = config.readOnly ? TOOLS.filter((t) => !t.write) : TOOLS;

  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: exposed.map((t) => ({
      name: t.name,
      title: t.title,
      description: t.write
        ? `${t.description}\n\n⚠️ Tento nástroj MĚNÍ DATA v ostré instanci.`
        : t.description,
      inputSchema: t.inputSchema,
      annotations: {
        title: t.title,
        readOnlyHint: !t.write,
        destructiveHint: false,
        idempotentHint: !t.write,
        openWorldHint: true,
      },
    })),
  }));

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;
    const tool = TOOLS_BY_NAME.get(name);

    if (!tool) {
      return toolError(`Neznámý nástroj "${name}".`);
    }
    if (tool.write && config.readOnly) {
      return toolError(new ReadOnlyError(name).message);
    }

    try {
      const payload = await tool.run(client, args ?? {}, name);
      return toolResult(payload);
    } catch (e) {
      if (e instanceof ApiError) {
        return toolError(describeApiError(e, name));
      }
      return toolError(`Nástroj ${name} selhal: ${e.message}`);
    }
  });

  await server.connect(new StdioServerTransport());
  process.stderr.write(
    `MyÚčto MCP v${VERSION} připojen — ${exposed.length} nástrojů, API ${config.baseUrl}`
    + `${config.readOnly ? ' (jen pro čtení)' : ''}; TLS: ${caStatus}\n`,
  );
}

main().catch((e) => {
  process.stderr.write(`MyÚčto MCP selhal: ${e?.stack ?? e}\n`);
  process.exit(1);
});
