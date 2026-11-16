
<?php

session_start();

// --------------------------------------------------
// CONFIGURACIÓ
// --------------------------------------------------

$template = 'home';

$db_Path = __DIR__ . '/../private/users.db';
$db_connection = 'sqlite:' . $db_Path;

// URL pública de la pàgina
define('VERIFY_BASE_URL', 'https://multijugador.khamia.org/index.php');

// Credencials de Brevo (API v3, no la clau SMTP).
// A Brevo: https://app.brevo.com/settings/keys/api
define('BREVO_API_KEY', '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');
define('BREVO_SENDER_EMAIL', 'algajitablet@gmail.com');
define('BREVO_SENDER_NAME', 'Practica multijugador Leah Irina Leo');

// --------------------------------------------------
// BASE DE DADES
// --------------------------------------------------

try {

    $db_init = new PDO($db_connection);
    $db_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db_init->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_name VARCHAR(64) PRIMARY KEY,
            user_password VARCHAR(255) NOT NULL,
            user_alias VARCHAR(64),
            user_email VARCHAR(254),
            email_verified INTEGER DEFAULT 0,
            verification_token TEXT,
            verification_expires INTEGER
        )
    ");

    // Migració automàtica de columnes per a bases de dades antigues

    $columns = $db_init->query("PRAGMA table_info(users)")
                       ->fetchAll(PDO::FETCH_COLUMN, 1);

    $migrations = [
        'user_email' => 'ALTER TABLE users ADD COLUMN user_email VARCHAR(254)',
        'email_verified' => 'ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0',
        'verification_token' => 'ALTER TABLE users ADD COLUMN verification_token TEXT',
        'verification_expires' => 'ALTER TABLE users ADD COLUMN verification_expires INTEGER'
    ];

    foreach ($migrations as $column => $sql) {
        if (!in_array($column, $columns)) {
            $db_init->exec($sql);
        }
    }

} catch (PDOException $e) {

    error_log('Error inicialitzant SQLite: ' . $e->getMessage());
    die('Error de connexió amb la base de dades.');

}

// --------------------------------------------------
// FUNCIONS
// --------------------------------------------------

function verify_session($password) {

    global $db_connection;

    $db = new PDO($db_connection);

    $sql = 'SELECT * FROM users WHERE user_name = :user_name';

    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_SESSION['user_name']);
    $query->execute();

    $result_row = $query->fetchObject();

    return $result_row &&
           password_verify($password, $result_row->user_password);
}


// --------------------------------------------------
// ENVIAR CORREU DE VERIFICACIÓ
// --------------------------------------------------

function send_verification_email($email, $username, $token) {

    // Comprobar API key
    if (
        empty(BREVO_API_KEY) ||
        BREVO_API_KEY === '-' ||
        !filter_var(BREVO_SENDER_EMAIL, FILTER_VALIDATE_EMAIL)
    ) {
        error_log('BREVO ERROR: API KEY o remitente incorrectos.');
        return false;
    }

    // Comprobar cURL
    if (!function_exists('curl_init')) {
        error_log('BREVO ERROR: cURL no está activado.');
        return false;
    }

    // URL de verificación
    $verification_url = VERIFY_BASE_URL .
        '?page=verify&token=' . urlencode($token);

    $safe_username = htmlspecialchars(
        $username,
        ENT_QUOTES,
        'UTF-8'
    );

    $safe_url = htmlspecialchars(
        $verification_url,
        ENT_QUOTES,
        'UTF-8'
    );

    // Contenido HTML
    
    // Contenido HTML
    $html_content = '
    <!DOCTYPE html>
    <html>
    <body style="font-family: Arial, sans-serif;">
        <h2>Benvingut, ' . $safe_username . '!</h2>

        <p>Gràcies per registrar-te.</p>

        <p>Per activar el teu compte, fes clic al següent enllaç:</p>

        <p>
            <a href="' . $safe_url . '"
            style="background-color:#198754;
                    color:white;
                    padding:12px 20px;
                    text-decoration:none;
                    border-radius:5px;
                    display:inline-block;">
                VERIFICAR EL MEU COMPTE
            </a>
        </p>

        <p>Si el botó no funciona, copia aquest enllaç al navegador:</p>

        <p>' . $safe_url . '</p>

        <p>Aquest enllaç caduca en 24 hores.</p>
    </body>
    </html>
    ';

    // Contenido de texto plano
    $text_content =
        "Benvingut {$username}!\n\n" .
        "Per verificar el teu compte, obre aquest enllaç:\n\n" .
        $verification_url . "\n\n" .
        "Aquest enllaç caduca en 24 hores.";


    // Datos para Brevo
    $payload = [
        'sender' => [
            'name' => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $username
            ]
        ],
        'subject' => 'Verifica el teu compte',
        'htmlContent' => $html_content,
        'textContent' => $text_content
    ];

    // Convertir a JSON
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        error_log('BREVO JSON ERROR: ' . json_last_error_msg());
        return false;
    }

    // Crear petición
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json'
        ],

        CURLOPT_POSTFIELDS => $json,

        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    // Ejecutar petición
    $response = curl_exec($ch);

    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);

    $http_code = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    // Error de conexión
    if ($response === false) {

        error_log(
            'BREVO CURL ERROR [' . $curl_errno . ']: ' .
            $curl_error
        );

        return false;
    }

    // Registrar respuesta HTTP
    error_log(
        'BREVO HTTP STATUS: ' . $http_code .
        ' RESPONSE: ' . $response
    );

    // Comprobar respuesta
    if ($http_code < 200 || $http_code >= 300) {
        return false;
    }

    return true;
}
// --------------------------------------------------
// CONFIGURACIÓ DE PLANTILLES
// --------------------------------------------------

