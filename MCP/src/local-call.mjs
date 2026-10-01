/**
 * Volání nástroje v lokálním (stdio) serveru. Leží mimo `index.mjs`, aby šlo
 * testovat bez spuštění MCP transportu a bez proměnných prostředí.
 */

import { ApiError, ReadOnlyError } from './client.mjs';
import { validateToolArguments } from './tool-args.mjs';
import { toolResult } from './tool-result.mjs';

/**
 * Chyba se vrací jako `isError` výsledek, ne jako výjimka protokolu: model tak
 * dostane text, na který může reagovat (doplnit chybějící parametr, přepnout
 * firmu), místo aby mu spadlo celé volání.
 */
export function toolError(message) {
  return { content: [{ type: 'text', text: message }], isError: true };
}

/** Nápověda k 403 — kód rozliší, jestli jde o token, práva, nebo vypnutý modul. */
const FORBIDDEN_HINTS = {
  token_ip_forbidden: 'Token má nastavené omezení na IP adresy a tahle adresa mezi nimi není.',
  insufficient_scope: 'Token má rozsah jen pro čtení; zápis vyžaduje token „čtení a zápis".',
  token_endpoint_forbidden: 'Tenhle endpoint není přes API token dostupný.',
  token_write_forbidden: 'Účetní a daňová vrstva je přes API token vždy jen ke čtení; krok dokončete v aplikaci.',
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

/**
 * @param {{toolsByName: Map<string, object>, readOnly: boolean, client: object}} context
 */
export async function callLocalTool({ toolsByName, readOnly, client }, name, args) {
  const tool = toolsByName.get(name);

  if (!tool) {
    return toolError(`Neznámý nástroj "${name}".`);
  }
  if (tool.write && readOnly) {
    return toolError(new ReadOnlyError(name).message);
  }

  try {
    const payload = await tool.run(client, validateToolArguments(tool, args ?? {}), name);
    return toolResult(payload);
  } catch (e) {
    if (e instanceof ApiError) {
      return toolError(describeApiError(e, name));
    }
    return toolError(`Nástroj ${name} selhal: ${e.message}`);
  }
}
