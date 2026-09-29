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
