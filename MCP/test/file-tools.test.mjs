import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { HOSTED_MAX_ENVELOPE_BYTES, HOSTED_MAX_FILE_BYTES } from '../src/client.mjs';
import {
  DOCUMENT_TYPES, FILE_TOOLS, INVOICE_ATTACHMENT_TYPES, PRODUCT_MEDIA_TYPES,
  PURCHASE_PDF_TYPES, STRUCTURED_IMPORT_TYPES,
} from '../src/file-tools.mjs';
import { toolResult } from '../src/tool-result.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

const PDF_BYTES = Buffer.from('%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n', 'latin1');
const PDF_BASE64 = PDF_BYTES.toString('base64');

class FileClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    return this.responses[`${method} ${path}`] ?? { ok: true };
  }

  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return this.response('GET', path);
  }

  async post(path, body, tool) {
    this.calls.push({ method: 'POST', path, body, tool });
    return this.response('POST', path);
  }

  async patch(path, body, tool) {
    this.calls.push({ method: 'PATCH', path, body, tool });
    return this.response('PATCH', path);
  }

  async del(path, tool) {
    this.calls.push({ method: 'DELETE', path, tool });
    return this.response('DELETE', path);
  }

  async download(path, query, tool) {
    this.calls.push({ method: 'DOWNLOAD', path, query, tool });
    return this.responses[`DOWNLOAD ${path}`] ?? {
      filename: 'faktura.pdf', content_type: 'application/pdf', size: PDF_BYTES.length, content_base64: PDF_BASE64,
    };
  }

  async upload(path, file, tool, options = {}) {
    this.calls.push({ method: 'UPLOAD', path, file, tool, options });
    return this.response('UPLOAD', path);
  }
}

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

test('souborové nástroje jsou v katalogu se správnými příznaky', () => {
  const expected = {
    download_invoice_pdf: [false, false],
    download_invoice_isdoc: [false, false],
    list_invoice_attachments: [false, false],
    download_invoice_attachment: [false, false],
    upload_invoice_attachment: [true, false],
    delete_invoice_attachment: [true, true],
    download_purchase_invoice_pdf: [false, false],
    upload_purchase_invoice_pdf: [true, false],
    delete_purchase_invoice_pdf: [true, true],
    import_purchase_invoice_file: [true, false],
    upload_document: [true, false],
    download_document: [false, false],
    upload_product_media: [true, false],
  };
  assert.deepEqual(FILE_TOOLS.map((t) => t.name).sort(), Object.keys(expected).sort());
  for (const [name, [write, destructive]] of Object.entries(expected)) {
    assert.equal(tool(name), FILE_TOOLS.find((t) => t.name === name), name);
    assert.equal(Boolean(tool(name).write), write, name);
    assert.equal(Boolean(tool(name).destructive), destructive, name);
    if (destructive) assert.ok(tool(name).inputSchema.properties.confirm, name);
    if (name.startsWith('download_')) {
      assert.deepEqual(tool(name).inputSchema.properties.format.enum, ['resource', 'base64'], name);
      assert.match(tool(name).description, /5 MB/, name);
    }
    if (name.startsWith('upload_') || name.startsWith('import_')) {
      assert.ok(tool(name).inputSchema.required.includes('content_base64'), name);
      assert.ok(tool(name).inputSchema.required.includes('filename'), name);
    }
  }
});

test('stažení PDF faktury volá binární GET a vrací vložený soubor jen jednou', async () => {
  const c = new FileClient();
  const payload = await tool('download_invoice_pdf').run(c, { id: 12 }, 'download_invoice_pdf');
  assert.deepEqual(c.calls, [{ method: 'DOWNLOAD', path: '/invoices/12/pdf', query: { download: 1 }, tool: 'download_invoice_pdf' }]);

  const result = toolResult(payload);
  assert.equal(result.content.length, 2);
  assert.equal(result.content[1].type, 'resource');
  assert.equal(result.content[1].resource.mimeType, 'application/pdf');
  assert.equal(result.content[1].resource.blob, PDF_BASE64);
  assert.equal(result.content[1].resource.uri, 'myucto://invoices/12/pdf');
  assert.deepEqual(result.structuredContent, {
    invoice_id: 12, filename: 'faktura.pdf', content_type: 'application/pdf', size: PDF_BYTES.length,
    uri: 'myucto://invoices/12/pdf',
  });
  // base64 nesmí být ani v textu, ani ve structuredContent — jinak by se přenášel dvakrát
  assert.equal(JSON.stringify(result).split(PDF_BASE64).length - 1, 1);
});

