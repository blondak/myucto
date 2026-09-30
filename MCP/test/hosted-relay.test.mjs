import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import test from 'node:test';

import { TOOLS } from '../src/tools.mjs';

/**
 * Pustí reléový most a hraje roli PHP: na každý `fetch` odpoví tím, co vrátí
 * `respond`. Vrací výsledek a seznam požadavků, které si most vyžádal.
 */
function run(input, respond = () => ({ status: 200, headers: {}, body: '{}' })) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, ['src/hosted-relay.mjs'], {
      cwd: new URL('..', import.meta.url),
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    const fetches = [];
    let result;
    let stderr = '';
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    createInterface({ input: child.stdout, crlfDelay: Infinity }).on('line', (line) => {
      const message = JSON.parse(line);
      if (message.type === 'fetch') {
        fetches.push(message);
        child.stdin.write(`${JSON.stringify({ id: message.id, ...respond(message) })}\n`);
      } else if (message.type === 'result') {
        result = message.result;
      }
    });
    child.on('error', reject);
    child.on('close', (code) => {
      if (code !== 0 || result === undefined) reject(new Error(`kód ${code}: ${stderr}`));
      else resolve({ result, fetches });
    });
    child.stdin.write(`${typeof input === 'string' ? input : JSON.stringify(input)}\n`);
  });
}

test('reléový most vrací stejný katalog jako most s vlastním PHP', async () => {
  for (const scope of ['read', 'read_write']) {
    const { result, fetches } = await run({ operation: 'list', scope, boundSupplierId: null });
    const expected = TOOLS.filter((tool) => scope === 'read_write' || !tool.write).map((tool) => tool.name);
    assert.deepEqual(result.tools.map((tool) => tool.name), expected);
    assert.equal(fetches.length, 0);
  }
});

test('volání API jde přes rodičovský proces a most nezná token', async () => {
  const { result, fetches } = await run(
    { operation: 'call', scope: 'read', name: 'whoami', arguments: {}, apiUrl: 'https://example.test/api/v1', version: '9.8.7' },
    () => ({ status: 200, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ relayed: true }) }),
  );
  assert.equal(fetches[0].headers['X-MyUcto-Client-Version'], '9.8.7');
  assert.equal(result.isError, undefined);
  assert.deepEqual(result.structuredContent, { relayed: true });
  assert.equal(fetches.length, 1);
  assert.equal(fetches[0].method, 'GET');
  assert.ok(fetches[0].url.startsWith('https://example.test/api/v1/'));
  assert.equal(fetches[0].headers.Authorization, 'Bearer undefined');
});

test('odmítnutí z API se vrátí jako chyba nástroje', async () => {
  const input = { operation: 'call', scope: 'read', name: 'whoami', arguments: {}, apiUrl: 'https://example.test/api/v1' };
  const denied = await run(input, () => ({
    status: 403, headers: {}, body: JSON.stringify({ error: { code: 'forbidden_supplier', message: 'Ne.' } }),
  }));
  assert.equal(denied.result.isError, true);
  assert.match(denied.result.content[0].text, /HTTP 403 \(forbidden_supplier\)/);
});

test('pravidla vazby na firmu platí i v reléovém mostu', async () => {
  const mismatch = await run({
    operation: 'call', scope: 'read', lockedSupplierId: 7,
    name: 'list_invoices', arguments: { supplier_id: 8 },
  });
  assert.equal(mismatch.result.isError, true);
  assert.match(mismatch.result.content[0].text, /doména je omezená na jinou firmu/);
  assert.equal(mismatch.fetches.length, 0);
});

test('neplatné zadání skončí chybou, ne zavěšeným procesem', async () => {
  const { result } = await run('tohle není JSON');
  assert.equal(result.isError, true);
});
