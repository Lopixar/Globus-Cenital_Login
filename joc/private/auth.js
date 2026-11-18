/*
 * AUTENTICACIÓN (carpeta private). El login lo hace la web PHP de la carpeta raíz.
 * PHP envía al jugador aquí con un token firmado (HMAC) y de vida corta; lo comprobamos
 * y lo convertimos en una cookie propia "auth". El secreto se comparte con PHP
 * mediante el archivo ../../private/game_secret.txt (se crea solo si no existe).
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SECRET_FILE = path.join(__dirname, '..', '..', 'private', 'game_secret.txt');
let SECRET = process.env.GAME_SHARED_SECRET || '';
if (!SECRET) {
  if (!fs.existsSync(SECRET_FILE)) fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32).toString('hex'));
  SECRET = fs.readFileSync(SECRET_FILE, 'utf8').trim();
}

const hmac = (text) => crypto.createHmac('sha256', SECRET).update(text).digest('hex');
const same = (a, b) => {
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && crypto.timingSafeEqual(x, y);
};
const decode = (b64) => Buffer.from(b64, 'base64url').toString('utf8');

/** Token de PHP "usuario.caducidad.firma" -> nombre de usuario, o null si no es válido. */
function userFromToken(token) {
  const [u, exp, sig] = String(token || '').split('.');
  if (!u || !exp || !sig || !same(sig, hmac(u + '.' + exp)) || Date.now() / 1000 > +exp) return null;
  return decode(u) || null;
}

/** Valor de la cookie "auth" para un usuario ya verificado. */
function cookieFor(user) {
  const u = Buffer.from(user).toString('base64url');
  return u + '.' + hmac(u);
}

/** Cookie "auth" -> nombre de usuario, o null si falta o está manipulada. */
function userFromCookie(value) {
  const [u, sig] = String(value || '').split('.');
  return u && sig && same(sig, hmac(u)) ? decode(u) || null : null;
}

module.exports = { userFromToken, cookieFor, userFromCookie };