test('stažení na přání vrátí base64 v JSON a obrázek jako obrázek, ISDOC jako text', async () => {
  const c = new FileClient({
    'DOWNLOAD /invoices/12/attachments/3': {
      filename: 'foto.png', content_type: 'image/png', size: 4, content_base64: 'iVBORw==',
    },
    'DOWNLOAD /invoices/12/isdoc': {
      filename: 'Faktura-2026001.isdoc', content_type: 'application/xml', size: 9,
      content_base64: Buffer.from('<Invoice/>').toString('base64'),
    },
  });
  const asBase64 = toolResult(await tool('download_invoice_pdf').run(c, { id: 12, format: 'base64' }, 't'));
  assert.equal(asBase64.content.length, 1);
  assert.equal(JSON.parse(asBase64.content[0].text).content_base64, PDF_BASE64);
  assert.equal(asBase64.structuredContent.content_base64, undefined);

  const image = toolResult(await tool('download_invoice_attachment').run(c, { id: 12, attachment_id: 3 }, 't'));
  assert.deepEqual(image.content[1], { type: 'image', data: 'iVBORw==', mimeType: 'image/png' });
  assert.deepEqual(c.calls[1].query, { download: 1 });

  const isdoc = toolResult(await tool('download_invoice_isdoc').run(c, { id: 12 }, 't'));
  assert.equal(isdoc.content[1].resource.text, '<Invoice/>');
  assert.equal(isdoc.content[1].resource.blob, undefined);
});

test('stažení dokumentu umí hlavní i další soubor dokladu', async () => {
  const c = new FileClient();
  await tool('download_document').run(c, { id: 5 }, 't');
  await tool('download_document').run(c, { id: 5, file_id: 9 }, 't');
  await tool('download_purchase_invoice_pdf').run(c, { id: 7 }, 't');
  assert.deepEqual(c.calls.map((call) => call.path), [
    '/documents/5/download', '/documents/5/files/9/download', '/purchase-invoices/7/pdf',
  ]);
});

test('nahrání přílohy ověří typ proti příponě a odstraní cestu z názvu', async () => {
  const c = new FileClient();
  await tool('upload_invoice_attachment').run(c, {
    id: 12, content_base64: PDF_BASE64, filename: '..\\..\\tajne/smlouva.pdf',
  }, 'upload_invoice_attachment');
  assert.deepEqual(c.calls, [{
    method: 'UPLOAD', path: '/invoices/12/attachments', tool: 'upload_invoice_attachment', options: {},
    file: { filename: 'smlouva.pdf', content_type: 'application/pdf', content_base64: PDF_BASE64 },
  }]);

  await assert.rejects(
    tool('upload_invoice_attachment').run(c, { id: 12, content_base64: PDF_BASE64, filename: 'stranka.html' }, 't'),
    /přijímá jen soubory .*\.pdf/,
  );
  await assert.rejects(
    tool('upload_invoice_attachment').run(c, {
      id: 12, content_base64: PDF_BASE64, filename: 'smlouva.pdf', content_type: 'text/html',
    }, 't'),
    /neodpovídá příponě \.pdf/,
  );
  await assert.rejects(
    tool('upload_invoice_attachment').run(c, {
      id: 12, content_base64: PDF_BASE64, filename: 'smlouva.pdf', content_type: 'application/pdf\r\nX-Evil: 1',
    }, 't'),
    /Neplatný content_type/,
  );
  assert.equal(c.calls.length, 1);
});

