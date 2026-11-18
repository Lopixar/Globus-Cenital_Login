<?php

// Cada petició executa aquest fitxer de nou.
// La cookie conté un identificador que permet recuperar les dades
// de la sessió desades al servidor.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => true, // només si serveixes per HTTPS
]);
session_start();

// valors per defecte
$template = 'home';
$db_path = getenv('DB_PATH') ?: (__DIR__ . '/../private/users.db');
$db_connection = 'sqlite:' . $db_path;
$game_url = rtrim(getenv('GAME_URL') ?: 'http://localhost:3000', '/'); // URL pública del servidor Node
$configuration = array(
    '{FEEDBACK}'          => '',
    '{LOGIN_LOGOUT_TEXT}' => 'Identificar-me',
    '{LOGIN_LOGOUT_URL}'  => '/?page=login',
    '{METHOD}'            => 'POST', // es veuen els paràmetres a l'URL i a la consola (???)
    '{REGISTER_URL}'      => '/?page=register',
    '{RECOVER_URL}'       => '/?page=recover',
    '{SITE_NAME}'         => 'La meva pàgina',
    '{PROFILE_LINKS}'     => '',
);

// -----------------------------------------------------------------
// Funcions auxiliars
// -----------------------------------------------------------------

// Obre connexió amb la BD sense trencar l'script si falla
function connectaBD(string $dsn): ?PDO
{
    try {
        $db = new PDO($dsn);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Crea l'esquema si encara no existeix (necessari amb el disc efímer de Render).
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            user_name VARCHAR(63) NOT NULL,
            user_password VARCHAR(255) NOT NULL,
            display_name VARCHAR(127),
            security_question VARCHAR(255),
            security_answer_hash VARCHAR(255),
            email VARCHAR(255),
            email_verified INTEGER NOT NULL DEFAULT 0,
            verification_code_hash VARCHAR(255),
            verification_expires INTEGER,
            verification_attempts INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS user_name_UNIQUE ON users (user_name)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS email_UNIQUE ON users (email)');
        return $db;
    } catch (PDOException $e) {
        error_log('SQLite: ' . $e->getMessage());
        return null;
    }
}

// Comprova que la contrasenya cumpleix els requisits mínims
function esContrasenyaValida(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password);
}

// Talla l'execució i envia l'usuari a fer login si no hi ha sessió oberta
function exigeixSessio(): void
{
    if (!isset($_SESSION['user_name'])) {
        header('Location: /?page=login');
        exit;
    }
}

// Retorna la fila de l'usuari (assoc array) o false si no existeix
function trobaUsuari(PDO $db, string $user_name)
{
    $sql = 'SELECT * FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $user_name);
    $query->execute();
    return $query->fetch(PDO::FETCH_ASSOC);
}

// Retorna la fila d'un usuari buscant pel correu
function trobaUsuariPerEmail(PDO $db, string $email)
{
    $sql = 'SELECT * FROM users WHERE email = :email';
    $query = $db->prepare($sql);
    $query->bindValue(':email', $email);
    $query->execute();
    return $query->fetch(PDO::FETCH_ASSOC);
}

// Secret compartit amb el joc (joc/private/auth.js) per signar el token d'entrada.
// Es crea sol la primera vegada si no existeix.
function secretJoc(): string
{
    $secret = getenv('GAME_SHARED_SECRET');
    if ($secret !== false && $secret !== '') {
        return $secret;
    }
    // Compatibilitat amb l'entorn local. A Render cal definir GAME_SHARED_SECRET.
    $fitxer = __DIR__ . '/../private/game_secret.txt';
    if (!file_exists($fitxer)) {
        file_put_contents($fitxer, bin2hex(random_bytes(32)));
    }
    return trim(file_get_contents($fitxer));
}

// Token de vida curta (60 s) amb el format "usuari.caducitat.firma".
// El joc el verifica i d'aquí treu el nom d'usuari que es mostra a la partida.
function creaTokenJoc(string $user_name): string
{
    $u = rtrim(strtr(base64_encode($user_name), '+/', '-_'), '=');
    $exp = time() + 60;
    return $u . '.' . $exp . '.' . hash_hmac('sha256', $u . '.' . $exp, secretJoc());
}

