import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { RELEASE_API_URL, checkUpdate } from '../src/update.mjs';
import { VERSION } from '../src/version.mjs';

const release = (version, overrides = {}) => ({
  tag_name: `v${version}`,
  draft: false,
  prerelease: false,
  html_url: `https://github.com/radekhulan/myucto/releases/tag/v${version}`,
  assets: [{
    name: `myucto-mcp-${version}.mjs`,
    browser_download_url: `https://github.com/radekhulan/myucto/releases/download/v${version}/myucto-mcp-${version}.mjs`,
  }],
  ...overrides,
});

const response = (payload, status = 200) => new Response(JSON.stringify(payload), {
  status,
  headers: { 'Content-Type': 'application/json' },
});

test('zdrojová verze se načítá z kořenového VERSION', () => {
  const rootVersion = readFileSync(new URL('../../VERSION', import.meta.url), 'utf8').trim();
  assert.equal(VERSION, rootVersion);
});

test('najde novější stabilní release a přesný MCP asset bez autorizace', async () => {
  let request;
  const result = await checkUpdate({
    currentVersion: '6.12.0',
    fetchImpl: async (url, init) => {
      request = { url, init };
      return response(release('6.13.0'));
    },
  });

  assert.equal(request.url, RELEASE_API_URL);
  assert.equal(request.init.headers.Authorization, undefined);
  assert.equal(request.init.headers.Accept, 'application/vnd.github+json');
  assert.deepEqual(result, {
    current: '6.12.0',
    latest: '6.13.0',
    updateAvailable: true,
    downloadUrl: 'https://github.com/radekhulan/myucto/releases/download/v6.13.0/myucto-mcp-6.13.0.mjs',
    releaseUrl: 'https://github.com/radekhulan/myucto/releases/tag/v6.13.0',
  });
});

test('starší poslední release není aktualizace', async () => {
  const result = await checkUpdate({
    currentVersion: '6.12.0',
    fetchImpl: async () => response(release('6.11.1')),
  });
  assert.equal(result.updateAvailable, false);
  assert.equal(result.latest, '6.11.1');
});

test('odmítne release bez přesně pojmenovaného MCP assetu', async () => {
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      fetchImpl: async () => response(release('6.13.0', { assets: [] })),
    }),
    /chybí platný asset myucto-mcp-6\.13\.0\.mjs/,
  );
});

test('odmítne asset s podvrženou URL i neplatnou položku v seznamu', async () => {
  const badRelease = release('6.13.0');
  badRelease.assets.unshift(null);
  badRelease.assets[1].browser_download_url = 'https://github.com/radekhulan/myucto/releases/download/v6.13.0/jiny-soubor.mjs';
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      fetchImpl: async () => response(badRelease),
    }),
    /chybí platný asset myucto-mcp-6\.13\.0\.mjs/,
  );
});

test('odmítne asset URL s query parametrem', async () => {
  const badRelease = release('6.13.0');
  badRelease.assets[0].browser_download_url += '?token=unexpected';
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      fetchImpl: async () => response(badRelease),
    }),
    /chybí platný asset myucto-mcp-6\.13\.0\.mjs/,
  );
});

test('odmítne nekanonickou nebo příliš velkou release verzi', async () => {
  for (const version of ['06.13.0', '9007199254740992.1.0']) {
    await assert.rejects(
      checkUpdate({
        currentVersion: '6.12.0',
        fetchImpl: async () => response(release(version)),
      }),
      /platnou release verzi/,
    );
  }
});

test('HTTP chyba je pro ruční kontrolu srozumitelná', async () => {
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      fetchImpl: async () => response({ message: 'rate limit' }, 403),
    }),
    /GitHub API vrátilo HTTP 403/,
  );
});

test('kontrola ukončí pomalý požadavek timeoutem', async () => {
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      timeoutMs: 10,
      fetchImpl: async (_url, { signal }) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(signal.reason), { once: true });
      }),
    }),
    /vypršela po 10 ms/,
  );
});

test('timeout platí i pro pomalé načtení těla odpovědi', async () => {
  await assert.rejects(
    checkUpdate({
      currentVersion: '6.12.0',
      timeoutMs: 10,
      fetchImpl: async (_url, { signal }) => ({
        ok: true,
        status: 200,
        json: async () => new Promise((resolve, reject) => {
          signal.addEventListener('abort', () => reject(signal.reason), { once: true });
        }),
      }),
    }),
    /vypršela po 10 ms/,
  );
});
