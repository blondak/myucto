/**
 * Převod výsledku nástroje na odpověď MCP. Sdílí ho lokální server (`index.mjs`)
 * i hostovaný most (`hosted-core.mjs`), aby se soubor vracel v obou stejně.
 *
 * Běžný výsledek je JSON v textovém obsahu + `structuredContent`. Stažený soubor
 * by tak skončil v odpovědi dvakrát (text i structuredContent), a u PDF o pár
 * megabajtech to znamená zbytečně dvojnásobný přenos. Soubor proto nese obsah
 * jen jednou: jako obrázek, vložený prostředek (`resource`), nebo na výslovné
 * přání jako base64 v JSON; `structuredContent` drží jen metadata.
 */

const FILE_RESULT = Symbol.for('myucto.mcp.file-result');

const IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
const TEXT_TYPES = /^(text\/|application\/(xml|json)$|application\/[a-z0-9.+-]+\+(xml|json)$)/;

/**
 * Obálka staženého souboru pro {@see toolResult}.
 *
 * @param {{filename: string|null, content_type: string, size: number, content_base64: string}} file
 * @param {{uri: string, format?: 'resource'|'base64', meta?: object}} options
 */
export function fileResult(file, { uri, format = 'resource', meta = {} }) {
  return {
    [FILE_RESULT]: true,
    uri,
    format,
    base64: file.content_base64,
    meta: {
      ...meta,
      filename: file.filename,
      content_type: file.content_type,
      size: file.size,
    },
  };
}

export const isFileResult = (payload) => Boolean(payload && payload[FILE_RESULT] === true);

function fileContent(payload) {
  const { meta, base64, uri } = payload;
  const mimeType = meta.content_type;

  if (payload.format === 'base64') {
    return {
      content: [{ type: 'text', text: JSON.stringify({ ...meta, content_base64: base64 }) }],
      structuredContent: meta,
    };
  }

  let block;
  if (IMAGE_TYPES.has(mimeType)) {
    block = { type: 'image', data: base64, mimeType };
  } else {
    const text = TEXT_TYPES.test(mimeType) ? utf8(base64) : null;
    block = {
      type: 'resource',
      resource: text !== null ? { uri, mimeType, text } : { uri, mimeType, blob: base64 },
    };
  }
  return {
    content: [{ type: 'text', text: JSON.stringify({ ...meta, uri }, null, 2) }, block],
    structuredContent: { ...meta, uri },
  };
}

/** Text jen tehdy, když je obsah platné UTF-8 — jinak by se znaky tiše nahradily. */
function utf8(base64) {
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(Buffer.from(base64, 'base64'));
  } catch {
    return null;
  }
}

/** Odpověď nástroje: JSON v textovém obsahu + strojově čitelný `structuredContent`. */
export function toolResult(payload) {
  if (isFileResult(payload)) return fileContent(payload);
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload && typeof payload === 'object' && !Array.isArray(payload)
      ? payload
      : { result: payload },
  };
}