// Envia un correu a través de l'API transaccional de Brevo.
// Retorna true si Brevo l'ha acceptat (HTTP 201).
function enviaCorreuBrevo(string $destinatari, string $assumpte, string $html): bool
{
    $config_file = __DIR__ . '/../private/config.php';
    if (!file_exists($config_file)) {
        error_log('Brevo: falta private/config.php');
        return false;
    }
    $config = require $config_file;

    $payload = [
        'sender'      => [
            'name'  => $config['brevo_sender_name'],
            'email' => $config['brevo_sender_email'],
        ],
        'to'          => [['email' => $destinatari]],
        'subject'     => $assumpte,
        'htmlContent' => $html,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $config['brevo_api_key'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resposta = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resposta === false) {
        error_log('Brevo cURL: ' . curl_error($ch));
    }

    if ($status !== 201) {
        error_log("Brevo HTTP $status: $resposta");
    }
    return $status === 201;
}

// Envia el codi de verificació de 6 dígits
function enviaCodiVerificacio(string $email, string $user_name, string $codi): bool
{
    $html = '<p>Hola <b>' . htmlentities($user_name) . '</b>,</p>'
        . '<p>El teu codi de verificació és:</p>'
        . '<p style="font-size:28px;letter-spacing:6px;"><b>' . $codi . '</b></p>'
        . '<p>Caduca d\'aquí a 15 minuts. Si no has creat cap compte, ignora aquest correu.</p>';
    return enviaCorreuBrevo($email, 'El teu codi de verificació', $html);
}

// HTML del pas 1 de recuperació: només es demana el nom d'usuari
function recuperacioPas1(string $error = ''): string
{
    $feedback = $error !== '' ? '<div>' . $error . '</div>' : '';
    return $feedback . '
        <form method="POST" action="/?page=recover">
            <label for="recover_username">Nom d\'usuari</label>
            <input id="recover_username" type="text" name="user_name" required />
            <input type="submit" name="recover_lookup" value="Continuar" />
        </form>';
}

// HTML del pas 2 de recuperació: es mostra la pregunta de seguretat
// i es demana la resposta + la nova contrasenya
function recuperacioPas2(string $user_name, string $question, string $error = ''): string
{
    $feedback = $error !== '' ? '<div>' . $error . '</div>' : '';
    return $feedback . '
        <form method="POST" action="/?page=recover">
            <input type="hidden" name="user_name" value="' . htmlentities($user_name) . '" />
            <p>Pregunta de seguretat: <strong>' . htmlentities($question) . '</strong></p>
            <label for="recover_answer">Resposta</label>
            <input id="recover_answer" type="text" name="security_answer" required />
            <label for="recover_new_password">Nova contrasenya</label>
            <input id="recover_new_password" type="password" name="new_password" required />
            <label for="recover_confirm_password">Confirma la nova contrasenya</label>
            <input id="recover_confirm_password" type="password" name="confirm_password" required />
            <input type="submit" name="recover_reset" value="Canviar contrasenya" />
        </form>';
}

// -----------------------------------------------------------------
// Processament de paràmetres
// -----------------------------------------------------------------
$parameters = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

if (isset($parameters['page'])) {

    if ($parameters['page'] == 'register') {
        $template = 'register';
        $configuration['{REGISTER_USERNAME}'] = '';
        $configuration['{REGISTER_EMAIL}'] = '';
        $configuration['{REGISTER_QUESTION}'] = '';
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Ja tinc un compte';

    } else if ($parameters['page'] == 'verify_email') {
        $template = 'verify_email';

    } else if ($parameters['page'] == 'login') {
        $template = 'login';
        $configuration['{LOGIN_USERNAME}'] = '';

    } else if ($parameters['page'] == 'logout') {
        $_SESSION = array(); // oblida les dades carregades en aquesta petició
        session_destroy();  // elimina la sessió desada al servidor

    } else if ($parameters['page'] == 'recover') {
        $template = 'recover';
        $configuration['{RECOVER_BODY}'] = recuperacioPas1();

    } else if ($parameters['page'] == 'change_password') {
        exigeixSessio();
        $template = 'change_password';

    } else if ($parameters['page'] == 'edit_profile') {
        exigeixSessio();
        $template = 'edit_profile';
        $configuration['{PROFILE_DISPLAY_NAME}'] = '';
        $db = connectaBD($db_connection);
        if ($db !== null) {
            $user = trobaUsuari($db, $_SESSION['user_name']);
            if ($user !== false && $user['display_name'] !== null) {
                $configuration['{PROFILE_DISPLAY_NAME}'] = htmlentities($user['display_name']);
            }
        }

    } else if ($parameters['page'] == 'delete_account') {
        exigeixSessio();
        $template = 'delete_account';

    } else if ($parameters['page'] == 'play') {
        // Només es pot entrar al joc havent iniciat sessió
        exigeixSessio();
        header('Location: ' . $game_url . '/?token=' . urlencode(creaTokenJoc($_SESSION['user_name'])));
        exit;
    }

// -----------------------------------------------------------------
// REGISTRE
// -----------------------------------------------------------------
} else if (isset($parameters['register'])) {

    if (
        !isset(
            $parameters['user_name'],
            $parameters['email'],
            $parameters['user_password'],
            $parameters['security_question'],
            $parameters['security_answer']
        )
        || trim($parameters['user_name']) === ''
        || trim($parameters['email']) === ''
        || trim($parameters['security_question']) === ''
        || trim($parameters['security_answer']) === ''
    ) {
        $template = 'register';

        $configuration['{REGISTER_USERNAME}'] =
            htmlentities($parameters['user_name'] ?? '');

        $configuration['{REGISTER_EMAIL}'] =
            htmlentities($parameters['email'] ?? '');

        $configuration['{REGISTER_QUESTION}'] =
            htmlentities($parameters['security_question'] ?? '');

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: Falten dades</mark>";

    } else if (!filter_var($parameters['email'], FILTER_VALIDATE_EMAIL)) {

        $template = 'register';

        $configuration['{REGISTER_USERNAME}'] =
            htmlentities($parameters['user_name']);

        $configuration['{REGISTER_EMAIL}'] =
            htmlentities($parameters['email']);

        $configuration['{REGISTER_QUESTION}'] =
            htmlentities($parameters['security_question']);

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: El correu electrònic no és vàlid</mark>";

    } else if (!esContrasenyaValida($parameters['user_password'])) {

        $template = 'register';

        $configuration['{REGISTER_USERNAME}'] =
            htmlentities($parameters['user_name']);

        $configuration['{REGISTER_EMAIL}'] =
            htmlentities($parameters['email']);

        $configuration['{REGISTER_QUESTION}'] =
            htmlentities($parameters['security_question']);

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: Contrassenya no prou segura (8 car. minim, 1 Maj minim, 1 num minim)</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $user = trobaUsuari(
                $db,
                trim($parameters['user_name'])
            );

            $email_user = trobaUsuariPerEmail(
                $db,
                strtolower(trim($parameters['email']))
            );

            //si ja existeix o no s'ha escrit usuari Error
            if ($user !== false || trim($parameters['user_name']) === '') {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: No s'ha pogut crear el compte, nom d'usuari o contrassenya no valids</mark>";

            } else if ($email_user !== false) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: Aquest correu electrònic ja està registrat</mark>";

            }

            //procedim amb registre
            else {

                // No existeix: pots continuar amb el registre
                $hash = password_hash(
                    $parameters['user_password'],
                    PASSWORD_DEFAULT
                );

                $answer_hash = password_hash(
                    strtolower(trim($parameters['security_answer'])),
                    PASSWORD_DEFAULT
                );

                // Generem un codi de verificació de 6 dígits.
                // No guardem el codi en clar a la base de dades.
                $verification_code = random_int(
                    100000,
                    999999
                );

                $verification_code_hash = password_hash(
                    (string)$verification_code,
                    PASSWORD_DEFAULT
                );

                // El codi de verificació serà vàlid durant 15 minuts.
                $verification_expires = time() + 900;

                $sql = 'INSERT INTO users
                        (
                            user_name,
                            user_password,
                            security_question,
                            security_answer_hash,
                            email,
                            email_verified,
                            verification_code_hash,
                            verification_expires
                        )
                        VALUES
                        (
                            :user_name,
                            :user_password,
                            :security_question,
                            :security_answer_hash,
                            :email,
                            0,
                            :verification_code_hash,
                            :verification_expires
                        )';

                $query = $db->prepare($sql);

                $query->bindValue(
                    ':user_name',
                    trim($parameters['user_name'])
                );

                $query->bindValue(
                    ':user_password',
                    $hash
                );

                $query->bindValue(
                    ':security_question',
                    trim($parameters['security_question'])
                );

                $query->bindValue(
                    ':security_answer_hash',
                    $answer_hash
                );

                $query->bindValue(
                    ':email',
                    strtolower(trim($parameters['email']))
                );

                $query->bindValue(
                    ':verification_code_hash',
                    $verification_code_hash
                );

                $query->bindValue(
                    ':verification_expires',
                    $verification_expires,
                    PDO::PARAM_INT
                );

                //si s'executa, encara no iniciem sessió:
                //primer cal verificar el correu
                if ($query->execute()) {

                    $nou_usuari = trim($parameters['user_name']);
                    $nou_email  = strtolower(trim($parameters['email']));

                    if (enviaCodiVerificacio($nou_email, $nou_usuari, (string)$verification_code)) {

                        session_regenerate_id(true);
                        $_SESSION['pending_verification_user']  = $nou_usuari;
                        $_SESSION['pending_verification_email'] = $nou_email;
                        $_SESSION['pending_last_sent']          = time();

                        header('Location: /?page=verify_email');
                        exit;

                    } else {

                        // No s'ha pogut enviar el correu: esborrem el compte
                        // provisional perquè el nom i el correu no quedin bloquejats
                        $del = $db->prepare('DELETE FROM users WHERE user_name = :u AND email_verified = 0');
                        $del->bindValue(':u', $nou_usuari);
                        $del->execute();

                        $template = 'register';
                        $configuration['{REGISTER_USERNAME}'] = htmlentities($nou_usuari);
                        $configuration['{REGISTER_EMAIL}'] = htmlentities($nou_email);
                        $configuration['{REGISTER_QUESTION}'] = htmlentities($parameters['security_question']);
                        $configuration['{FEEDBACK}'] =
                            "<mark>ERROR: No s'ha pogut enviar el correu de verificació. Torna-ho a provar més tard.</mark>";
                    }

                } else {

                    $configuration['{FEEDBACK}'] =
                        "<mark>ERROR: No s'ha pogut crear el compte <b>"
                        . htmlentities($parameters['user_name'])
                        . '</b></mark>';
                }
            }
        }
    }