test('smazání přílohy bez potvrzení jen ukáže, co by smazalo', async () => {
  const responses = {
    'GET /invoices/12/attachments': { items: [{ id: 3, original_name: 'smlouva.pdf', size_bytes: 20480 }] },
  };
  const c = new FileClient(responses);
  await assert.rejects(
    tool('delete_invoice_attachment').run(c, { id: 12, attachment_id: 3 }, 't'),
    /NEPROVEDENO.*smlouva\.pdf \(20 kB\)/s,
  );
  assert.equal(c.calls.some((call) => call.method === 'DELETE'), false);

  await assert.rejects(
    tool('delete_invoice_attachment').run(c, { id: 12, attachment_id: 4, confirm: true }, 't'),
    /#4 u faktury #12 neexistuje/,
  );

  const result = await tool('delete_invoice_attachment').run(c, { id: 12, attachment_id: 3, confirm: true }, 't');
  assert.equal(result.deleted.original_name, 'smlouva.pdf');
  assert.equal(c.calls.at(-1).method, 'DELETE');
  assert.equal(c.calls.at(-1).path, '/invoices/12/attachments/3');
});

test('nahrání PDF přijaté faktury nepřepíše existující originál bez potvrzení', async () => {
  const fresh = new FileClient({ 'GET /purchase-invoices/7': { id: 7, pdf_path: null } });
  await tool('upload_purchase_invoice_pdf').run(fresh, { id: 7, content_base64: PDF_BASE64, filename: 'doklad.jpg' }, 't');
  assert.equal(fresh.calls.at(-1).path, '/purchase-invoices/7/pdf');
  assert.equal(fresh.calls.at(-1).file.content_type, 'image/jpeg');

  const archived = new FileClient({
    'GET /purchase-invoices/7': { id: 7, pdf_path: 'supplier-1/ab/x.pdf', pdf_original_name: 'puvodni.pdf' },
  });
  await assert.rejects(
    tool('upload_purchase_invoice_pdf').run(archived, { id: 7, content_base64: PDF_BASE64, filename: 'novy.pdf' }, 't'),
    /NEPROVEDENO.*puvodni\.pdf/s,
  );
  assert.equal(archived.calls.some((call) => call.method === 'UPLOAD'), false);
  await tool('upload_purchase_invoice_pdf').run(archived, {
    id: 7, content_base64: PDF_BASE64, filename: 'novy.pdf', confirm: true,
  }, 't');
  assert.equal(archived.calls.at(-1).method, 'UPLOAD');

  await assert.rejects(
    tool('upload_purchase_invoice_pdf').run(fresh, { id: 7, content_base64: PDF_BASE64, filename: 'doklad.docx' }, 't'),
    /přijímá jen soubory/,
  );
});

test('smazání PDF přijaté faktury vyžaduje potvrzení a existující PDF', async () => {
  const none = new FileClient({ 'GET /purchase-invoices/7': { id: 7, pdf_path: null } });
  await assert.rejects(tool('delete_purchase_invoice_pdf').run(none, { id: 7, confirm: true }, 't'), /nemá archivované PDF/);

  const c = new FileClient({
    'GET /purchase-invoices/7': { id: 7, pdf_path: 'x.pdf', pdf_original_name: 'doklad.pdf', vendor_invoice_number: 'FV-1' },
  });
  await assert.rejects(tool('delete_purchase_invoice_pdf').run(c, { id: 7 }, 't'), /NEPROVEDENO.*doklad\.pdf u faktury FV-1/s);
  assert.equal(c.calls.some((call) => call.method === 'DELETE'), false);
  await tool('delete_purchase_invoice_pdf').run(c, { id: 7, confirm: true }, 't');
  assert.equal(c.calls.at(-1).path, '/purchase-invoices/7/pdf');
});

test('import přijaté faktury bere jen ISDOC, ISDOCX a PDF', async () => {
  const c = new FileClient();
  await tool('import_purchase_invoice_file').run(c, { content_base64: 'PEludm9pY2UvPg==', filename: 'faktura.isdoc' }, 't');
  assert.deepEqual(c.calls[0].file, {
    filename: 'faktura.isdoc', content_type: 'application/xml', content_base64: 'PEludm9pY2UvPg==',
  });
  assert.equal(c.calls[0].path, '/purchase-invoices/import-structured');
  await assert.rejects(
    tool('import_purchase_invoice_file').run(c, { content_base64: PDF_BASE64, filename: 'sken.jpg' }, 't'),
    /\.pdf, \.isdoc, \.isdocx/,
  );
  assert.match(tool('import_purchase_invoice_file').description, /NEPOUŽÍVÁ AI/);
});

