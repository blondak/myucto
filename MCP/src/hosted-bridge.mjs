import { stdin, stdout } from 'node:process';
import { spawn } from 'node:child_process';

import { hostedError, runHosted } from './hosted-core.mjs';

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

let result;
try {
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  result = await runHosted(input, (url, options) => internalFetch(input, url, options));
} catch (error) {
  result = hostedError(error);
}
stdout.write(JSON.stringify(result));