// -----------------------------------------------------------------
// VERIFICACIÓ DEL CORREU
// -----------------------------------------------------------------
} else if (isset($parameters['verify_email'])) {

    $template = 'verify_email';

    if (!isset($_SESSION['pending_verification_user'])) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: No hi ha cap verificació pendent</mark>";

    } else if (
        !isset($parameters['verification_code'])
        || !preg_match(
            '/^[0-9]{6}$/',
            $parameters['verification_code']
        )
    ) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: El codi ha de tenir 6 dígits</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $user = trobaUsuari(
                $db,
                $_SESSION['pending_verification_user']
            );

            if ($user === false) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: Usuari no trobat</mark>";

            } else if ((int)$user['email_verified'] === 1) {

                $configuration['{FEEDBACK}'] =
                    'Aquest correu ja està verificat.';

            } else if (
                empty($user['verification_expires'])
                || time() > (int)$user['verification_expires']
            ) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: El codi de verificació ha caducat</mark>";

            } else if ((int)$user['verification_attempts'] >= 5) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: Massa intents. Demana un codi nou.</mark>";

            } else if (
                !password_verify(
                    $parameters['verification_code'],
                    $user['verification_code_hash']
                )
            ) {

                $inc = $db->prepare('UPDATE users SET verification_attempts = verification_attempts + 1 WHERE user_name = :u');
                $inc->bindValue(':u', $user['user_name']);
                $inc->execute();

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: El codi de verificació no és correcte</mark>";

            } else {

                $sql = 'UPDATE users
                        SET email_verified = 1,
                            verification_code_hash = NULL,
                            verification_expires = NULL,
                            verification_attempts = 0
                        WHERE user_name = :user_name';

                $query = $db->prepare($sql);

                $query->bindValue(
                    ':user_name',
                    $user['user_name']
                );

                if ($query->execute()) {

                    session_regenerate_id(true);

                    $_SESSION['user_name'] =
                        $user['user_name'];

                    unset($_SESSION['pending_verification_user']);
                    unset($_SESSION['pending_verification_email']);
                    unset($_SESSION['pending_last_sent']);

                    $template = 'home';

                    $configuration['{FEEDBACK}'] =
                        'Correu verificat correctament. Compte creat.';

                    $configuration['{LOGIN_LOGOUT_TEXT}'] =
                        'Tancar sessió';

                    $configuration['{LOGIN_LOGOUT_URL}'] =
                        '/?page=logout';

                } else {

                    $configuration['{FEEDBACK}'] =
                        "<mark>ERROR: No s'ha pogut verificar el correu</mark>";
                }
            }
        }
    }

