import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as httpRequest } from 'node:http';

// PHP_BIN/PHP_EXT_DIR allow a portable PHP runtime with PDO SQLite enabled.
const php = process.env.PHP_BIN || 'php';
const phpArgs = process.env.PHP_EXT_DIR ? ['-n', '-d', `extension_dir=${process.env.PHP_EXT_DIR}`, '-d', 'extension=pdo_sqlite'] : [];
const root = fileURLToPath(new URL('../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'fedesvin-stats-'));
const env = { ...process.env, DB_PATH: join(directory, 'test.sqlite'), COUNTER_SECRET: 'test-only-secret-that-is-at-least-32-characters' };
const sql = (query) => execFileSync(php, [...phpArgs, '-r', '$db = new PDO("sqlite:" . getenv("DB_PATH")); $db->exec($argv[1]);', query], { env });
const server = spawn(php, [...phpArgs, '-S', '127.0.0.1:18089', '-t', root], { env, stdio: ['ignore', 'ignore', 'pipe'] });
let errors = '';
server.stderr.on('data', (data) => { errors += data; });
const request = (path, host = 'fedesvin.dk', options = {}) => new Promise((resolve, reject) => {
  const body = options.body?.toString();
  const req = httpRequest(`http://127.0.0.1:18089${path}`, {
    method: options.method || 'GET',
    headers: { Host: host, 'User-Agent': 'StatsIntegrationBrowser/1.0',
      ...(body ? { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) } : {}),
      ...options.headers },
  }, (res) => {
    const chunks = [];
    res.on('data', (chunk) => chunks.push(chunk));
    res.on('end', () => resolve(new Response(res.statusCode === 204 ? null : Buffer.concat(chunks), { status: res.statusCode, headers: res.headers })));
  });
  req.on('error', reject);
  req.end(body);
});
const getToken = async (host) => {
  const html = await (await request('/', host)).text();
  return html.match(/name="counter-token" content="([^"]+)"/)[1];
};
const post = (host, token, headers = {}) => request('/counter', host, { method: 'POST', body: new URLSearchParams({ token }), headers });

try {
  // Seed the legacy schema to verify an upgrade preserves the existing counter.
  sql('CREATE TABLE stats (id INTEGER PRIMARY KEY CHECK (id = 1), visits INTEGER NOT NULL DEFAULT 0); INSERT INTO stats VALUES (1, 123);');
  let ready = false;
  for (let attempt = 0; attempt < 50; attempt++) {
    try { ready = (await request('/health')).ok; } catch {}
    if (ready) break;
    await new Promise((done) => setTimeout(done, 100));
  }
  assert.ok(ready, errors);
  let html = await (await request('/stats')).text();
  assert.match(html, /Hitlisten venter/);
  assert.match(html, /<dd>123<\/dd>/);
  assert.doesNotMatch(html, /counter-token|visitor_hash|ip_hash|test-only-secret/);
  const kasper = await getToken('kasper.fedesvin.dk');
  const morten = await getToken('morten.fedesvin.dk');
  assert.equal((await post('morten.fedesvin.dk', kasper)).status, 403, 'Token must be bound to its subdomain');
  assert.equal((await post('kasper.fedesvin.dk', kasper, { 'User-Agent': 'Googlebot' })).status, 204);
  assert.equal((await request('/counter')).status, 405);
  assert.equal((await (await post('kasper.fedesvin.dk', kasper)).json()).visits, 124);
  const duplicates = await Promise.all(Array.from({ length: 5 }, () => post('kasper.fedesvin.dk', kasper)));
  for (const response of duplicates) assert.equal((await response.json()).visits, 124);
  assert.equal((await (await post('morten.fedesvin.dk', morten)).json()).visits, 124, 'Global dedupe spans domains');
  html = await (await request('/stats/')).text();
  assert.match(html, /<dd>2<\/dd>/, 'Both subdomains get one visit');
  assert.equal((html.match(/class="stats-rank"/g) || []).length, 2);
  assert.ok(html.indexOf('>Kasper<span>') < html.indexOf('>Morten<span>'), 'Ties sort alphabetically');
  // A stats page has no token and must not increment its own subdomain.
  assert.doesNotMatch(await (await request('/stats', 'stats.fedesvin.dk')).text(), /counter-token|<script/);
  sql('UPDATE counter_visitors SET last_seen = last_seen - 28801; UPDATE counter_subdomain_visitors SET last_seen = last_seen - 28801;');
  assert.equal((await (await post('kasper.fedesvin.dk', kasper)).json()).visits, 125, 'Count again after eight hours');
  sql(`WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<25)
    INSERT INTO subdomain_stats SELECT 'test-' || x, 10+x, unixepoch(), unixepoch() FROM n;`);
  html = await (await request('/stats')).text();
  assert.equal((html.match(/class="stats-rank"/g) || []).length, 20);
  assert.ok(html.indexOf('>Test-25<span>') < html.indexOf('>Test-24<span>'));
  assert.doesNotMatch(html, />Test-1<span>/);
  assert.equal((await request('/stats.css')).status, 200);
  const rateResponses = await Promise.all(Array.from({ length: 35 }, () => post('kasper.fedesvin.dk', kasper)));
  assert.ok(rateResponses.some((response) => response.status === 429));
  assert.ok(rateResponses.every((response) => [200, 429].includes(response.status)));
  assert.doesNotMatch(errors, /Fatal error|Warning:/);
  console.log('PASS: legacy migration, empty stats, GET/bot filtering, host-bound tokens, dedupe, eight-hour expiry, top 20 ordering, public output, CSS and rate limiting.');
} finally {
  server.kill();
  await new Promise((done) => server.exitCode !== null ? done() : server.once('exit', done));
  rmSync(directory, { recursive: true, force: true });
}
