<?php
session_start();

$template = 'home';
$db_connection = 'sqlite:..\private\users.db';

// Sincronització de la sessió amb la galeta del joc
if (isset($_SESSION['user_name'])) {
    setcookie('jugador_sessio', $_SESSION['user_name'], time() + 3600, "/");
}

$configuration = array(
    '{FEEDBACK}'          => '',
    '{REGISTER_USERNAME}' => '',
    '{LOGIN_USERNAME}'    => '',
    '{REGISTER_PASSWORD}' => '',
    '{LOGIN_LOGOUT_TEXT}' => 'Identificar-me',
    '{LOGIN_LOGOUT_URL}'  => '/?page=login',
    '{METHOD}'            => 'POST',
    '{REGISTER_URL}'      => '/?page=register',
    '{MIN_PWD_LENGTH}'    => '15',
    '{MAX_LENGTH}'        => '64',
    '{REGISTER_SECTION}'  => '<section><div><a href="/?page=register">Registrar-me</a></div></section>',
    '{PROFILE_SECTION}'   => '',
    '{GAME_SECTION}'      => '',
    '{PROFILE_USERNAME}'  => '',
    '{PROFILE_ALIAS}'     => '',
    '{PROFILE_EMAIL}'     => '',
    '{PLAYER_ID}'         => '',
    '{PLAYER_ID_JS}'      => '""',
    '{SITE_NAME}'         => 'La meva pàgina'
);

$parameters = $_REQUEST;

function verify_session($password) {
    global $db_connection;
    $db = new PDO($db_connection);
    
    $sql = 'SELECT * FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_SESSION['user_name']);
    $query->execute();
    $result_row = $query->fetchObject();

    return $result_row && password_verify($password, $result_row->user_password);
}

// --- REGISTRE ---
if (!isset($_SESSION['user_name'])) {
    if (isset($parameters['register'])) {
        if (empty($parameters['user_name']) || empty($parameters['user_password'])) {
            $template = 'register';
            $configuration['{REGISTER_USERNAME}'] = $parameters['user_name'] ?? '';
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuari o contrasenya no vàlides</mark>';
        } else {
            $db = new PDO($db_connection);
            $sql = 'SELECT * FROM users WHERE user_name = :user_name';
            $query = $db->prepare($sql);
            $query->bindValue(':user_name', $parameters['user_name']);
            $query->execute();
            $result_row = $query->fetchObject();
            if ($result_row) {
                $template = 'register';
                $configuration['{REGISTER_USERNAME}'] = $parameters['user_name'];
                $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuari ja registrat</mark>';
            } else {
                $hashed_password = password_hash($parameters['user_password'], PASSWORD_ARGON2I);
                $sql = 'INSERT INTO users (user_name, user_password, user_alias) VALUES (:user_name, :user_password, :user_alias)';
                $query = $db->prepare($sql);
                $query->bindValue(':user_name', $parameters['user_name']);
                $query->bindValue(':user_alias', $parameters['user_name']);
                $query->bindValue(':user_password', $hashed_password);
                if ($query->execute()) {
                    $_SESSION['user_name'] = $parameters['user_name'];
                    setcookie('jugador_sessio', $_SESSION['user_name'], time() + 3600, "/");
                    $template = 'home';
                    $configuration['{FEEDBACK}'] = 'Creat el compte <b>' . htmlentities($parameters['user_name']) . '</b>';
                }
            }
        }
    // --- LOGIN ---
    } else if (isset($parameters['login'])) {
        $db = new PDO($db_connection);
        $sql = 'SELECT * FROM users WHERE user_name = :user_name';
        $query = $db->prepare($sql);
        $query->bindValue(':user_name', $parameters['user_name']);
        $query->execute();
        $result_row = $query->fetchObject();

        if ($result_row && password_verify($parameters['user_password'], $result_row->user_password)) {
            session_regenerate_id(true);
            $_SESSION['user_name'] = $result_row->user_name;
            setcookie('jugador_sessio', $_SESSION['user_name'], time() + 3600, "/");
            $configuration['{FEEDBACK}'] = 'Sessió iniciada com <b>' . htmlentities($result_row->user_alias ?: $result_row->user_name) . '</b>';
        } else {
            $template = 'login';
            $configuration['{LOGIN_USERNAME}'] = $parameters['user_name'];
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuari o contrasenya incorrectes</mark>';
        }
    }
} else {
    // --- ACTUALITZAR DADES ---
    if (isset($parameters['update_profile'])) {
        $db = new PDO($db_connection);
        $sql = 'UPDATE users SET user_alias = :user_alias, user_email = :user_email WHERE user_name = :user_name';
        $query = $db->prepare($sql);
        $query->bindValue(':user_alias', trim($parameters['user_alias']));
        $query->bindValue(':user_email', trim($parameters['user_email']));
        $query->bindValue(':user_name', $_SESSION['user_name']);
        
        $template = 'profile';
        if ($query->execute()) {
            $configuration['{FEEDBACK}'] = 'Perfil actualitzat correctament.';
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: No s\'ha pogut actualitzar el perfil.</mark>';
        }
    } else if (isset($parameters['delete_account'])) {
        if (verify_session($parameters['user_password'])) {
            $db = new PDO($db_connection);
            $sql = 'DELETE FROM users WHERE user_name = :user_name';
            $query = $db->prepare($sql);
            $query->bindValue(':user_name', $_SESSION['user_name']);
            $query->execute();
            
            $deleted_user = $_SESSION['user_name'];
            $_SESSION = array();
            session_destroy();
            setcookie('jugador_sessio', '', time() - 3600, "/");
            $template = 'home';
            $configuration['{FEEDBACK}'] = 'S\'ha eliminat el compte <b>' . htmlentities($deleted_user) . '</b> correctament.';
        } else {
            $template = 'profile';
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Contrasenya incorrecta. No s\'ha pogut eliminar el compte.</mark>';
        }
    } else if (isset($parameters['change_password_account'])) {
        if (verify_session($parameters['user_old_password'])) {
            $db = new PDO($db_connection);
            $sql = 'UPDATE users SET user_password = :user_new_password WHERE user_name = :user_name';
            $query = $db->prepare($sql);
            $query->bindValue(':user_name', $_SESSION['user_name']);
            $query->bindValue(':user_new_password', password_hash($parameters['user_new_password'], PASSWORD_ARGON2I));

            if ($query->execute()) {
                $configuration['{FEEDBACK}'] = 'Contrasenya modificada correctament.';
            } else {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: No s\'ha pogut actualitzar la contrasenya.</mark>';
            }
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Contrasenya incorrecta.</mark>';
        }
        $template = 'profile';
    }
}

