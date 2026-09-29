import { stdin, stdout } from 'node:process';
import { spawn } from 'node:child_process';

import { ApiError, MyUctoClient, ReadOnlyError } from './client.mjs';
import { TOOLS, TOOLS_BY_NAME } from './tools.mjs';
import { VERSION } from './version.mjs';

const chunks = [];
for await (const chunk of stdin) chunks.push(chunk);

function internalFetch(input, url, options) {
  return new Promise((resolve, reject) => {
    const child = spawn(input.phpBinary, ['-d', 'opcache.file_cache=', input.apiScript], {
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    const chunks = [];
    let size = 0;
    let error = '';
    child.stdout.on('data', (chunk) => {
      size += chunk.length;
      if (size > 8 * 1024 * 1024) child.kill();
      else chunks.push(chunk);
    });
    child.stderr.on('data', (chunk) => { error += chunk.toString().slice(0, 1024); });
    child.on('error', reject);
    child.on('close', (code) => {
      if (code !== 0 || size > 8 * 1024 * 1024) {
        reject(new Error(`Interní PHP API selhalo${error ? `: ${error.slice(0, 500)}` : ''}.`));
        return;
      }
      try {
        const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        resolve(new Response([204, 205, 304].includes(result.status) ? null : result.body, {
          status: result.status,
          headers: result.headers,
        }));
      } catch (cause) {
        reject(cause);
      }
    });
    options.signal?.addEventListener('abort', () => child.kill(), { once: true });
    child.stdin.end(JSON.stringify({
      url: String(url), method: options.method, headers: options.headers,
      body: options.body ?? '', serverParams: input.serverParams,
    }));
  });
}

try {
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  const readOnly = input.scope !== 'read_write';
  const boundSupplierId = Number.isSafeInteger(input.boundSupplierId) && input.boundSupplierId > 0
    ? input.boundSupplierId : null;
  const lockedSupplierId = Number.isSafeInteger(input.lockedSupplierId) && input.lockedSupplierId > 0
    ? input.lockedSupplierId : null;
  const withoutCompany = new Set(['whoami', 'list_suppliers']);

  if (input.operation === 'list') {
    const exposed = readOnly ? TOOLS.filter((tool) => !tool.write) : TOOLS;
    const cursor = input.cursor === undefined ? 0 : Number(input.cursor);
    if (cursor !== 0) {
      throw new Error('Neplatný kurzor seznamu nástrojů.');
    }
    stdout.write(JSON.stringify({ tools: exposed.map((tool) => ({
      name: tool.name,
      title: tool.title,
      description: tool.write
        ? `${tool.description}\n\n⚠️ Tento nástroj MĚNÍ DATA v ostré instanci.`
          + (tool.destructive ? ' Jde o NEVRATNOU operaci; vyžaduje `confirm: true`.' : '')
        : tool.description,
      inputSchema: boundSupplierId !== null || withoutCompany.has(tool.name)
        ? tool.inputSchema
        : {
            ...tool.inputSchema,
            properties: {
              ...tool.inputSchema.properties,
              supplier_id: {
                type: 'integer', minimum: 1,
                description: 'ID firmy ze seznamu list_suppliers. Práva se ověřují při každém volání.',
              },
            },
            required: [...(tool.inputSchema.required ?? []), 'supplier_id'],
          },
      annotations: {
        title: tool.title,
        readOnlyHint: !tool.write,
        destructiveHint: Boolean(tool.destructive),
        idempotentHint: !tool.write,
        openWorldHint: true,
      },
    })) }));
  } else if (input.operation === 'call') {
    const tool = TOOLS_BY_NAME.get(input.name);
    if (!tool) throw new Error(`Neznámý nástroj "${input.name}".`);
    if (tool.write && readOnly) throw new ReadOnlyError(input.name);

    let args = input.arguments ?? {};
    if (Array.isArray(args) && args.length === 0) args = {};
    if (args === null || typeof args !== 'object' || Array.isArray(args)) {
      throw new Error('Neplatné argumenty nástroje.');
    }
    const supplierId = boundSupplierId ?? args.supplier_id;
    if (boundSupplierId !== null && args.supplier_id !== undefined
      && args.supplier_id !== boundSupplierId) {
      throw new Error('Toto připojení je omezené na původně schválenou firmu. Pro přístup k dalším firmám je znovu připojte.');
    }
    if (!withoutCompany.has(tool.name) && !Number.isSafeInteger(supplierId)) {
      throw new Error('Zadejte supplier_id ze seznamu list_suppliers.');
    }
    if (!withoutCompany.has(tool.name) && supplierId < 1) {
      throw new Error('Zadejte platné supplier_id ze seznamu list_suppliers.');
    }
    if (lockedSupplierId !== null && !withoutCompany.has(tool.name) && supplierId !== lockedSupplierId) {
      throw new Error('Tato doména je omezená na jinou firmu.');
    }
    const { supplier_id: _supplierId, ...toolArguments } = args;

    const client = new MyUctoClient({
      baseUrl: input.apiUrl,
      token: input.token,
      supplierId: withoutCompany.has(tool.name) ? (lockedSupplierId ?? boundSupplierId) : supplierId,
      readOnly,
      maxConcurrent: 1,
      version: VERSION,
      fetcher: (url, options) => internalFetch(input, url, options),
    });
    const payload = await tool.run(client, toolArguments, input.name);
    stdout.write(JSON.stringify({
      content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
      structuredContent: payload && typeof payload === 'object' && !Array.isArray(payload)
        ? payload : { result: payload },
    }));
  } else {
    throw new Error('Neznámá operace MCP mostu.');
  }
} catch (error) {
  const message = error instanceof ApiError
    ? `HTTP ${error.status} (${error.code}): ${error.message}`
    : error.message;
  stdout.write(JSON.stringify({
    content: [{ type: 'text', text: message }],
    isError: true,
  }));
}
