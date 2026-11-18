# Desplegament de Globo Cenital a Render

El projecte s'ha de desplegar com a **dos Web Services** dins del mateix repositori:

1. PHP + Apache (Docker), que serveix el login.
2. Node.js, que serveix el joc.

No cal afegir cap base de dades externa. SQLite es crea automàticament a `/tmp/users.db` i és efímera.

## 0. Abans de pujar el repositori

- Revoca la clau antiga de Brevo que hi havia al ZIP original i crea'n una de nova.
- No pugis `private/users.db`, `private/game_secret.txt`, cap `.env` ni cap clau API.
- Puja el contingut d'aquesta carpeta a l'arrel d'un repositori GitHub.

## 1. Crea un secret compartit

Genera un secret aleatori localment amb:

```bash
openssl rand -hex 32
```

Copia el resultat temporalment. El mateix valor s'ha de posar als dos serveis Render com a `GAME_SHARED_SECRET`. No el pugis a GitHub ni el comparteixis.

## 2. Servei PHP

A Render: **New + → Web Service**, connecta el repositori i configura:

- Runtime / Language: `Docker`
- Dockerfile Path: `./Dockerfile`
- Root Directory: buit (arrel del repositori)
- Health Check Path: `/?page=login` (opcional)

Variables d'entorn:

| Key | Value |
|---|---|
| `PORT` | `10000` |
| `DB_PATH` | `/tmp/users.db` |
| `GAME_URL` | `https://NOM-DEL-SERVEI-JOC.onrender.com` |
| `GAME_SHARED_SECRET` | el secret generat al pas 1 |
| `BREVO_API_KEY` | la nova clau de Brevo |
| `BREVO_SENDER_EMAIL` | el correu de remitent verificat a Brevo |
| `BREVO_SENDER_NAME` | `Globo Cenital` |

Desplega el servei. La URL pública serà semblant a `https://globo-login.onrender.com`.

## 3. Servei Node.js

A Render: **New + → Web Service**, selecciona el mateix repositori i configura:

- Runtime / Language: `Node`
- Root Directory: `joc`
- Build Command: `npm install`
- Start Command: `npm start`
- Health Check Path: `/health`

Variables d'entorn:

| Key | Value |
|---|---|
| `PORT` | Render l'assigna automàticament; no cal definir-lo manualment |
| `GAME_SHARED_SECRET` | exactament el mateix secret del servei PHP |
| `ACCOUNT_URL` | URL pública completa del servei PHP, acabada en `/` |
| `LOGIN_URL` | URL del login PHP, per exemple `https://globo-login.onrender.com/?page=login` |
| `LEADERBOARD_PATH` | `/tmp/leaderboard.json` |

Desplega el servei.

## 4. Revisa les URL

Quan tinguis les dues URL definitives:

- Al servei PHP, `GAME_URL` ha de ser la URL del servei Node.
- Al servei Node, `ACCOUNT_URL` i `LOGIN_URL` han de ser les URL del servei PHP.

Si canvies alguna variable, desa-la i torna a desplegar el servei corresponent.

## 5. Prova

1. Obre la URL PHP i registra un compte.
2. Comprova que arriba el correu de verificació.
3. Verifica el correu i inicia sessió.
4. Prem «Jugar al Globo cenital».
5. Obre el joc en un altre navegador/perfil i entra amb un segon compte per provar una partida de dos jugadors.
6. Comprova que «Tornar al compte» et porta al servei PHP.

## Notes

- Render utilitza un sistema de fitxers efímer per defecte. Els comptes SQLite i el ranking es poden perdre en reinicis o desplegaments, tal com es vol per a aquesta pràctica.
- Els serveis gratuïts poden quedar aturats per inactivitat i tardar a despertar-se en la primera visita.
- El login PHP necessita la clau Brevo per enviar codis de verificació. Sense `BREVO_API_KEY`, el registre amb verificació per correu no funcionarà.
- El secret `GAME_SHARED_SECRET` ha de ser idèntic als dos serveis; si no, el joc rebutjarà els tokens del login.
