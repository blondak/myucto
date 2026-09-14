import { readFileSync } from 'node:fs';

const embeddedVersion = typeof __MYUCTO_MCP_VERSION__ === 'string'
  ? __MYUCTO_MCP_VERSION__
  : null;

const version = (embeddedVersion
  ?? readFileSync(new URL('../../VERSION', import.meta.url), 'utf8')).trim();

if (!/^\d+\.\d+\.\d+$/.test(version)) {
  throw new Error(`Neplatná verze MyÚčta: "${version}".`);
}

export const VERSION = version;
