/**
 * Ověření argumentů nástroje proti jeho `inputSchema` dřív, než se nástroj spustí.
 *
 * MCP klient schéma jen nabízí modelu, nevynucuje ho. Bez téhle kontroly by model
 * ovlivněný obsahem dokumentu mohl poslat třeba `{"id": "../../invoices/123"}`
 * a nástroj pro jeden druh dokladu by po vyhodnocení tečkových segmentů v URL
 * sáhl na úplně jiný. Kontrola proto běží centrálně v lokálním serveru
 * (`index.mjs`) i v serverovém MCP (`hosted-core.mjs`).
 *
 * Validátor pokrývá jen podmnožinu JSON Schema, kterou katalog nástrojů používá
 * (`SUPPORTED_KEYWORDS`); test hlídá, že nové schéma nesáhne po klíčovém slově,
 * které by se tady tiše přeskočilo.
 *
 * Kvůli kompatibilitě s klienty, kteří posílají čísla jako text:
 * - `integer` přijme i řetězec ze samých číslic a převede ho na číslo,
 * - `number` přijme i řetězec s platným konečným číslem a nechá ho beze změny,
 *   protože nástroje s ním tak pracovaly i dřív.
 */

export const SUPPORTED_KEYWORDS = new Set([
  'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
  'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
  'minLength', 'maxLength', 'pattern', 'format',
  'minItems', 'maxItems', 'uniqueItems',
  // Jen popisné, na platnost nemají vliv.
  'description', 'title', 'default', 'examples',
]);

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const INTEGER_TEXT = /^-?\d+$/;
const NUMBER_TEXT = /^-?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;
const MAX_REPORTED = 5;

export class ToolArgumentsError extends Error {
  constructor(tool, problems) {
    const shown = problems.slice(0, MAX_REPORTED);
    const more = problems.length > shown.length ? `\n  - … a dalších ${problems.length - shown.length}` : '';
    super(`Neplatné argumenty nástroje ${tool}:\n  - ${shown.join('\n  - ')}${more}\n`
      + 'Oprav argumenty podle schématu nástroje a zavolej ho znovu.');
    this.name = 'ToolArgumentsError';
    this.problems = problems;
  }
}

/**
 * Vrátí argumenty s případně převedenými hodnotami, nebo vyhodí ToolArgumentsError.
 *
 * @param {{name: string, inputSchema: object}} tool
 * @param {unknown} args
 */
export function validateToolArguments(tool, args) {
  const problems = [];
  const value = check(tool.inputSchema ?? { type: 'object' }, args ?? {}, '', problems);
  if (problems.length > 0) throw new ToolArgumentsError(tool.name, problems);
  return value;
}

const label = (path) => (path === '' ? 'argumenty' : `\`${path}\``);

function describe(value) {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'pole';
  if (typeof value === 'string') {
    const shown = value.length > 40 ? `${value.slice(0, 40)}…` : value;
    return `řetězec ${JSON.stringify(shown)}`;
  }
  if (typeof value === 'object') return 'objekt';
  return `${typeof value === 'number' ? 'číslo' : typeof value} ${String(value)}`;
}

const TYPE_NAMES = {
  null: 'null', boolean: 'true/false', string: 'text', integer: 'celé číslo',
  number: 'číslo', object: 'objekt', array: 'pole',
};

/**
 * Hodnota odpovídající typu (případně převedená), nebo `undefined`, když typu
 * neodpovídá. `null` se vrací jako hodnota zabalená v poli, aby šel odlišit.
 */
function matchType(type, value) {
  switch (type) {
    case 'null':
      return value === null ? [null] : undefined;
    case 'boolean':
      return typeof value === 'boolean' ? [value] : undefined;
    case 'string':
      return typeof value === 'string' ? [value] : undefined;
    case 'integer':
      if (typeof value === 'number') return Number.isSafeInteger(value) ? [value] : undefined;
      if (typeof value === 'string' && INTEGER_TEXT.test(value)) {
        const n = Number(value);
        return Number.isSafeInteger(n) ? [n] : undefined;
      }
      return undefined;
    case 'number':
      if (typeof value === 'number') return Number.isFinite(value) ? [value] : undefined;
      if (typeof value === 'string' && NUMBER_TEXT.test(value) && Number.isFinite(Number(value))) return [value];
      return undefined;
    case 'object':
      return value !== null && typeof value === 'object' && !Array.isArray(value) ? [value] : undefined;
    case 'array':
      return Array.isArray(value) ? [value] : undefined;
    default:
      return [value];
  }
}

