import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import { TOOLS } from '../src/tools.mjs';

function run(input) {
  const child = spawnSync(process.execPath, ['src/hosted-bridge.mjs'], {
    cwd: new URL('..', import.meta.url),
    input: JSON.stringify(input),
    encoding: 'utf8',
  });
  assert.equal(child.status, 0, child.stderr);
  return JSON.parse(child.stdout);
}

const list = (scope, boundSupplierId = null) => run({ operation: 'list', scope, boundSupplierId });

test('hostovaný katalog vrací všechny povolené nástroje v jedné odpovědi', () => {
  for (const scope of ['read', 'read_write']) {
    const page = list(scope);
    const names = page.tools.map((tool) => tool.name);
    const expected = TOOLS.filter((tool) => scope === 'read_write' || !tool.write).map((tool) => tool.name);
    assert.deepEqual(names, expected);
    assert.equal(page.nextCursor, undefined);
    assert.equal(new Set(names).size, names.length);
  }
});

test('nevázaný grant vyžaduje firmu u datových nástrojů', () => {
  const tools = list('read').tools;
  assert.equal(tools.find((tool) => tool.name === 'list_suppliers').inputSchema.properties.supplier_id, undefined);
  assert.equal(tools.find((tool) => tool.name === 'whoami').inputSchema.properties.supplier_id, undefined);
  for (const tool of tools.filter((item) => !['list_suppliers', 'whoami'].includes(item.name))) {
    assert.equal(tool.inputSchema.properties.supplier_id.type, 'integer', tool.name);
    assert.ok(tool.inputSchema.required.includes('supplier_id'), tool.name);
  }
  const missing = run({ operation: 'call', scope: 'read', name: 'list_invoices', arguments: {} });
  assert.equal(missing.isError, true);
  assert.match(missing.content[0].text, /supplier_id/);
});

test('starší grant zůstává vázaný na původní firmu', () => {
  const schema = list('read', 7).tools.find((tool) => tool.name === 'list_invoices').inputSchema;
  assert.equal(schema.properties.supplier_id, undefined);
  const mismatch = run({
    operation: 'call', scope: 'read', boundSupplierId: 7,
    name: 'list_invoices', arguments: { supplier_id: 8 },
  });
  assert.equal(mismatch.isError, true);
  assert.match(mismatch.content[0].text, /původně schválenou firmu/);
});

test('doména firmy nepovolí volání nástroje pro jinou firmu', () => {
  const mismatch = run({
    operation: 'call', scope: 'read', lockedSupplierId: 7,
    name: 'list_invoices', arguments: { supplier_id: 8 },
  });
  assert.equal(mismatch.isError, true);
  assert.match(mismatch.content[0].text, /doména je omezená na jinou firmu/);
});
