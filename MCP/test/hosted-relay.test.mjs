import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
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

test('po zavření vstupu most katalog dokončí a volání API rychle vzdá', () => {
  const piped = (input) => {
    const child = spawnSync(process.execPath, ['src/hosted-relay.mjs'], {
      cwd: new URL('..', import.meta.url),
      input: `${JSON.stringify(input)}\n`,
      encoding: 'utf8',
      timeout: 15000,
    });
    assert.equal(child.status, 0, child.stderr);
    return child.stdout.trim().split('\n').map((line) => JSON.parse(line));
  };

  const list = piped({ operation: 'list', scope: 'read' });
  assert.equal(list.length, 1);
  assert.ok(list[0].result.tools.length > 100);

  const call = piped({ operation: 'call', scope: 'read', name: 'whoami', arguments: {}, apiUrl: 'https://example.test/api/v1' });
  assert.equal(call.at(-1).result.isError, true);
  assert.match(call.at(-1).result.content[0].text, /Spojení s aplikací bylo ukončeno/);
});

test('neplatné zadání skončí chybou, ne zavěšeným procesem', async () => {
  const { result } = await run('tohle není JSON');
  assert.equal(result.isError, true);
});

test('reléový most přenese stažený soubor i nahrávaný multipart jako base64', async () => {
  const pdf = Buffer.from([0x25, 0x50, 0x44, 0x46, 0x2d, 0x00, 0xff, 0x80, 0x0a]);
  const download = await run(
    { operation: 'call', scope: 'read', boundSupplierId: 1, name: 'download_invoice_pdf', arguments: { id: 4 }, apiUrl: 'https://example.test/api/v1' },
    () => ({
      status: 200,
      headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="F-4.pdf"' },
      body: '',
      bodyBase64: pdf.toString('base64'),
    }),
  );
  assert.equal(download.result.isError, undefined, JSON.stringify(download.result));
  assert.equal(download.fetches[0].url, 'https://example.test/api/v1/invoices/4/pdf?download=1');
  assert.equal(download.result.content[1].resource.blob, pdf.toString('base64'));
  assert.equal(download.result.structuredContent.filename, 'F-4.pdf');
  assert.equal(download.result.structuredContent.size, pdf.length);

  const upload = await run(
    {
      operation: 'call', scope: 'read_write', boundSupplierId: 1, name: 'upload_invoice_attachment', apiUrl: 'https://example.test/api/v1',
      arguments: { id: 4, content_base64: pdf.toString('base64'), filename: 'priloha.pdf' },
    },
    () => ({ status: 200, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ created: [9] }) }),
  );
  assert.equal(upload.result.isError, undefined, JSON.stringify(upload.result));
  const [request] = upload.fetches;
  assert.equal(request.method, 'POST');
  assert.equal(request.body, undefined);
  assert.match(request.headers['Content-Type'], /^multipart\/form-data; boundary=/);
  const body = Buffer.from(request.bodyBase64, 'base64');
  assert.ok(body.includes(pdf));
  assert.match(body.toString('latin1'), /name="file"; filename="priloha\.pdf"\r\nContent-Type: application\/pdf/);
});

test('soubor nad strop serverového MCP most odmítne bez volání API', async () => {
  const big = Buffer.alloc(5 * 1024 * 1024 + 1).toString('base64');
  const { result, fetches } = await run({
    operation: 'call', scope: 'read_write', boundSupplierId: 1, name: 'upload_document', apiUrl: 'https://example.test/api/v1',
    arguments: { content_base64: big, filename: 'velky.pdf' },
  });
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /file_too_large/);
  assert.equal(fetches.length, 0);
});
