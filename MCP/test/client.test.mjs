import assert from 'node:assert/strict';
import test from 'node:test';

import { ApiError, MyUctoClient } from '../src/client.mjs';

const client = () => new MyUctoClient({
  baseUrl: 'https://example.test/api/v1',
  token: 'test-token',
  maxRps: 0,
  maxConcurrent: 1,
  timeoutMs: 1000,
  version: 'test',
});

test('čtecí POST opakuje přechodnou chybu serveru', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return calls === 1
      ? new Response('', { status: 503 })
      : new Response(JSON.stringify({ items: [] }), { status: 200 });
  };

  try {
    const result = await client().postRead('/catalog/products/batch', { ids: [1] }, 'batch');
    assert.deepEqual(result, { items: [] });
    assert.equal(calls, 2);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('nacenění skladových řádků jde čtecím POSTem', async () => {
  const originalFetch = globalThis.fetch;
  const seen = [];
  globalThis.fetch = async (url, init) => {
    seen.push({ url: String(url), method: init.method });
    return new Response(JSON.stringify({ lines: [] }), { status: 200 });
  };

  try {
    const result = await client().postRead('/stock/items/quote', { lines: [] }, 'quote');
    assert.deepEqual(result, { lines: [] });
    assert.deepEqual(seen, [{ url: 'https://example.test/api/v1/stock/items/quote', method: 'POST' }]);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('čtecí POST odmítne jinou než výslovně povolenou cestu', () => {
  assert.throws(
    () => client().postRead('/stock/items', { name: 'Test' }, 'batch'),
    /není pro cestu.*povolen/i,
  );
});

test('náhled Intrastatu funguje i s čtecím klientem', async () => {
  const originalFetch = globalThis.fetch;
  const seen = [];
  globalThis.fetch = async (url, init) => {
    seen.push({ url: String(url), method: init.method });
    return new Response(JSON.stringify({ rows: [], warnings: [] }), { status: 200 });
  };
  try {
    const api = client();
    api.readOnly = true;
    assert.deepEqual(await api.postRead('/stock/intrastat/preview', { period: '2026-09', direction: 'arrival' }, 'intrastat_preview'), { rows: [], warnings: [] });
    assert.deepEqual(seen, [{ url: 'https://example.test/api/v1/stock/intrastat/preview', method: 'POST' }]);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('běžný POST neopakuje ani rate limit', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return new Response(JSON.stringify({ error: { code: 'temporary', message: 'Dočasná chyba' } }), {
      status: 429,
      headers: { 'Content-Type': 'application/json' },
    });
  };

  try {
    await assert.rejects(
      client().post('/stock/items', { name: 'Test' }, 'write'),
      (error) => error instanceof ApiError && error.status === 429,
    );
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('stažení souboru vrátí base64, typ a název i s diakritikou', async () => {
  const originalFetch = globalThis.fetch;
  const bytes = Buffer.from([0x25, 0x50, 0x44, 0x46, 0x2d, 0x00, 0xff, 0xfe, 0x80]);
  const seen = [];
  globalThis.fetch = async (url, init) => {
    seen.push({ url: String(url), accept: init.headers.Accept, tool: init.headers['X-MyUcto-Tool'] });
    // Server posílá syrové UTF-8 v uvozovkách; fetch ho přečte jako latin1.
    const latin1 = Buffer.from('attachment; filename="Faktura č. 1.pdf"', 'utf8').toString('latin1');
    return new Response(bytes, {
      status: 200,
      headers: { 'Content-Type': 'application/octet-stream', 'Content-Disposition': latin1 },
    });
  };
  try {
    const file = await client().download('/documents/5/download', { download: 1 }, 'download_document');
    assert.deepEqual(file, {
      filename: 'Faktura č. 1.pdf',
      content_type: 'application/pdf',
      size: bytes.length,
      content_base64: bytes.toString('base64'),
    });
    assert.deepEqual(seen, [{
      url: 'https://example.test/api/v1/documents/5/download?download=1', accept: '*/*', tool: 'download_document',
    }]);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('stažení nad limit skončí srozumitelnou chybou i bez Content-Length', async () => {
  const api = client();
  api.maxFileBytes = 1000;
  api.fetcher = async () => new Response(Buffer.alloc(10), {
    status: 200, headers: { 'Content-Type': 'application/pdf', 'Content-Length': '5000' },
  });
  await assert.rejects(api.download('/invoices/1/pdf', null, 't'),
    (e) => e instanceof ApiError && e.status === 413 && e.code === 'file_too_large');

  let pulled = 0;
  api.fetcher = async () => new Response(new ReadableStream({
    pull(controller) {
      pulled += 1;
      controller.enqueue(new Uint8Array(400));
      if (pulled > 50) controller.close();
    },
  }), { status: 200, headers: { 'Content-Type': 'application/pdf' } });
  await assert.rejects(api.download('/invoices/1/pdf', null, 't'),
    (e) => e instanceof ApiError && e.code === 'file_too_large' && /aplikaci/.test(e.message));
  assert.ok(pulled < 10, `čtení se mělo zastavit hned po překročení, přečteno ${pulled} bloků`);
});

test('chyba při stažení se čte jako JSON chyba API', async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => new Response(JSON.stringify({ error: { code: 'no_pdf', message: 'Bez PDF.' } }), {
    status: 404, headers: { 'Content-Type': 'application/json' },
  });
  try {
    await assert.rejects(client().download('/purchase-invoices/7/pdf', null, 't'),
      (e) => e instanceof ApiError && e.status === 404 && e.code === 'no_pdf');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('nahrání složí multipart s neporušeným obsahem a neopakuje se', async () => {
  const originalFetch = globalThis.fetch;
  const bytes = Buffer.from([0x25, 0x50, 0x44, 0x46, 0x0d, 0x0a, 0x2d, 0x2d, 0x00, 0xff]);
  const seen = [];
  globalThis.fetch = async (url, init) => {
    seen.push({ url: String(url), method: init.method, headers: init.headers, body: init.body });
    return new Response(JSON.stringify({ created: [1] }), { status: 503 });
  };
  try {
    await assert.rejects(client().upload('/invoices/3/attachments', {
      content_base64: `data:application/pdf;base64,${bytes.toString('base64')}`,
      filename: 'C:\\Users\\x\\Smlouva "nová".pdf',
      content_type: 'application/pdf',
    }, 'upload_invoice_attachment', { fields: { folder_id: 4, zip_mode: undefined } }),
    (e) => e instanceof ApiError && e.status === 503);
    assert.equal(seen.length, 1);

    const [{ method, headers, body }] = seen;
    assert.equal(method, 'POST');
    const boundary = /^multipart\/form-data; boundary=(----MyUctoMcp[0-9a-f]{32})$/.exec(headers['Content-Type'])[1];
    assert.ok(Buffer.isBuffer(body));
    const head = `--${boundary}\r\nContent-Disposition: form-data; name="folder_id"\r\n\r\n4\r\n`
      + `--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="Smlouva _nová_.pdf"\r\n`
      + 'Content-Type: application/pdf\r\n\r\n';
    const tail = `\r\n--${boundary}--\r\n`;
    assert.equal(body.subarray(0, Buffer.byteLength(head)).toString('utf8'), head);
    assert.deepEqual(body.subarray(Buffer.byteLength(head), body.length - tail.length), bytes);
    assert.equal(body.subarray(body.length - tail.length).toString('utf8'), tail);
    assert.equal(headers['X-MyUcto-Tool'], 'upload_invoice_attachment');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('nahrání odmítne neplatné base64, velký soubor i režim jen pro čtení dřív, než něco pošle', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return new Response('{}', { status: 200 });
  };
  const file = (content_base64, filename = 'a.pdf') => ({ content_base64, filename, content_type: 'application/pdf' });
  try {
    const api = client();
    await assert.rejects(api.upload('/documents', file('JVBERi0*'), 't'), /není platné base64/);
    await assert.rejects(api.upload('/documents', file('JVBERi0'), 't'), /není platné base64/);
    await assert.rejects(api.upload('/documents', file(''), 't'), /prázdný/);
    await assert.rejects(api.upload('/documents', file('JVBERi0x', '../'), 't'), /název souboru/);
    api.maxFileBytes = 4;
    await assert.rejects(api.upload('/documents', file('JVBERi0xLjc='), 't'),
      (e) => e instanceof ApiError && e.code === 'file_too_large');
    const readOnly = client();
    readOnly.readOnly = true;
    await assert.rejects(readOnly.upload('/documents', file('JVBERi0x'), 'upload_document'), /jen pro čtení/);
    assert.equal(calls, 0);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