function check(schema, input, path, problems) {
  if (!schema || typeof schema !== 'object') return input;

  let value = input;
  if (schema.type !== undefined) {
    const types = Array.isArray(schema.type) ? schema.type : [schema.type];
    let matched;
    for (const type of types) {
      matched = matchType(type, input);
      if (matched !== undefined) break;
    }
    if (matched === undefined) {
      problems.push(`${label(path)}: očekává se ${types.map((t) => TYPE_NAMES[t] ?? t).join(' nebo ')}, zadáno ${describe(input)}.`);
      return input;
    }
    [value] = matched;
  }

  if (Array.isArray(schema.enum) && !schema.enum.some((option) => option === value)) {
    problems.push(`${label(path)}: povolené hodnoty jsou ${schema.enum.map((o) => JSON.stringify(o)).join(', ')}, zadáno ${describe(value)}.`);
    return value;
  }

  if (typeof value === 'string') checkString(schema, value, path, problems);
  if (typeof value === 'number' || (typeof value === 'string' && isNumericSchema(schema) && NUMBER_TEXT.test(value))) {
    checkNumber(schema, Number(value), path, problems);
  }
  if (Array.isArray(value)) return checkArray(schema, value, path, problems);
  if (value !== null && typeof value === 'object') return checkObject(schema, value, path, problems);
  return value;
}

const isNumericSchema = (schema) => {
  const types = Array.isArray(schema.type) ? schema.type : [schema.type];
  return types.includes('number') && !types.includes('string');
};

function checkString(schema, value, path, problems) {
  // Řetězec, který prošel jen jako číslo, nemá smysl měřit délkou ani vzorem.
  if (isNumericSchema(schema)) return;
  const length = [...value].length;
  if (Number.isInteger(schema.minLength) && length < schema.minLength) {
    problems.push(`${label(path)}: musí mít aspoň ${schema.minLength} znaků.`);
  }
  if (Number.isInteger(schema.maxLength) && length > schema.maxLength) {
    problems.push(`${label(path)}: smí mít nejvýš ${schema.maxLength} znaků (má ${length}).`);
  }
  if (typeof schema.pattern === 'string' && !new RegExp(schema.pattern).test(value)) {
    problems.push(`${label(path)}: hodnota ${describe(value)} neodpovídá tvaru ${schema.pattern}.`);
  }
  if (schema.format === 'date' && !DATE.test(value)) {
    problems.push(`${label(path)}: očekává se datum ve tvaru RRRR-MM-DD, zadáno ${describe(value)}.`);
  }
}

function checkNumber(schema, n, path, problems) {
  if (typeof schema.minimum === 'number' && n < schema.minimum) {
    problems.push(`${label(path)}: musí být nejméně ${schema.minimum}, zadáno ${n}.`);
  }
  if (typeof schema.maximum === 'number' && n > schema.maximum) {
    problems.push(`${label(path)}: smí být nejvýš ${schema.maximum}, zadáno ${n}.`);
  }
  if (typeof schema.exclusiveMinimum === 'number' && n <= schema.exclusiveMinimum) {
    problems.push(`${label(path)}: musí být větší než ${schema.exclusiveMinimum}, zadáno ${n}.`);
  }
  if (typeof schema.exclusiveMaximum === 'number' && n >= schema.exclusiveMaximum) {
    problems.push(`${label(path)}: musí být menší než ${schema.exclusiveMaximum}, zadáno ${n}.`);
  }
}

function checkArray(schema, value, path, problems) {
  if (Number.isInteger(schema.minItems) && value.length < schema.minItems) {
    problems.push(`${label(path)}: musí mít aspoň ${schema.minItems} položek.`);
  }
  if (Number.isInteger(schema.maxItems) && value.length > schema.maxItems) {
    problems.push(`${label(path)}: smí mít nejvýš ${schema.maxItems} položek (má ${value.length}).`);
  }
  const items = schema.items && typeof schema.items === 'object' && !Array.isArray(schema.items)
    ? value.map((item, i) => check(schema.items, item, `${path}[${i}]`, problems))
    : value;
  if (schema.uniqueItems === true) {
    const seen = new Set();
    for (const item of items) {
      const key = JSON.stringify(item);
      if (seen.has(key)) {
        problems.push(`${label(path)}: položky se nesmí opakovat (${key}).`);
        break;
      }
      seen.add(key);
    }
  }
  return items;
}

function checkObject(schema, value, path, problems) {
  const properties = schema.properties && typeof schema.properties === 'object' ? schema.properties : {};
  const prefix = path === '' ? '' : `${path}.`;

  for (const key of Array.isArray(schema.required) ? schema.required : []) {
    if (value[key] === undefined) problems.push(`\`${prefix}${key}\`: chybí povinný parametr.`);
  }

  const result = {};
  // defineProperty místo přiřazení: klíč `__proto__` z JSON by jinak přepsal prototyp.
  const put = (key, item) => Object.defineProperty(result, key, {
    value: item, enumerable: true, writable: true, configurable: true,
  });
  for (const [key, item] of Object.entries(value)) {
    if (item === undefined) continue;
    if (Object.hasOwn(properties, key)) {
      put(key, check(properties[key], item, `${prefix}${key}`, problems));
    } else if (schema.additionalProperties === false) {
      problems.push(`\`${prefix}${key}\`: neznámý parametr${Object.keys(properties).length ? ` (povolené: ${Object.keys(properties).join(', ')})` : ''}.`);
    } else if (schema.additionalProperties && typeof schema.additionalProperties === 'object') {
      put(key, check(schema.additionalProperties, item, `${prefix}${key}`, problems));
    } else {
      put(key, item);
    }
  }
  return result;
}