// -----------------------------------------------------------------
// REENVIAR EL CODI DE VERIFICACIÓ
// -----------------------------------------------------------------
} else if (isset($parameters['resend_code'])) {

    $template = 'verify_email';

    if (!isset($_SESSION['pending_verification_user'])) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: No hi ha cap verificació pendent</mark>";

    } else if (time() - ($_SESSION['pending_last_sent'] ?? 0) < 60) {

        $configuration['{FEEDBACK}'] =
            "<mark>Espera un minut abans de demanar un codi nou</mark>";

    } else {

        $db = connectaBD($db_connection);
        $user = ($db !== null)
            ? trobaUsuari($db, $_SESSION['pending_verification_user'])
            : false;

        if ($user === false || (int)$user['email_verified'] === 1) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No hi ha cap verificació pendent</mark>";

        } else {

            $nou_codi = (string)random_int(100000, 999999);

            $upd = $db->prepare('UPDATE users
                                 SET verification_code_hash = :h,
                                     verification_expires = :e,
                                     verification_attempts = 0
                                 WHERE user_name = :u');
            $upd->bindValue(':h', password_hash($nou_codi, PASSWORD_DEFAULT));
            $upd->bindValue(':e', time() + 900, PDO::PARAM_INT);
            $upd->bindValue(':u', $user['user_name']);
            $upd->execute();

            if (enviaCodiVerificacio($user['email'], $user['user_name'], $nou_codi)) {
                $_SESSION['pending_last_sent'] = time();
                $configuration['{FEEDBACK}'] = 'T\'hem enviat un codi nou.';
            } else {
                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: No s'ha pogut enviar el correu</mark>";
            }
        }
    }