$configuration = array(

    '{FEEDBACK}'          => '',
    '{REGISTER_USERNAME}' => '',
    '{LOGIN_USERNAME}'    => '',
    '{REGISTER_PASSWORD}' => '',

    '{LOGIN_LOGOUT_TEXT}' => 'Identificar-me',
    '{LOGIN_LOGOUT_URL}'  => '?page=login',

    '{METHOD}'            => 'POST',
    '{REGISTER_URL}'      => '?page=register',

    '{MIN_PWD_LENGTH}'    => '15',
    '{MAX_LENGTH}'        => '64',

    '{REGISTER_SECTION}'  =>
        '<section><div><a href="?page=register">Registrar-me</a></div></section>',

    '{PROFILE_SECTION}'  => '',
    '{GAME_SECTION}'     => '',

    '{PROFILE_USERNAME}' => '',
    '{PROFILE_ALIAS}'    => '',
    '{PROFILE_EMAIL}'    => '',

    '{PLAYER_ID}'        => '',
    '{PLAYER_ID_JS}'     => '""',

    '{SITE_NAME}'        => 'La meva pàgina'
);


// --------------------------------------------------
// PARÀMETRES
// --------------------------------------------------

$parameters = array_merge($_GET, $_POST);


// --------------------------------------------------
// SINCRONITZACIÓ DE SESSIÓ I GALETA
// --------------------------------------------------

if (isset($_SESSION['user_name'])) {

    setcookie(
        'jugador_sessio',
        $_SESSION['user_name'],
        time() + 3600,
        "/"
    );
}


// --------------------------------------------------
// VERIFICACIÓ DEL CORREU ELECTRÒNIC
// --------------------------------------------------

if (isset($parameters['page']) &&
    $parameters['page'] == 'verify') {

    $token = $parameters['token'] ?? '';

    $template = 'login';

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {

        $configuration['{FEEDBACK}'] =
            '<mark>Enllaç de verificació no vàlid.</mark>';

    } else {

        $db = new PDO($db_connection);

        $token_hash = hash('sha256', $token);

        $sql = '
            SELECT user_name
            FROM users
            WHERE verification_token = :token
            AND email_verified = 0
            AND verification_expires > :now
        ';

        $query = $db->prepare($sql);

        $query->bindValue(':token', $token_hash);
        $query->bindValue(':now', time());

        $query->execute();

        $user = $query->fetchObject();

        if ($user) {

            $sql = '
                UPDATE users
                SET email_verified = 1,
                    verification_token = NULL,
                    verification_expires = NULL
                WHERE user_name = :user_name
            ';

            $query = $db->prepare($sql);

            $query->bindValue(':user_name', $user->user_name);

            $query->execute();

            $configuration['{FEEDBACK}'] =
                '<mark>Correu verificat correctament! ' .
                'Ja pots iniciar sessió.</mark>';

        } else {

            $configuration['{FEEDBACK}'] =
                '<mark>L\'enllaç de verificació no és vàlid ' .
                'o ha caducat.</mark>';
        }
    }
}


// --------------------------------------------------
// USUARIS NO IDENTIFICATS
// --------------------------------------------------

