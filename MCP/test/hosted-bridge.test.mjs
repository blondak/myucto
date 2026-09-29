import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import { TOOLS } from '../src/tools.mjs';

function list(scope, cursor) {
  const child = spawnSync(process.execPath, ['src/hosted-bridge.mjs'], {
    cwd: new URL('..', import.meta.url),
    input: JSON.stringify({ operation: 'list', scope, ...(cursor ? { cursor } : {}) }),
    encoding: 'utf8',
  });
  assert.equal(child.status, 0, child.stderr);
  return JSON.parse(child.stdout);
}

test('hostovaný katalog vrací všechny povolené nástroje po stránkách', () => {
  for (const scope of ['read', 'read_write']) {
    const names = [];
    let cursor;
    do {
      const page = list(scope, cursor);
      assert.ok(page.tools.length > 0 && page.tools.length <= 30);
      names.push(...page.tools.map((tool) => tool.name));
      cursor = page.nextCursor;
    } while (cursor);
    const expected = TOOLS.filter((tool) => scope === 'read_write' || !tool.write).map((tool) => tool.name);
    assert.deepEqual(names, expected);
    assert.equal(new Set(names).size, names.length);
  }
});