// -----------------------------------------------------------------
// LOGIN
// -----------------------------------------------------------------
} else if (isset($parameters['login'])) {

    if (!isset(
        $parameters['user_name'],
        $parameters['user_password']
    )) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: Falten dades</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $result_row = trobaUsuari(
                $db,
                $parameters['user_name']
            );

            if (
                $result_row
                && password_verify(
                    $parameters['user_password'],
                    $result_row['user_password']
                )
            ) {

                if ((int)$result_row['email_verified'] !== 1) {

                    $configuration['{FEEDBACK}'] =
                        "<mark>Has de verificar el teu correu electrònic abans d'iniciar sessió</mark>";

                } else {

                    // nou identificador de sessió per no reutilitzar
                    // el que tenia abans del login
                    session_regenerate_id(true);

                    $_SESSION['user_name'] =
                        $result_row['user_name'];

                    $configuration['{FEEDBACK}'] =
                        'Sessió iniciada com <b>'
                        . htmlentities($parameters['user_name'])
                        . '</b>';

                    $configuration['{LOGIN_LOGOUT_TEXT}'] =
                        'Tancar sessió';

                    $configuration['{LOGIN_LOGOUT_URL}'] =
                        '/?page=logout';
                }

            } else {

                $configuration['{FEEDBACK}'] =
                    '<mark>ERROR: Usuari desconegut o contrasenya incorrecta</mark>';
            }
        }
    }