if (!isset($_SESSION['user_name'])) {

    // ----------------------------------------------
    // REGISTRE
    // ----------------------------------------------

    if (isset($parameters['register'])) {

        $username = trim($parameters['user_name'] ?? '');
        $password = $parameters['user_password'] ?? '';
        $email = trim($parameters['user_email'] ?? '');

        $template = 'register';

        $configuration['{REGISTER_USERNAME}'] =
            htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        $configuration['{PROFILE_EMAIL}'] =
            htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        // Validació del nom d'usuari

        if (
            empty($username) ||
            strlen($username) > 64
        ) {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: Nom d\'usuari no vàlid.</mark>';

        // Validació de la contrasenya

        } else if (
            strlen($password) < 15 ||
            strlen($password) > 64 ||
            !preg_match('/^(?=.*[a-zA-Z])(?=.*[0-9])[a-zA-Z0-9]+$/', $password)
        ) {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: La contrasenya ha de tenir entre 15 i 64 ' .
                'caràcters i contenir lletres i números.</mark>';

        // Validació del correu

        } else if (
            empty($email) ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 254
        ) {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: Introdueix un correu electrònic vàlid.</mark>';

        } else {

            $db = new PDO($db_connection);

            // Comprovar si l'usuari o el correu ja existeixen

            $sql = '
                SELECT * FROM users
                WHERE user_name = :user_name
                OR user_email = :user_email
            ';

            $query = $db->prepare($sql);

            $query->bindValue(':user_name', $username);
            $query->bindValue(':user_email', $email);

            $query->execute();

            $result_row = $query->fetchObject();

            if ($result_row) {

                if ($result_row->user_name === $username) {

                    $configuration['{FEEDBACK}'] =
                        '<mark>ERROR: El nom d\'usuari ja està registrat.</mark>';

                } else {

                    $configuration['{FEEDBACK}'] =
                        '<mark>ERROR: Aquest correu electrònic ja està en ús.</mark>';
                }

            } else {

                // Crear contrasenya xifrada

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_BCRYPT
                );

                // Generar token segur

                $token = bin2hex(random_bytes(32));

                $token_hash = hash('sha256', $token);

                // Caducitat de 24 hores

                $expires = time() + (24 * 60 * 60);

                // Inserir usuari pendent de verificació

                $sql = '
                    INSERT INTO users (
                        user_name,
                        user_password,
                        user_alias,
                        user_email,
                        email_verified,
                        verification_token,
                        verification_expires
                    )
                    VALUES (
                        :user_name,
                        :user_password,
                        :user_alias,
                        :user_email,
                        0,
                        :token,
                        :expires
                    )
                ';

                $query = $db->prepare($sql);

                $query->bindValue(':user_name', $username);
                $query->bindValue(':user_password', $hashed_password);
                $query->bindValue(':user_alias', $username);
                $query->bindValue(':user_email', $email);
                $query->bindValue(':token', $token_hash);
                $query->bindValue(':expires', $expires);

                if ($query->execute()) {

                    // Enviar correu de verificació

                    if (
                        send_verification_email(
                            $email,
                            $username,
                            $token
                        )
                    ) {

                        $template = 'login';

                        $configuration['{FEEDBACK}'] =
                            '<mark>Compte creat correctament! ' .
                            'T\'hem enviat un correu electrònic. ' .
                            'Revisa la teva safata d\'entrada i verifica ' .
                            'el teu compte abans d\'iniciar sessió.</mark>';

                    } else {

                        $configuration['{FEEDBACK}'] =
                            '<mark>Compte creat, però no s\'ha pogut enviar ' .
                            'el correu de verificació. Contacta amb ' .
                            'l\'administrador.</mark>';
                    }

                } else {

                    $configuration['{FEEDBACK}'] =
                        '<mark>ERROR: No s\'ha pogut crear el compte.</mark>';
                }
            }
        }


    // ----------------------------------------------
    // LOGIN
    // ----------------------------------------------

    } else if (isset($parameters['login'])) {

        $username = $parameters['user_name'] ?? '';
        $password = $parameters['user_password'] ?? '';

        $db = new PDO($db_connection);

        $sql = '
            SELECT * FROM users
            WHERE user_name = :user_name
        ';

        $query = $db->prepare($sql);

        $query->bindValue(':user_name', $username);

        $query->execute();

        $result_row = $query->fetchObject();

        if (
            $result_row &&
            password_verify($password, $result_row->user_password)
        ) {

            // Comprovar si el correu està verificat

            if ((int)$result_row->email_verified !== 1) {

                $template = 'login';

                $configuration['{FEEDBACK}'] =
                    '<mark>Has de verificar el teu correu electrònic ' .
                    'abans d\'iniciar sessió.</mark>';

            } else {

                session_regenerate_id(true);

                $_SESSION['user_name'] = $result_row->user_name;

                setcookie(
                    'jugador_sessio',
                    $_SESSION['user_name'],
                    time() + 3600,
                    "/"
                );

                $configuration['{FEEDBACK}'] =
                    'Sessió iniciada com <b>' .
                    htmlentities(
                        $result_row->user_alias ?: $result_row->user_name
                    ) .
                    '</b>';
            }

        } else {

            $template = 'login';

            $configuration['{LOGIN_USERNAME}'] =
                htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: Usuari o contrasenya incorrectes.</mark>';
        }


    // ----------------------------------------------
    // PÀGINES PÚBLIQUES
    // ----------------------------------------------

    } else if (isset($parameters['page'])) {

        if ($parameters['page'] == 'register') {

            $template = 'register';

            $configuration['{REGISTER_USERNAME}'] = '';
            $configuration['{REGISTER_PASSWORD}'] = '';

            $configuration['{LOGIN_LOGOUT_TEXT}'] =
                'Ja tinc un compte';

        } else if ($parameters['page'] == 'login') {

            $template = 'login';

            $configuration['{LOGIN_USERNAME}'] = '';
        }
    }


