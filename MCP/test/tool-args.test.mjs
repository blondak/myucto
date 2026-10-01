import assert from 'node:assert/strict';
import test from 'node:test';

import { apiUrl } from '../src/client.mjs';
import { runHosted } from '../src/hosted-core.mjs';
import { callLocalTool } from '../src/local-call.mjs';
import { SUPPORTED_KEYWORDS, ToolArgumentsError, validateToolArguments } from '../src/tool-args.mjs';
import { seg } from '../src/tool-shared.mjs';
import { TOOLS, TOOLS_BY_NAME } from '../src/tools.mjs';
import { UPDATE_TOOL } from '../src/update.mjs';

const TRAVERSAL = '../../invoices/123';

const sample = {
  name: 'sample',
  inputSchema: {
    type: 'object',
    additionalProperties: false,
    required: ['id'],
    properties: {
      id: { type: 'integer', minimum: 1 },
      partner_id: { type: ['integer', 'null'], minimum: 1 },
      kind: { type: 'string', enum: ['a', 'b'] },
      title: { type: 'string', minLength: 1, maxLength: 5 },
      vs: { type: ['string', 'null'], pattern: '^[0-9]{0,10}$' },
      on: { type: 'string', format: 'date' },
      flag: { type: 'boolean' },
      price: { type: 'number', exclusiveMinimum: 0 },
      share: { type: 'number', minimum: 0, maximum: 1 },
      ids: { type: 'array', items: { type: 'integer', minimum: 1 }, minItems: 1, maxItems: 3, uniqueItems: true },
      lines: {
        type: 'array',
        items: {
          type: 'object', additionalProperties: false, required: ['qty'],
          properties: { qty: { type: 'number' }, note: { type: 'string' } },
        },
      },
    },
  },
};

const problems = (args) => {
  try {
    validateToolArguments(sample, args);
  } catch (e) {
    assert.ok(e instanceof ToolArgumentsError);
    return e.problems.join('\n');
  }
  return '';
};

test('platné argumenty projdou a celé číslo jako text se převede', () => {
  const valid = validateToolArguments(sample, {
    id: '12', partner_id: null, kind: 'a', title: 'Nájem', vs: '2026', on: '2026-09-30', flag: false,
    price: '10.5', share: 0.25, ids: [1, '2'], lines: [{ qty: '3', note: 'x' }],
  });
  assert.equal(valid.id, 12);
  assert.deepEqual(valid.ids, [1, 2]);
  // Číslo jako text se jen povolí, nepřevádí: nástroje s ním tak pracovaly i dřív.
  assert.equal(valid.price, '10.5');
  assert.equal(valid.lines[0].qty, '3');
  assert.equal(valid.partner_id, null);
});

test('typy, enum a povinné parametry', () => {
  assert.match(problems({}), /`id`: chybí povinný parametr/);
  assert.match(problems({ id: 1.5 }), /`id`: očekává se celé číslo/);
  assert.match(problems({ id: '1e3' }), /`id`: očekává se celé číslo/);
  assert.match(problems({ id: true }), /`id`: očekává se celé číslo/);
  assert.match(problems({ id: 0 }), /`id`: musí být nejméně 1/);
  assert.match(problems({ id: '0' }), /`id`: musí být nejméně 1/);
  assert.match(problems({ id: 1, partner_id: 'x' }), /`partner_id`: očekává se celé číslo nebo null/);
  assert.match(problems({ id: 1, kind: 'c' }), /`kind`: povolené hodnoty jsou "a", "b"/);
  assert.match(problems({ id: 1, flag: 'true' }), /`flag`: očekává se true\/false/);
  assert.match(problems({ id: 1, price: 0 }), /`price`: musí být větší než 0/);
  assert.match(problems({ id: 1, price: 'deset' }), /`price`: očekává se číslo/);
  assert.match(problems({ id: 1, price: '' }), /`price`: očekává se číslo/);
  assert.match(problems({ id: 1, share: 2 }), /`share`: smí být nejvýš 1/);
  assert.match(problems({ id: 1, title: '' }), /`title`: musí mít aspoň 1 znaků/);
  assert.match(problems({ id: 1, title: 'Příliš dlouhé' }), /`title`: smí mít nejvýš 5 znaků/);
  assert.match(problems({ id: 1, vs: 'ABC' }), /`vs`: .*neodpovídá tvaru/);
  assert.match(problems({ id: 1, on: '30.9.2026' }), /`on`: očekává se datum ve tvaru RRRR-MM-DD/);
  assert.match(problems('text'), /argumenty: očekává se objekt/);
});

