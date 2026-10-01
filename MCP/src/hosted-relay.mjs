/**
 * Serverový MCP pro spravované instalace, kde Node běží odděleně od aplikace
 * (typicky v kontejneru bez sítě, bez PHP a bez přístupu k datům).
 *
 * Proces sám nic nespouští ani nikam nevolá. S PHP, které ho spustilo, mluví
 * po řádcích JSON:
 *
 *   stdin   1. řádek  zadání (operation, scope, name, arguments, …)
 *   stdout  {"type":"fetch","id":1,"url":…,"method":…,"headers":…,"body":…}
 *   stdin   {"id":1,"status":200,"headers":{…},"body":"…"}  nebo  {"id":1,"error":"…"}
 *   stdout  {"type":"result","result":{…}}
 *
 * Nahrávaný soubor (multipart) jde místo `body` v `bodyBase64`, stažený soubor
 * se vrací stejně (viz `hosted-body.mjs`). Velikost i tvar hlídá PHP.
 *
 * Token ani hlavičku firmy sem PHP neposílá, doplňuje je až k požadavku na API.
 * Verzi instalace posílá v zadání, takže procesu stačí vidět složku `MCP`
 * a nepotřebuje kořenový soubor `VERSION`.
 */
import { stdin, stdout } from 'node:process';
import { createInterface } from 'node:readline';

import { decodeBody, encodeBody } from './hosted-body.mjs';

const pending = new Map();
let nextId = 1;
let started = false;
let closed = false;

function send(message, done) {
  stdout.write(`${JSON.stringify(message)}\n`, done);
}

function relayFetch(url, options) {
  return new Promise((resolve, reject) => {
    // Po zavření vstupu už odpověď nemá kdo poslat; bez toho by každý pokus
    // čekal na vlastní timeout a proces zbytečně visel.
    if (closed) {
      reject(new Error('Spojení s aplikací bylo ukončeno.'));
      return;
    }
    const id = nextId;
    nextId += 1;
    pending.set(id, { resolve, reject });
    options.signal?.addEventListener('abort', () => {
      if (pending.delete(id)) reject(new Error('Požadavek na API vypršel.'));
    }, { once: true });
    send({
      type: 'fetch', id, url: String(url), method: options.method,
      headers: options.headers, ...encodeBody(options.body),
    });
  });
}

function finish(result) {
  send({ type: 'result', result }, () => process.exit(0));
}

function failure(error) {
  return { content: [{ type: 'text', text: error.message }], isError: true };
}

async function start(line) {
  const input = JSON.parse(line);
  if (typeof input.version === 'string' && /^\d+\.\d+\.\d+$/.test(input.version)) {
    globalThis.__MYUCTO_MCP_VERSION__ = input.version;
  }
  // Až po nastavení verze: `version.mjs` ji čte při načtení modulu.
  const { runHosted } = await import('./hosted-core.mjs');
  return runHosted(input, relayFetch);
}

const lines = createInterface({ input: stdin, crlfDelay: Infinity });

lines.on('line', (line) => {
  if (!started) {
    started = true;
    start(line).then(finish, (error) => finish(failure(error)));
    return;
  }

  let message;
  try {
    message = JSON.parse(line);
  } catch {
    return;
  }
  const waiting = pending.get(message?.id);
  if (!waiting) return;
  pending.delete(message.id);
  if (typeof message.error === 'string') {
    waiting.reject(new Error(message.error));
    return;
  }
  try {
    waiting.resolve(new Response(decodeBody(message), {
      status: message.status,
      headers: message.headers,
    }));
  } catch (cause) {
    waiting.reject(cause);
  }
});

lines.on('close', () => {
  closed = true;
  if (!started) {
    finish(failure(new Error('MCP most nedostal zadání.')));
    return;
  }
  for (const waiting of pending.values()) {
    waiting.reject(new Error('Spojení s aplikací bylo ukončeno.'));
  }
  pending.clear();
});
