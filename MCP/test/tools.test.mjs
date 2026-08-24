import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS, TOOLS_BY_NAME } from '../src/tools.mjs';

class FakeClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    return this.responses[`${method} ${path}`] ?? { ok: true };
  }

  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return this.response('GET', path);
  }

  async post(path, body, tool) {
    this.calls.push({ method: 'POST', path, body, tool });
    return this.response('POST', path);
  }

  async put(path, body, tool) {
    this.calls.push({ method: 'PUT', path, body, tool });
    return this.response('PUT', path);
  }

  async patch(path, body, tool) {
    this.calls.push({ method: 'PATCH', path, body, tool });
    return this.response('PATCH', path);
  }

  async del(path, tool, query) {
    this.calls.push({ method: 'DELETE', path, query, tool });
    return this.response('DELETE', path);
  }
}

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

test('katalog má unikátní názvy a nové domény', () => {
  assert.equal(new Set(TOOLS.map(({ name }) => name)).size, TOOLS.length);
  for (const name of [
    'save_project', 'project_profitability', 'get_document', 'link_document',
    'save_logbook_car', 'save_logbook_trip', 'save_logbook_fueling', 'logbook_summary',
  ]) {
    assert.ok(TOOLS_BY_NAME.has(name), name);
  }
  assert.equal(tool('project_profitability').write, false);
  assert.equal(tool('logbook_summary').write, false);
  assert.equal(tool('delete_logbook_trip').destructive, true);
});

test('úprava zakázky zachová nezadaná pole úplného PUT payloadu', async () => {
  const current = {
    client_id: 8,
    name: 'Původní zakázka',
    status: 'active',
    payment_due_days: 14,
    billing_emails: [{ email: 'billing@example.test', position: 1 }],
    billing_emails_mode: 'replace',
  };
  const client = new FakeClient({ 'GET /projects/42': current });

  await tool('save_project').run(client, { id: 42, name: 'Nový název' }, 'save_project');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/projects/42'],
    ['PUT', '/projects/42'],
  ]);
  assert.deepEqual(client.calls[1].body, { ...current, name: 'Nový název' });
});

test('nová zakázka vyžaduje klienta, název a splatnost', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_project').run(client, { name: 'Neúplná' }, 'save_project'),
    /client_id, payment_due_days/,
  );
  assert.equal(client.calls.length, 0);
});

test('detail dokumentu načte vytěžený text jen na výslovné vyžádání', async () => {
  const client = new FakeClient({
    'GET /documents/7': { id: 7, title: 'Smlouva' },
    'GET /documents/7/text': { content: 'Vytěžený text', has_more: false },
  });

  const result = await tool('get_document').run(client, {
    id: 7,
    include_text: true,
    text_offset: 100,
    text_max_chars: 5000,
  }, 'get_document');

  assert.equal(result.extracted_text.content, 'Vytěžený text');
  assert.deepEqual(client.calls[1], {
    method: 'GET',
    path: '/documents/7/text',
    query: { offset: 100, max_chars: 5000 },
    tool: 'get_document',
  });
});

test('odpojení dokumentu vyžaduje potvrzení a posílá vazbu v query', async () => {
  const preview = { id: 7, title: 'Smlouva' };
  const client = new FakeClient({ 'GET /documents/7': preview });
  const args = { id: 7, entity_type: 'project', entity_id: 42 };

  await assert.rejects(tool('unlink_document').run(client, args, 'unlink_document'), /NEPROVEDENO/);
  assert.equal(client.calls.some(({ method }) => method === 'DELETE'), false);

  await tool('unlink_document').run(client, { ...args, confirm: true }, 'unlink_document');
  assert.deepEqual(client.calls.at(-1), {
    method: 'DELETE',
    path: '/documents/7/links',
    query: { entity_type: 'project', entity_id: 42 },
    tool: 'unlink_document',
  });
});

test('AI nesmí založit jízdu bez výslovně vybrané kategorie', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_logbook_trip').run(client, {
      car_id: 3,
      trip_date: '2026-08-24',
      distance_km: 25,
    }, 'save_logbook_trip'),
    /category_id/,
  );
  assert.equal(client.calls.length, 0);

  await tool('save_logbook_trip').run(client, {
    car_id: 3,
    trip_date: '2026-08-24',
    category_id: 2,
    distance_km: 25,
    origin: 'Praha',
    destination: 'Kolín',
  }, 'save_logbook_trip');
  assert.equal(client.calls[0].path, '/logbook/trips');
});

test('úprava tankování zachová původní hodnoty', async () => {
  const current = {
    car_id: 3,
    fueled_date: '2026-08-20',
    fuel_type: 'diesel',
    quantity: 40,
    unit: 'l',
    unit_price: 38,
    amount_with_vat: 1520,
    currency: 'CZK',
    station: 'Původní stanice',
  };
  const client = new FakeClient({ 'GET /logbook/fuelings/9': current });

  await tool('save_logbook_fueling').run(client, { id: 9, station: 'Nová stanice' }, 'save_logbook_fueling');
  assert.deepEqual(client.calls[1].body, { ...current, station: 'Nová stanice' });
});

test('filtry dodavatele a nepřiřazeného vozidla patří k tankování', async () => {
  const client = new FakeClient();
  await tool('list_logbook_fuelings').run(client, {
    vendor_id: 18,
    unassigned: true,
  }, 'list_logbook_fuelings');

  assert.equal(client.calls[0].path, '/logbook/fuelings');
  assert.equal(client.calls[0].query.vendor_id, 18);
  assert.equal(client.calls[0].query.unassigned, 1);
  assert.equal(tool('list_logbook_trips').inputSchema.properties.vendor_id, undefined);
});