// --------------------------------------------------
// USUARIS IDENTIFICATS
// --------------------------------------------------

} else {

    // ----------------------------------------------
    // ACTUALITZAR ÀLIES
    // ----------------------------------------------

    if (isset($parameters['update_profile'])) {

        $db = new PDO($db_connection);

        $sql = '
            UPDATE users
            SET user_alias = :user_alias
            WHERE user_name = :user_name
        ';

        $query = $db->prepare($sql);

        $query->bindValue(
            ':user_alias',
            trim($parameters['user_alias'] ?? '')
        );

        $query->bindValue(
            ':user_name',
            $_SESSION['user_name']
        );

        $template = 'profile';

        if ($query->execute()) {

            $configuration['{FEEDBACK}'] =
                'Àlies actualitzat correctament.';

        } else {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: No s\'ha pogut actualitzar l\'àlies.</mark>';
        }


    // ----------------------------------------------
    // ACTUALITZAR CORREU ELECTRÒNIC
    // AMB CONTRASENYA I NOVA VERIFICACIÓ
    // ----------------------------------------------

    } else if (isset($parameters['update_email'])) {

        $template = 'profile';

        $new_email = trim($parameters['user_email'] ?? '');
        $password = $parameters['user_password'] ?? '';

        if (!verify_session($password)) {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: Contrasenya incorrecta. ' .
                'No s\'ha pogut canviar el correu.</mark>';

        } else if (
            empty($new_email) ||
            !filter_var($new_email, FILTER_VALIDATE_EMAIL) ||
            strlen($new_email) > 254
        ) {

            $configuration['{FEEDBACK}'] =
                '<mark>ERROR: El format del correu electrònic no és vàlid.</mark>';

        } else {

            $db = new PDO($db_connection);

            // Comprovar si el correu ja està en ús

            $sql_check = '
                SELECT user_name FROM users
                WHERE user_email = :user_email
                AND user_name != :user_name
            ';

            $query_check = $db->prepare($sql_check);

            $query_check->bindValue(':user_email', $new_email);
            $query_check->bindValue(
                ':user_name',
                $_SESSION['user_name']
            );

            $query_check->execute();

            if ($query_check->fetchObject()) {

                $configuration['{FEEDBACK}'] =
                    '<mark>ERROR: Aquest correu electrònic ' .
                    'ja està en ús per un altre compte.</mark>';

            } else {

                // Generar nou token

                $token = bin2hex(random_bytes(32));

                $token_hash = hash('sha256', $token);

                $expires = time() + (24 * 60 * 60);

                // Actualitzar correu i deixar-lo pendent de verificació

                $sql = '
                    UPDATE users
                    SET user_email = :user_email,
                        email_verified = 0,
                        verification_token = :token,
                        verification_expires = :expires
                    WHERE user_name = :user_name
                ';

                $query = $db->prepare($sql);

                $query->bindValue(':user_email', $new_email);
                $query->bindValue(':token', $token_hash);
                $query->bindValue(':expires', $expires);
                $query->bindValue(
                    ':user_name',
                    $_SESSION['user_name']
                );

                if ($query->execute()) {

                    $username = $_SESSION['user_name'];

                    if (
                        send_verification_email(
                            $new_email,
                            $username,
                            $token
                        )
                    ) {

                        // Tancar la sessió fins a verificar el nou correu

                        $_SESSION = array();

                        session_destroy();

                        setcookie(
                            'jugador_sessio',
                            '',
                            time() - 3600,
                            "/"
                        );

                        $template = 'login';

                        $configuration['{FEEDBACK}'] =
                            '<mark>Hem enviat un correu de verificació ' .
                            'a la nova adreça. Verifica-la abans ' .
                            'de tornar a iniciar sessió.</mark>';

                    } else {

                        $configuration['{FEEDBACK}'] =
                            '<mark>El correu s\'ha actualitzat, però no ' .
                            's\'ha pogut enviar la verificació. ' .
                            'Contacta amb l\'administrador.</mark>';
                    }

                } else {

                    $configuration['{FEEDBACK}'] =
                        '<mark>ERROR: No s\'ha pogut actualitzar ' .
                        'el correu electrònic.</mark>';
                }
            }
        }


    // ----------------------------------------------
    // TANCAR SESSIÓ
    // ----------------------------------------------

    } else if (
        isset($parameters['page']) &&
        $parameters['page'] == 'logout'
    ) {

        $_SESSION = array();

        session_destroy();

        setcookie(
            'jugador_sessio',
            '',
            time() - 3600,
            "/"
        );

        header('Location: ?page=login');
        exit;
    }
}