// -----------------------------------------------------------------
// RECUPERACIÓ DE CONTRASENYA - pas 1: es demana el nom d'usuari
// -----------------------------------------------------------------
} else if (isset($parameters['recover_lookup'])) {

    $template = 'recover';

    if (
        !isset($parameters['user_name'])
        || trim($parameters['user_name']) === ''
    ) {

        $configuration['{RECOVER_BODY}'] =
            recuperacioPas1(
                "<mark>Cal indicar un nom d'usuari</mark>"
            );

    } else {

        $db = connectaBD($db_connection);

        $user = ($db !== null)
            ? trobaUsuari($db, $parameters['user_name'])
            : false;

        if (
            $user === false
            || $user['security_question'] === null
        ) {

            // No revelem si l'usuari existeix o no,
            // només que no es pot recuperar així
            $configuration['{RECOVER_BODY}'] =
                recuperacioPas1(
                    "<mark>ERROR: No s'ha pogut recuperar la contrasenya per a aquest usuari</mark>"
                );

        } else {

            $configuration['{RECOVER_BODY}'] =
                recuperacioPas2(
                    $user['user_name'],
                    $user['security_question']
                );
        }
    }

// -----------------------------------------------------------------
// RECUPERACIÓ DE CONTRASENYA - pas 2: resposta + nova contrasenya
// -----------------------------------------------------------------
} else if (isset($parameters['recover_reset'])) {

    $template = 'recover';

    if (
        !isset(
            $parameters['user_name'],
            $parameters['security_answer'],
            $parameters['new_password'],
            $parameters['confirm_password']
        )
    ) {

        $configuration['{RECOVER_BODY}'] =
            recuperacioPas1(
                "<mark>ERROR: Falten dades</mark>"
            );

    } else {

        $db = connectaBD($db_connection);

        $user = ($db !== null)
            ? trobaUsuari($db, $parameters['user_name'])
            : false;

        if (
            $user === false
            || $user['security_question'] === null
        ) {

            $configuration['{RECOVER_BODY}'] =
                recuperacioPas1(
                    "<mark>ERROR: No s'ha pogut recuperar la contrasenya</mark>"
                );

        } else if (
            !password_verify(
                strtolower(trim($parameters['security_answer'])),
                $user['security_answer_hash']
            )
        ) {

            $configuration['{RECOVER_BODY}'] =
                recuperacioPas2(
                    $user['user_name'],
                    $user['security_question'],
                    "<mark>ERROR: Resposta incorrecta</mark>"
                );

        } else if (
            $parameters['new_password']
            !== $parameters['confirm_password']
        ) {

            $configuration['{RECOVER_BODY}'] =
                recuperacioPas2(
                    $user['user_name'],
                    $user['security_question'],
                    "<mark>ERROR: Les contrasenyes no coincideixen</mark>"
                );

        } else if (
            !esContrasenyaValida(
                $parameters['new_password']
            )
        ) {

            $configuration['{RECOVER_BODY}'] =
                recuperacioPas2(
                    $user['user_name'],
                    $user['security_question'],
                    "<mark>ERROR: Contrassenya no prou segura (8 car. minim, 1 Maj minim, 1 num minim)</mark>"
                );

        } else {

            $hash = password_hash(
                $parameters['new_password'],
                PASSWORD_DEFAULT
            );

            $sql = 'UPDATE users
                    SET user_password = :user_password
                    WHERE user_name = :user_name';

            $query = $db->prepare($sql);

            $query->bindValue(
                ':user_password',
                $hash
            );

            $query->bindValue(
                ':user_name',
                $user['user_name']
            );

            if ($query->execute()) {

                $template = 'login';

                $configuration['{LOGIN_USERNAME}'] =
                    htmlentities($user['user_name']);

                $configuration['{FEEDBACK}'] =
                    'Contrasenya actualitzada. Ja pots iniciar sessió.';

            } else {

                $configuration['{RECOVER_BODY}'] =
                    recuperacioPas2(
                        $user['user_name'],
                        $user['security_question'],
                        "<mark>ERROR: No s'ha pogut actualitzar la contrasenya</mark>"
                    );
            }
        }
    }

// -----------------------------------------------------------------
// CANVI DE CONTRASENYA (usuari identificat)
// -----------------------------------------------------------------
} else if (isset($parameters['change_password'])) {

    exigeixSessio();

    if (
        !isset(
            $parameters['current_password'],
            $parameters['new_password'],
            $parameters['confirm_password']
        )
    ) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: Falten dades</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $user = trobaUsuari(
                $db,
                $_SESSION['user_name']
            );

            if (
                !$user
                || !password_verify(
                    $parameters['current_password'],
                    $user['user_password']
                )
            ) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: La contrasenya actual no és correcta</mark>";

            } else if (
                $parameters['new_password']
                !== $parameters['confirm_password']
            ) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: Les contrasenyes noves no coincideixen</mark>";

            } else if (
                !esContrasenyaValida(
                    $parameters['new_password']
                )
            ) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: Contrassenya no prou segura (8 car. minim, 1 Maj minim, 1 num minim)</mark>";

            } else {

                $hash = password_hash(
                    $parameters['new_password'],
                    PASSWORD_DEFAULT
                );

                $sql = 'UPDATE users
                        SET user_password = :user_password
                        WHERE user_name = :user_name';

                $query = $db->prepare($sql);

                $query->bindValue(
                    ':user_password',
                    $hash
                );

                $query->bindValue(
                    ':user_name',
                    $_SESSION['user_name']
                );

                $configuration['{FEEDBACK}'] =
                    $query->execute()
                        ? 'Contrasenya actualitzada correctament'
                        : "<mark>ERROR: No s'ha pogut actualitzar la contrasenya</mark>";
            }
        }
    }