test('nahrání dokumentu doplní metadata a vazbu k jednoznačnému dokumentu', async () => {
  const c = new FileClient({ 'UPLOAD /documents': { created: 1, root_ids: [41], skipped: [], errors: [] } });
  const result = await tool('upload_document').run(c, {
    content_base64: PDF_BASE64, filename: 'smlouva.pdf', folder_id: 3,
    title: 'Nájemní smlouva', tags: ['smlouvy'], entity_type: 'client', entity_id: 8,
  }, 'upload_document');
  assert.equal(result.document_id, 41);
  assert.deepEqual(c.calls.map((call) => `${call.method} ${call.path}`), [
    'UPLOAD /documents', 'PATCH /documents/41', 'POST /documents/41/links',
  ]);
  assert.deepEqual(c.calls[0].options, { fields: { folder_id: 3, zip_mode: undefined } });
  assert.deepEqual(c.calls[1].body, { title: 'Nájemní smlouva', tags: ['smlouvy'] });
  assert.deepEqual(c.calls[2].body, { entity_type: 'client', entity_id: 8 });
});

test('rozbalený ZIP metadata nehádá a vazba bez obou údajů se odmítne', async () => {
  const c = new FileClient({ 'UPLOAD /documents': { created: 3, root_ids: [1, 2, 3] } });
  const result = await tool('upload_document').run(c, {
    content_base64: 'UEsDBA==', filename: 'balik.zip', zip_mode: 'explode', title: 'Balík',
  }, 't');
  assert.match(result.warning, /3 dokumentů/);
  assert.equal(c.calls.length, 1);

  await assert.rejects(
    tool('upload_document').run(c, { content_base64: PDF_BASE64, filename: 'a.pdf', entity_type: 'client' }, 't'),
    /entity_type.*entity_id/,
  );
  await assert.rejects(
    tool('upload_document').run(c, { content_base64: 'PHN2Zy8+', filename: 'obrazek.svg' }, 't'),
    /přijímá jen soubory/,
  );
});

test('média zboží jdou na kartu zboží', async () => {
  const c = new FileClient();
  await tool('upload_product_media').run(c, { id: 15, content_base64: 'iVBORw==', filename: 'foto.png' }, 't');
  assert.equal(c.calls[0].path, '/eshop/products/15/media');
  assert.equal(c.calls[0].file.content_type, 'image/png');
});

test('povolené typy a stropy souhlasí s mostem v PHP', () => {
  const php = readFileSync(new URL('../../api/src/Service/Mcp/McpFileLimits.php', import.meta.url), 'utf8');
  const block = /ALLOWED_UPLOAD_TYPES = \[([\s\S]*?)\];/.exec(php)[1];
  const allowed = new Set([...block.matchAll(/'([^']+)'/g)].map((m) => m[1]));
  for (const types of [DOCUMENT_TYPES, INVOICE_ATTACHMENT_TYPES, PRODUCT_MEDIA_TYPES, PURCHASE_PDF_TYPES, STRUCTURED_IMPORT_TYPES]) {
    for (const [ext, mimes] of Object.entries(types)) {
      for (const mime of mimes) assert.ok(allowed.has(mime), `${ext}: ${mime} most v PHP nepustí`);
    }
  }
  const constant = (name) => {
    const m = new RegExp(`const ${name} = (\\d+) \\* 1024 \\* 1024;`).exec(php);
    assert.ok(m, name);
    return Number(m[1]) * 1024 * 1024;
  };
  assert.equal(constant('MAX_FILE_BYTES'), HOSTED_MAX_FILE_BYTES);
  assert.equal(constant('MAX_ENVELOPE_BYTES'), HOSTED_MAX_ENVELOPE_BYTES);
  assert.ok(Math.ceil((HOSTED_MAX_FILE_BYTES + 64 * 1024) / 3) * 4 + 1024 * 1024 < HOSTED_MAX_ENVELOPE_BYTES);
});
