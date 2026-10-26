import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as httpRequest } from 'node:http';

// PHP_BIN/PHP_EXT_DIR allow a portable PHP runtime with PDO SQLite enabled.
const php = process.env.PHP_BIN || 'php';
const phpArgs = process.env.PHP_EXT_DIR ? ['-n', '-d', `extension_dir=${process.env.PHP_EXT_DIR}`, '-d', 'extension=pdo_sqlite', '-d', 'extension=gd'] : [];
const root = fileURLToPath(new URL('../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'fedesvin-stats-'));
const env = { ...process.env, DB_PATH: join(directory, 'test.sqlite'), COUNTER_SECRET: 'test-only-secret-that-is-at-least-32-characters', GUESTBOOK_ADMIN_USER: 'test-admin', GUESTBOOK_ADMIN_PASSWORD: 'test-guestbook-password' };
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
  sql(`CREATE TABLE stats (id INTEGER PRIMARY KEY CHECK (id = 1), visits INTEGER NOT NULL DEFAULT 0); INSERT INTO stats VALUES (1, 123);
    CREATE TABLE shortlinks (code TEXT PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
    INSERT INTO shortlinks (code, name) VALUES ('abcdef123456', 'morten');`);
  let ready = false;
  for (let attempt = 0; attempt < 50; attempt++) {
    try { ready = (await request('/health')).ok; } catch {}
    if (ready) break;
    await new Promise((done) => setTimeout(done, 100));
  }
  assert.ok(ready, errors);
  assert.equal((await request('/s/abcdef123456')).headers.get('location'), 'https://morten.fedesvin.dk/p/abcdef123456', 'Legacy shortlinks survive migration');
  const greeting = 'Du tog det sidste kage igen! <script>alert("øf")</script>';
  const created = await request('/shortlink', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ name: 'øjvind', message: greeting }) });
  assert.equal(created.status, 303);
  const personalPath = created.headers.get('location');
  assert.match(personalPath, /^\/s\/[a-f0-9]{12}$/);
  const personalCode = personalPath.split('/').at(-1);
  const redirect = await request(personalPath);
  assert.equal(redirect.headers.get('location'), `https://oejvind.fedesvin.dk/p/${personalCode}`);
  const personalPage = await request(`/p/${personalCode}`, 'oejvind.fedesvin.dk');
  const personalHtml = await personalPage.text();
  assert.match(personalHtml, /Hej Øjvind!/);
  assert.match(personalHtml, /class="personal-message"/);
  assert.match(personalHtml, /&lt;script&gt;alert\(&quot;øf&quot;\)&lt;\/script&gt;/);
  assert.doesNotMatch(personalHtml, /<script>alert/);
  assert.match(personalHtml, new RegExp(`property="og:image" content="https://fedesvin.dk/share-image.png\\?code=${personalCode}&amp;art=\\d+"`));
  assert.match(personalHtml, /name="twitter:card" content="summary_large_image"/);
  assert.doesNotMatch(personalHtml, /data-pig-sound/, 'Pig sound toggle is removed');
  const otherDomain = await (await request(`/p/${personalCode}`, 'kasper.fedesvin.dk')).text();
  assert.doesNotMatch(otherDomain, /class="personal-message"/, 'Do not attach a message to a different person');
  assert.match(await (await request(`/?s=${personalCode}`, 'oejvind.fedesvin.dk')).text(), /class="personal-message"/, 'Old query links remain supported');
  const png = await request(`/share-image.png?code=${personalCode}&v=business&download=1`);
  assert.equal(png.status, 200);
  assert.equal(png.headers.get('content-type'), 'image/png');
  assert.match(png.headers.get('content-disposition'), /attachment; filename="fedesvin-oejvind.png"/);
  const bytes = Buffer.from(await png.arrayBuffer());
  assert.equal(bytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
  assert.equal(bytes.readUInt32BE(16), 1200);
  assert.equal(bytes.readUInt32BE(20), 630);
  for (const variant of ['classic', 'rose', 'mint', 'party', 'royal', 'goth', 'pirate', 'space']) {
    const variantImage = await request(`/share-image.png?code=${personalCode}&v=${variant}`);
    assert.equal(variantImage.status, 200, `${variant} must render`);
    const variantBytes = Buffer.from(await variantImage.arrayBuffer());
    assert.equal(variantBytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.notDeepEqual(variantBytes, bytes, `${variant} must differ from business`);
  }
  assert.equal((await request('/share-image.png?code=000000000000')).status, 404);
  assert.equal((await request('/s/000000000000')).status, 404);
  for (const asset of ['/extras.css', '/app.js']) assert.equal((await request(asset)).status, 200);
  let html = await (await request('/stats')).text();
  assert.match(html, /Hitlisten venter/);
  assert.match(html, /<dd>123<\/dd>/);
  assert.doesNotMatch(html, /counter-token|visitor_hash|ip_hash|test-only-secret/);
  let guestbook = await request('/gaestebog');
  let guestbookHtml = await guestbook.text();
  assert.equal(guestbook.status, 200);
  assert.match(guestbookHtml, /Første øf i gæstebogen!/);
  assert.match(guestbookHtml, /<strong>Codex<\/strong>/);
  assert.match(guestbookHtml, /NETSCAPE 4\.0/);
  const guestbookToken = guestbookHtml.match(/name="token" value="([^"]+)"/)[1];
  const guestMessage = 'Hej gris! <script>alert("øf")</script>';
  assert.match(guestbookHtml, /name="js_check" value=""/, 'Guestbook includes a JavaScript check');
  const noJavaScriptPost = await request('/gaestebog', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ token: guestbookToken, author: 'bot', message: 'spam', company: '' }) });
  assert.equal(noJavaScriptPost.headers.get('location'), '/gaestebog?status=browser-check#indlaeg', 'Posts without the JavaScript check are rejected');
  const tooFastPost = await request('/gaestebog', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ token: guestbookToken, js_check: 'ready', author: 'bot', message: 'spam', company: '' }) });
  assert.equal(tooFastPost.headers.get('location'), '/gaestebog?status=too-fast#indlaeg', 'Immediate submissions are rejected');
  await new Promise((done) => setTimeout(done, 2200));
  const guestPost = await request('/gaestebog', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ token: guestbookToken, js_check: 'ready', author: 'testsvin', message: guestMessage, company: '' }) });
  assert.equal(guestPost.status, 303);
  guestbookHtml = await (await request('/gaestebog')).text();
  assert.match(guestbookHtml, /Hej gris! &lt;script&gt;alert\(&quot;øf&quot;\)&lt;\/script&gt;/);
  assert.doesNotMatch(guestbookHtml, /<script>alert\("øf"\)<\/script>/);
  assert.equal((await request('/gaestebog/admin')).status, 401, 'Admin interface requires Basic Auth');
  const adminAuthorization = `Basic ${Buffer.from('test-admin:test-guestbook-password').toString('base64')}`;
  const adminPage = await request('/gaestebog/admin', 'fedesvin.dk', { headers: { Authorization: adminAuthorization } });
  assert.equal(adminPage.status, 200);
  const adminHtml = await adminPage.text();
  const csrf = adminHtml.match(/name="csrf" value="([^"]+)"/)[1];
  const guestEntryId = adminHtml.match(/name="id" value="(\d+)"/)[1];
  const sessionCookie = adminPage.headers.get('set-cookie')?.split(';', 1)[0];
  assert.ok(sessionCookie, 'Admin page creates a CSRF session');
  const invalidAdminDelete = await request('/gaestebog/admin', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ csrf: 'invalid', id: guestEntryId, action: 'delete' }), headers: { Authorization: adminAuthorization, Cookie: sessionCookie } });
  assert.equal(invalidAdminDelete.status, 400, 'Admin mutations require a valid CSRF token');
  const editedMessage = 'Rettet hilsen <script>alert("redigeret")</script>';
  const adminUpdate = await request('/gaestebog/admin', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ csrf, id: guestEntryId, action: 'update', author: 'Redigeret gris', message: editedMessage }), headers: { Authorization: adminAuthorization, Cookie: sessionCookie } });
  assert.equal(adminUpdate.status, 303, 'Admin can edit guestbook entries');
  guestbookHtml = await (await request('/gaestebog')).text();
  assert.match(guestbookHtml, /<strong>Redigeret gris<\/strong>/);
  assert.match(guestbookHtml, /Rettet hilsen &lt;script&gt;alert\(&quot;redigeret&quot;\)&lt;\/script&gt;/, 'Edited content is HTML-escaped');
  const adminDelete = await request('/gaestebog/admin', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ csrf, id: guestEntryId, action: 'delete' }), headers: { Authorization: adminAuthorization, Cookie: sessionCookie } });
  assert.equal(adminDelete.status, 303);
  guestbookHtml = await (await request('/gaestebog')).text();
  assert.doesNotMatch(guestbookHtml, /Rettet hilsen &lt;script&gt;/, 'Admin can delete edited guestbook entries');
  assert.match(guestbookHtml, /Første øf i gæstebogen!/, 'Seeded first entry remains');
  const seedAdminPage = await request('/gaestebog/admin', 'fedesvin.dk', { headers: { Authorization: adminAuthorization, Cookie: sessionCookie } });
  const seedAdminHtml = await seedAdminPage.text();
  const seedCsrf = seedAdminHtml.match(/name="csrf" value="([^"]+)"/)[1];
  const deleteSeed = await request('/gaestebog/admin', 'fedesvin.dk', { method: 'POST', body: new URLSearchParams({ csrf: seedCsrf, id: '1', action: 'delete' }), headers: { Authorization: adminAuthorization, Cookie: sessionCookie } });
  assert.equal(deleteSeed.status, 303, 'Admin can delete the seeded welcome entry');
  guestbookHtml = await (await request('/gaestebog')).text();
  assert.doesNotMatch(guestbookHtml, /Første øf i gæstebogen!/, 'Deleted welcome entry is not re-created on the next request');
  assert.equal((await request('/guestbook.css')).status, 200);
  assert.equal((await request('/guestbook.php')).status, 404, 'PHP view cannot be opened directly');
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
  assert.doesNotMatch(await (await request('/stats', 'stats.fedesvin.dk')).text(), /counter-token/);
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
  console.log('PASS: legacy migration, personal messages and escaping, shortlink redirects/reloads, OG metadata, PNG download, guestbook seed/submission/escaping, admin auth and deletion, stats, GET/bot filtering, host-bound tokens, dedupe, eight-hour expiry, top 20 ordering, public output, CSS and rate limiting.');
} finally {
  server.kill();
  await new Promise((done) => server.exitCode !== null ? done() : server.once('exit', done));
  rmSync(directory, { recursive: true, force: true });
}
