/**
 * Těla požadavků a odpovědí v JSON zprávách mezi hostovaným MCP a PHP.
 *
 * JSON putuje beze změny v `body`. Binární obsah (multipart s nahrávaným
 * souborem, stažené PDF) by v JSON řetězci nepřežil, proto jde jako base64
 * v `bodyBase64`. Druhá strana ho ověří a dekóduje (`McpInternalRequest`).
 */

export function encodeBody(body) {
  if (body instanceof Uint8Array) {
    return { bodyBase64: Buffer.from(body.buffer, body.byteOffset, body.byteLength).toString('base64') };
  }
  return { body: body ?? '' };
}

export function decodeBody(message) {
  if ([204, 205, 304].includes(message.status)) return null;
  return typeof message.bodyBase64 === 'string'
    ? Buffer.from(message.bodyBase64, 'base64')
    : message.body;
}