// --- GESTIÓ DE PÀGINES / RUTES ---
if (isset($parameters['page'])) {
    if ($parameters['page'] == 'register' && !isset($_SESSION['user_name'])) {
        $template = 'register';
        $configuration['{REGISTER_USERNAME}'] = '';
        $configuration['{REGISTER_PASSWORD}'] = '';
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Ja tinc un compte';
    } else if ($parameters['page'] == 'login' && !isset($_SESSION['user_name'])) {
        $template = 'login';
        $configuration['{LOGIN_USERNAME}'] = '';
    } else if ($parameters['page'] == 'profile' && isset($_SESSION['user_name'])) {
        $template = 'profile';
        
    } else if ($parameters['page'] == 'game') {
        
        //redireccionem si no hi ha sessio
        if (!isset($_SESSION['user_name'])) {
            header('Location: /?page=login');
            exit;
        }

        $jugador_id = $_SESSION['user_name'];
        setcookie('jugador_sessio', $jugador_id, time() + (86400 * 30), "/");

        $configuration_game = array(
            '{PLAYER_ID}' => htmlspecialchars($jugador_id, ENT_QUOTES, 'UTF-8'),
            '{PLAYER_ID_JS}' => json_encode($jugador_id)
        );

        $html_game = file_get_contents('client_page.html', true);
        $html_game = str_replace(array_keys($configuration_game), array_values($configuration_game), $html_game);
        echo $html_game;
        exit;
        
    } else if ($parameters['page'] == 'logout' && isset($_SESSION['user_name'])) {
        $_SESSION = array();
        session_destroy();
        //eliminem cookie
        setcookie('jugador_sessio', '', time() - 3600, "/"); 
    }
}

// Carregar dades del perfil
if ($template == 'profile' && isset($_SESSION['user_name'])) {
    $db = new PDO($db_connection);
    $sql = 'SELECT user_name, user_alias, user_email FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_SESSION['user_name']);
    $query->execute();
    $user = $query->fetchObject();

    if ($user) {
        $configuration['{PROFILE_USERNAME}'] = htmlentities($user->user_name);
        $configuration['{PROFILE_ALIAS}'] = htmlentities($user->user_alias ?? '');
        $configuration['{PROFILE_EMAIL}'] = htmlentities($user->user_email ?? '');        
    }
}

// Configuració d'estat per a usuaris identificats
if (isset($_SESSION['user_name'])) {
    $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar sessió';
    $configuration['{LOGIN_LOGOUT_URL}']  = '/?page=logout';
    $configuration['{REGISTER_SECTION}']  = ''; 
    $configuration['{PROFILE_SECTION}']   = '<section><div><a href="/?page=profile">Configuració del perfil</a></div></section>' .
                                            '<section><div><a href="/?page=game">Entrar al Joc Multijugador</a></div></section>';

    if ($template == 'home' && empty($configuration['{FEEDBACK}'])) {
        $configuration['{FEEDBACK}'] = 'Sessió iniciada com <b>' . htmlentities($_SESSION['user_name']) . '</b>';
    }
}
$html = file_get_contents('plantilla_' . $template . '.html', true);
$html = str_replace(array_keys($configuration), array_values($configuration), $html);
echo $html;