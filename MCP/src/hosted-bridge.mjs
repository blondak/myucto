import { stdin, stdout } from 'node:process';
import { spawn } from 'node:child_process';

import { ApiError, MyUctoClient, ReadOnlyError } from './client.mjs';
import { TOOLS, TOOLS_BY_NAME } from './tools.mjs';
import { VERSION } from './version.mjs';

const chunks = [];
for await (const chunk of stdin) chunks.push(chunk);

function internalFetch(input, url, options) {
  return new Promise((resolve, reject) => {
    const child = spawn(input.phpBinary, [input.apiScript], { stdio: ['pipe', 'pipe', 'pipe'] });
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

  if (input.operation === 'list') {
    const exposed = readOnly ? TOOLS.filter((tool) => !tool.write) : TOOLS;
    const offset = input.cursor === undefined ? 0 : Number(input.cursor);
    if (!Number.isInteger(offset) || offset < 0 || offset >= exposed.length) {
      throw new Error('Neplatný kurzor seznamu nástrojů.');
    }
    const pageSize = 30;
    const page = exposed.slice(offset, offset + pageSize);
    stdout.write(JSON.stringify({ tools: page.map((tool) => ({
      name: tool.name,
      title: tool.title,
      description: tool.write
        ? `${tool.description}\n\n⚠️ Tento nástroj MĚNÍ DATA v ostré instanci.`
          + (tool.destructive ? ' Jde o NEVRATNOU operaci; vyžaduje `confirm: true`.' : '')
        : tool.description,
      inputSchema: tool.inputSchema,
      annotations: {
        title: tool.title,
        readOnlyHint: !tool.write,
        destructiveHint: Boolean(tool.destructive),
        idempotentHint: !tool.write,
        openWorldHint: true,
      },
    })), ...(offset + pageSize < exposed.length ? { nextCursor: String(offset + pageSize) } : {}) }));
  } else if (input.operation === 'call') {
    const tool = TOOLS_BY_NAME.get(input.name);
    if (!tool) throw new Error(`Neznámý nástroj "${input.name}".`);
    if (tool.write && readOnly) throw new ReadOnlyError(input.name);

    const client = new MyUctoClient({
      baseUrl: input.apiUrl,
      token: input.token,
      supplierId: input.supplierId,
      readOnly,
      maxConcurrent: 1,
      version: VERSION,
      fetcher: (url, options) => internalFetch(input, url, options),
    });
    const payload = await tool.run(client, input.arguments ?? {}, input.name);
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