// -----------------------------------------------------------------
// EDICIÓ DEL PERFIL (usuari identificat)
// -----------------------------------------------------------------
} else if (isset($parameters['edit_profile'])) {

    exigeixSessio();

    $display_name = isset($parameters['display_name'])
        ? trim($parameters['display_name'])
        : '';

    if (strlen($display_name) > 127) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: El nom a mostrar és massa llarg</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $sql = 'UPDATE users
                    SET display_name = :display_name
                    WHERE user_name = :user_name';

            $query = $db->prepare($sql);

            $query->bindValue(
                ':display_name',
                $display_name === '' ? null : $display_name,
                PDO::PARAM_STR
            );

            $query->bindValue(
                ':user_name',
                $_SESSION['user_name']
            );

            $configuration['{FEEDBACK}'] =
                $query->execute()
                    ? 'Perfil actualitzat correctament'
                    : "<mark>ERROR: No s'ha pogut actualitzar el perfil</mark>";
        }
    }

// -----------------------------------------------------------------
// ELIMINACIÓ DEL COMPTE (usuari identificat)
// -----------------------------------------------------------------
} else if (isset($parameters['delete_account'])) {

    exigeixSessio();

    if (!isset($parameters['user_password'])) {

        $configuration['{FEEDBACK}'] =
            "<mark>ERROR: Falten dades</mark>";

    } else {

        $db = connectaBD($db_connection);

        if ($db === null) {

            $configuration['{FEEDBACK}'] =
                "<mark>ERROR: No s'ha pogut connectar amb la base de dades</mark>";

        } else {

            $user = trobaUsuari(
                $db,
                $_SESSION['user_name']
            );

            if (
                !$user
                || !password_verify(
                    $parameters['user_password'],
                    $user['user_password']
                )
            ) {

                $configuration['{FEEDBACK}'] =
                    "<mark>ERROR: La contrasenya no és correcta</mark>";

            } else {

                $sql = 'DELETE FROM users
                        WHERE user_name = :user_name';

                $query = $db->prepare($sql);

                $query->bindValue(
                    ':user_name',
                    $_SESSION['user_name']
                );

                if ($query->execute()) {

                    $_SESSION = array();
                    session_destroy();

                    $configuration['{FEEDBACK}'] =
                        'El compte s\'ha eliminat correctament';

                } else {

                    $configuration['{FEEDBACK}'] =
                        "<mark>ERROR: No s'ha pogut eliminar el compte</mark>";
                }
            }
        }
    }
}

// -----------------------------------------------------------------
// adaptem la pàgina inicial a l'usuari identificat
// -----------------------------------------------------------------
if ($template == 'home' && isset($_SESSION['user_name'])) {

    $configuration['{LOGIN_LOGOUT_TEXT}'] =
        'Tancar sessió';

    $configuration['{LOGIN_LOGOUT_URL}'] =
        '/?page=logout';

    if ($configuration['{FEEDBACK}'] == '') {

        $configuration['{FEEDBACK}'] =
            'Sessió iniciada com <b>'
            . htmlentities($_SESSION['user_name'])
            . '</b>';
    }

    $configuration['{PROFILE_LINKS}'] = '
        <div><a href="/?page=play">Jugar al Globo cenital</a></div>
        <div><a href="/?page=edit_profile">Editar el perfil</a></div>
        <div><a href="/?page=change_password">Canviar la contrasenya</a></div>
        <div><a href="/?page=delete_account">Eliminar el compte</a></div>';
}

// processament amb la plantilla i generació de la pàgina
$html = file_get_contents(
    __DIR__ . '/../private/plantilla_' . $template . '.html',
    true
);

$html = str_replace(
    array_keys($configuration),
    array_values($configuration),
    $html
);

echo $html;