// --------------------------------------------------
// GESTIÓ DE PÀGINES / RUTES
// --------------------------------------------------

if (isset($parameters['page'])) {

    if (
        $parameters['page'] == 'profile' &&
        isset($_SESSION['user_name'])
    ) {

        $template = 'profile';

    } else if ($parameters['page'] == 'game') {

        if (!isset($_SESSION['user_name'])) {

            header('Location: ?page=login');
            exit;
        }

        $jugador_id = $_SESSION['user_name'];

        setcookie(
            'jugador_sessio',
            $jugador_id,
            time() + (86400 * 30),
            "/"
        );

        $configuration_game = array(
            '{PLAYER_ID}' => htmlspecialchars($jugador_id, ENT_QUOTES, 'UTF-8'),
            '{PLAYER_ID_JS}' => json_encode($jugador_id),
            '{SITE_NAME}' => $configuration['{SITE_NAME}'],
            '{FEEDBACK}' => $configuration['{FEEDBACK}']
        );

        $html_game = file_get_contents('plantilla_game.html', true);
        $html_game = str_replace(array_keys($configuration_game), array_values($configuration_game), $html_game);
        echo $html_game;

        exit;
    }
}


// --------------------------------------------------
// CARREGAR DADES DEL PERFIL
// --------------------------------------------------

if (
    $template == 'profile' &&
    isset($_SESSION['user_name'])
) {

    $db = new PDO($db_connection);

    $sql = '
        SELECT user_name, user_alias, user_email
        FROM users
        WHERE user_name = :user_name
    ';

    $query = $db->prepare($sql);

    $query->bindValue(':user_name', $_SESSION['user_name']);

    $query->execute();

    $user = $query->fetchObject();

    if ($user) {

        $configuration['{PROFILE_USERNAME}'] =
            htmlentities($user->user_name);

        $configuration['{PROFILE_ALIAS}'] =
            htmlentities($user->user_alias ?? '');

        $configuration['{PROFILE_EMAIL}'] =
            htmlentities($user->user_email ?? '');
    }
}


// --------------------------------------------------
// CONFIGURACIÓ D'ESTAT PER A USUARIS IDENTIFICATS
// --------------------------------------------------

if (isset($_SESSION['user_name'])) {
    $configuration['{LOGIN_LOGOUT_TEXT}'] =
        'Tancar sessió';

    $configuration['{LOGIN_LOGOUT_URL}'] =
        '?page=logout';

    $configuration['{REGISTER_SECTION}'] = '';

    $configuration['{PROFILE_SECTION}'] =
        '<section><div>' .
        '<a href="?page=profile">Configuració del perfil</a>' .
        '</div></section>' .

        '<section><div>' .
        '<a href="?page=game">Entrar al Joc Multijugador</a>' .
        '</div></section>';
    
    if ($template == 'home' && empty($configuration['{FEEDBACK}'])) {
        // Leer el parámetro de la URL para mostrar el mensaje de desconexión
        if (isset($_GET['msg']) && $_GET['msg'] === 'desconnectat') {
            $configuration['{FEEDBACK}'] = '<mark>Rival desconectat, tancament de la sala</mark>';
        } else {
            $configuration['{FEEDBACK}'] = 'Sessió iniciada com <b>' . htmlentities($_SESSION['user_name']) . '</b>';
        }
    }
}


// --------------------------------------------------
// CARREGAR PLANTILLA HTML
// --------------------------------------------------

$html = file_get_contents(
    'plantilla_' . $template . '.html'
);

if ($html === false) {

    die('No s\'ha pogut carregar la plantilla.');

}

$html = str_replace(
    array_keys($configuration),
    array_values($configuration),
    $html
);

echo $html;

?>
