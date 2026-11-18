/*
 * GLOBO CENITAL - SERVIDOR (punto de entrada)
 * ------------------------------------------------------------------
 * Solo HTTP (sin WebSockets) y sin librerías externas. Solo hace falta Node.js.
 *
 * Estructura:
 *   server.js   -> este archivo: servidor HTTP (reparte la web y recibe la API)
 *   private/    -> lógica del juego. NUNCA se envía al navegador.
 *   public/     -> lo que descarga el navegador (html, css, js del cliente)
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const { PORT, LOGIN_URL, ACCOUNT_URL } = require('./private/config');
const { api } = require('./private/api');
const { parseCookies } = require('./private/game');
const { userFromToken, cookieFor, userFromCookie } = require('./private/auth');

const PUBLIC_DIR = path.join(__dirname, 'public');
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
};

http.createServer((req, res) => {
  const url = req.url.split('?')[0];

  if (url === '/health') {
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
    return res.end('ok');
  }

  // ---- Llegada desde el login (PHP): /?token=... -> guardamos cookie "auth" y limpiamos la URL ----
  const tokenUser = userFromToken(new URLSearchParams(req.url.split('?')[1] || '').get('token'));
  if (tokenUser) {
    res.writeHead(302, { 'Set-Cookie': `auth=${cookieFor(tokenUser)}; Path=/; HttpOnly; Secure; SameSite=Lax`, Location: '/' });
    return res.end();
  }

  // ---- Identificamos al jugador por su cookie "auth". Sin login válido, fuera ----
  const user = userFromCookie(parseCookies(req.headers.cookie).auth);
  if (!user) {
    if (url.startsWith('/api/')) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ err: 'Sesión no iniciada' }));
    }
    res.writeHead(302, { Location: LOGIN_URL });
    return res.end();
  }
  const pid = 'u_' + user;   // el prefijo evita claves peligrosas tipo "__proto__" en el ranking

  // ---- Quién soy y adónde vuelvo (lo usa el cliente para el nombre y el botón "Mi cuenta") ----
  if (url === '/api/me') {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    return res.end(JSON.stringify({ name: user, account: ACCOUNT_URL }));
  }

  // ---- API del juego (POST /api/...) ----
  if (req.method === 'POST' && url.startsWith('/api/')) {
    let raw = '';
    req.on('data', (chunk) => { raw += chunk; if (raw.length > 2000) req.destroy(); });
    req.on('end', () => {
      let body = {};
      try { body = JSON.parse(raw || '{}'); } catch (e) { /* cuerpo inválido: lo tratamos como vacío */ }
      body.name = user;   // el nombre en el juego es siempre el del login (el cliente no lo elige)
      const result = api(url.slice(5), pid, body, Date.now());
      res.writeHead(result ? 200 : 404, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(result || { err: 'no existe' }));
    });
    return;
  }

  // ---- Archivos estáticos: SOLO desde la carpeta public ----
  let urlPath;
  try { urlPath = decodeURIComponent(url); } catch (e) { res.writeHead(400); return res.end('400'); }
  if (urlPath === '/') urlPath = '/index.html';
  const file = path.normalize(path.join(PUBLIC_DIR, urlPath));
  if (!file.startsWith(PUBLIC_DIR + path.sep)) {   // protege contra rutas tipo /../server.js
    res.writeHead(403); return res.end('403');
  }
  fs.readFile(file, (err, data) => {
    if (err) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); return res.end('404'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
    res.end(data);
  });
}).listen(PORT, '0.0.0.0', () => console.log('Juego escuchando al port ' + PORT));