test('neznámé parametry a vnořené položky', () => {
  assert.match(problems({ id: 1, smazat_vse: true }), /`smazat_vse`: neznámý parametr/);
  assert.match(problems({ id: 1, ids: [] }), /`ids`: musí mít aspoň 1 položek/);
  assert.match(problems({ id: 1, ids: [1, 2, 3, 4] }), /`ids`: smí mít nejvýš 3 položek/);
  assert.match(problems({ id: 1, ids: [1, '1'] }), /`ids`: položky se nesmí opakovat/);
  assert.match(problems({ id: 1, ids: [1, 0] }), /`ids\[1\]`: musí být nejméně 1/);
  assert.match(problems({ id: 1, lines: [{ qty: 1 }, { note: 'x' }] }), /`lines\[1\]\.qty`: chybí povinný parametr/);
  assert.match(problems({ id: 1, lines: [{ qty: 1, extra: 1 }] }), /`lines\[0\]\.extra`: neznámý parametr/);
  assert.match(problems({ id: 1, lines: [{ qty: 'x' }] }), /`lines\[0\]\.qty`: očekává se číslo/);
});

test('klíč __proto__ nepřepíše prototyp výsledku', () => {
  const loose = { name: 'loose', inputSchema: { type: 'object', properties: { meta: { type: 'object' } } } };
  const valid = validateToolArguments(loose, JSON.parse('{"meta": {"__proto__": {"polluted": true}}}'));
  assert.equal(valid.meta.polluted, undefined);
  assert.equal(Object.getPrototypeOf(valid.meta), Object.prototype);
});

test('ID s tečkovými segmenty neprojde žádným nástrojem s celočíselným ID', () => {
  let checked = 0;
  for (const tool of TOOLS) {
    const id = tool.inputSchema.properties?.id;
    if (!id || id.type !== 'integer') continue;
    assert.throws(() => validateToolArguments(tool, { id: TRAVERSAL, confirm: true }),
      (e) => e.problems.some((p) => p.startsWith('`id`: očekává se celé číslo')), tool.name);
    checked += 1;
  }
  assert.ok(checked > 100, `kontrolovaných nástrojů: ${checked}`);
});

test('katalog nepoužívá klíčové slovo, které validátor nezná', () => {
  const unknown = new Set();
  const walk = (schema, where) => {
    if (!schema || typeof schema !== 'object') return;
    for (const key of Object.keys(schema)) if (!SUPPORTED_KEYWORDS.has(key)) unknown.add(`${where}: ${key}`);
    if (schema.format !== undefined && schema.format !== 'date') unknown.add(`${where}: format ${schema.format}`);
    for (const [key, value] of Object.entries(schema.properties ?? {})) walk(value, `${where}.${key}`);
    if (schema.items) walk(schema.items, `${where}[]`);
    if (typeof schema.additionalProperties === 'object') walk(schema.additionalProperties, `${where}{}`);
  };
  for (const tool of [...TOOLS, UPDATE_TOOL]) {
    walk(tool.inputSchema, tool.name);
    // Kořen musí neznámé parametry odmítat, jinak by šly obejít kontroly nástroje.
    assert.equal(tool.inputSchema.additionalProperties, false, tool.name);
  }
  assert.deepEqual([...unknown], []);
});

test('segment cesty kóduje oddělovače a odmítne tečky', () => {
  assert.equal(seg(12), '12');
  assert.equal(seg(TRAVERSAL), '..%2F..%2Finvoices%2F123');
  assert.equal(seg('a?b#c'), 'a%3Fb%23c');
  for (const bad of ['.', '..', '', null, undefined]) assert.throws(() => seg(bad), /Neplatná hodnota v cestě/);
});

test('nástroj zakóduje ID do cesty, i kdyby validace chyběla', async () => {
  const paths = [];
  const client = { get: async (path) => { paths.push(path); return { status: 'draft' }; }, del: async (path) => { paths.push(path); } };
  await TOOLS_BY_NAME.get('delete_other_item').run(client, { id: TRAVERSAL, confirm: true }, 'delete_other_item');
  assert.deepEqual(paths, Array(2).fill('/accounting/other-items/..%2F..%2Finvoices%2F123'));
});

test('klient odmítne cestu s tečkovými segmenty', () => {
  assert.equal(apiUrl('https://example.test/api/v1', '/invoices/4?x=..').pathname, '/api/v1/invoices/4');
  for (const bad of ['/accounting/other-items/../../invoices/123', '/a/./b', '/a/%2e%2E/b', '/a/..']) {
    assert.throws(() => apiUrl('https://example.test/api/v1', bad), /Neplatná cesta požadavku/, bad);
  }
});

const recordingFetch = () => {
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({ url: String(url), method: options.method });
    return new Response(JSON.stringify({ id: 12, status: 'draft', title: 'Koncept' }), {
      status: 200, headers: { 'Content-Type': 'application/json' },
    });
  };
  return { calls, fetcher };
};

test('serverové MCP: smazání konceptu s ID mířícím na fakturu nic nezavolá', async () => {
  const { calls, fetcher } = recordingFetch();
  const result = await runHosted({
    operation: 'call', scope: 'read_write', boundSupplierId: 1, apiUrl: 'https://example.test/api/v1',
    name: 'delete_other_item', arguments: { id: TRAVERSAL, confirm: true },
  }, fetcher);
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /Neplatné argumenty nástroje delete_other_item:[\s\S]*`id`: očekává se celé číslo/);
  assert.deepEqual(calls, []);
});

test('serverové MCP: ID jako text projde převedené na číslo', async () => {
  const { calls, fetcher } = recordingFetch();
  const result = await runHosted({
    operation: 'call', scope: 'read', boundSupplierId: 1, apiUrl: 'https://example.test/api/v1',
    name: 'get_other_item', arguments: { id: '12' },
  }, fetcher);
  assert.equal(result.isError, undefined, JSON.stringify(result));
  assert.deepEqual(calls.map((c) => c.url), ['https://example.test/api/v1/accounting/other-items/12']);
});

test('lokální server: neplatné argumenty se nástroji vůbec nepředají', async () => {
  const calls = [];
  const client = new Proxy({}, {
    get: (_target, method) => async (...args) => { calls.push([method, ...args]); return { status: 'draft' }; },
  });
  const context = { toolsByName: TOOLS_BY_NAME, readOnly: false, client };

  const rejected = await callLocalTool(context, 'delete_other_item', { id: TRAVERSAL, confirm: true });
  assert.equal(rejected.isError, true);
  assert.match(rejected.content[0].text, /^Nástroj delete_other_item selhal: Neplatné argumenty nástroje delete_other_item:/);
  assert.match(rejected.content[0].text, /`id`: očekává se celé číslo/);
  assert.deepEqual(calls, []);

  const unknown = await callLocalTool(context, 'get_other_item', { id: 12, path: '/invoices/1' });
  assert.match(unknown.content[0].text, /`path`: neznámý parametr/);
  assert.deepEqual(calls, []);

  const ok = await callLocalTool(context, 'get_other_item', { id: '12' });
  assert.equal(ok.isError, undefined, JSON.stringify(ok));
  assert.deepEqual(calls.map(([method, path]) => [method, path]), [['get', '/accounting/other-items/12']]);
});
