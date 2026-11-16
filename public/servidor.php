<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// ==================================================
// CONFIGURACIÓN
// ==================================================

$db_path = __DIR__ . '/../private/partidas.db';

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================================================
// COMPROBAR SESIÓN
// ==================================================

$jugador_id = $_SESSION['user_name']
    ?? $_COOKIE['jugador_sessio']
    ?? null;

if (!$jugador_id) {
    responder(["error" => "No hi ha sessió activa"], 401);
}

$jugador_id = (string)$jugador_id;

// ==================================================
// RECIBIR Y VALIDAR PETICIÓN
// ==================================================

$entrada = json_decode(file_get_contents('php://input'), true);

if (!is_array($entrada)) {
    $entrada = [];
}

$lobby_code = $entrada['lobby'] ?? $_GET['lobby'] ?? null;
$accio = $entrada['accio'] ?? $_GET['accio'] ?? '';

if (!is_string($lobby_code) || $lobby_code === '') {
    responder(["error" => "Codi de lobby no proporcionat"], 400);
}

$lobby_code = strtoupper($lobby_code);

if (!preg_match('/^[A-Z0-9]{1,12}$/', $lobby_code)) {
    responder(["error" => "Codi de lobby invàlid"], 400);
}

// ==================================================
// CONEXIÓN SQLITE
// ==================================================

try {

    $db = new PDO('sqlite:' . $db_path);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');

    $db->exec("
        CREATE TABLE IF NOT EXISTS lobbies (
            codigo TEXT PRIMARY KEY,
            estado TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )
    ");

} catch (Throwable $e) {

    error_log("SQLite: " . $e->getMessage());

    responder([
        "error" => "Error de connexió amb SQLite"
    ], 500);
}

// ==================================================
// FUNCIONES DEL JUEGO
// ==================================================

function set_position(&$estat, $id, $x, $y) {

    $estat['jugadors'][$id]['x'] = $x;
    $estat['jugadors'][$id]['y'] = $y;
}


// Comprobar colisiones entre dos cajas

function caixes_colisionen(
    $a_x, $a_y, $a_sx, $a_sy,
    $b_x, $b_y, $b_sx, $b_sy
) {

    return (
        $a_x < $b_x + $b_sx &&
        $a_x + $a_sx > $b_x &&
        $a_y < $b_y + $b_sy &&
        $a_y + $a_sy > $b_y
    );
}


// ==================================================
// GENERAR OBSTÁCULOS
// ==================================================

function generar_nous_obstacles(&$estat) {

    $plantilles = [

        [
            ['x'=>3,'y'=>2,'size_x'=>2,'size_y'=>1],
            ['x'=>3,'y'=>3,'size_x'=>1,'size_y'=>6],
            ['x'=>3,'y'=>9,'size_x'=>2,'size_y'=>1]
        ],

        [
            ['x'=>2,'y'=>1,'size_x'=>1,'size_y'=>4],
            ['x'=>3,'y'=>4,'size_x'=>1,'size_y'=>2],
            ['x'=>4,'y'=>6,'size_x'=>1,'size_y'=>3],
            ['x'=>5,'y'=>9,'size_x'=>1,'size_y'=>2],
            ['x'=>5,'y'=>7,'size_x'=>1,'size_y'=>1],
            ['x'=>6,'y'=>5,'size_x'=>1,'size_y'=>2]
        ],

        [
            ['x'=>2,'y'=>2,'size_x'=>1,'size_y'=>8],
            ['x'=>3,'y'=>2,'size_x'=>4,'size_y'=>1],
            ['x'=>3,'y'=>9,'size_x'=>4,'size_y'=>1],
            ['x'=>6,'y'=>3,'size_x'=>1,'size_y'=>2],
            ['x'=>6,'y'=>7,'size_x'=>1,'size_y'=>2]
        ],

        [
            ['x'=>2,'y'=>2,'size_x'=>1,'size_y'=>10],
            ['x'=>5,'y'=>0,'size_x'=>1,'size_y'=>8]
        ],

        [
            ['x'=>3,'y'=>2,'size_x'=>1,'size_y'=>6],
            ['x'=>3,'y'=>8,'size_x'=>5,'size_y'=>1],
            ['x'=>5,'y'=>2,'size_x'=>3,'size_y'=>1],
            ['x'=>5,'y'=>3,'size_x'=>1,'size_y'=>3]
        ]
    ];

    $plantilla = $plantilles[array_rand($plantilles)];

    $estat['obstacles'] = [];

    foreach ($plantilla as $obs) {

        $estat['obstacles'][] = $obs;

        // Generar obstáculo simétrico

        $simetric_x = 16 - $obs['x'] - $obs['size_x'];

        $estat['obstacles'][] = [
            'x' => $simetric_x,
            'y' => $obs['y'],
            'size_x' => $obs['size_x'],
            'size_y' => $obs['size_y']
        ];
    }
}


// ==================================================
// REINICIAR PARTIDA
// ==================================================

function start_match(&$estat) {

    // Eliminar jugadores que no están presentes

    foreach ($estat['jugadors'] as $id => $dades) {

        if (!isset($estat['presents_ronda'][$id])) {
            unset($estat['jugadors'][$id]);
        }
    }

    $estat['guanyador'] = null;
    $estat['timestamp_victoria'] = 0;

    // Recolocar jugadores

    $i = 0;

    foreach ($estat['jugadors'] as $id => $dades) {

        set_position(
            $estat,
            $id,
            ($i % 2 == 0) ? 0 : 15,
            5
        );

        $i++;
    }

    // Reiniciar presencia

    $estat['presents_ronda'] = [];

    // Generar nuevo mapa

    generar_nous_obstacles($estat);
}


// ==================================================
// MOVIMIENTO DEL JUGADOR
// ==================================================

// ==================================================
// ANTICHEAT: VALIDAR DISTANCIA DE MOVIMIENTO
// ==================================================

function movimiento_valido($estat, $id, $x_nuevo, $y_nuevo) {

    // Posición real guardada en el servidor
    $x_anterior = (int)$estat['jugadors'][$id]['x'];
    $y_anterior = (int)$estat['jugadors'][$id]['y'];

    // Posición solicitada
    $x_nuevo = (int)$x_nuevo;
    $y_nuevo = (int)$y_nuevo;

    // Calcular desplazamiento
    $distancia_x = abs($x_nuevo - $x_anterior);
    $distancia_y = abs($y_nuevo - $y_anterior);

    // Solo permitir desplazamiento de una casilla
    // en horizontal O vertical, nunca en ambas direcciones.
    return (
        ($distancia_x === 1 && $distancia_y === 0) ||
        ($distancia_x === 0 && $distancia_y === 1) ||
        ($distancia_x === 0 && $distancia_y === 0)
    );
}

function handle_movement(&$estat, $id, $x, $y) {

    $mida_x = $estat['jugadors'][$id]['size_x'] ?? 1;
    $mida_y = $estat['jugadors'][$id]['size_y'] ?? 1;

    // Limitar movimiento dentro del mapa

    $x = max(0, min(16 - $mida_x, (int)$x));
    $y = max(0, min(12 - $mida_y, (int)$y));

    // Comprobar colisiones

    if (isset($estat['obstacles'])) {

        foreach ($estat['obstacles'] as $obs) {

            if (caixes_colisionen(
                $x, $y, $mida_x, $mida_y,
                $obs['x'], $obs['y'],
                $obs['size_x'], $obs['size_y']
            )) {

                return [
                    "x" => $estat['jugadors'][$id]['x'],
                    "y" => $estat['jugadors'][$id]['y']
                ];
            }
        }
    }

    set_position($estat, $id, $x, $y);

    return [
        "x" => $x,
        "y" => $y
    ];
}


// ==================================================
// COMPROBAR VICTORIA
// ==================================================

function position_inside_goal($goal, $x, $y) {

    return (
        $x >= $goal['x'] &&
        $x < $goal['x'] + $goal['size_x'] &&
        $y >= $goal['y'] &&
        $y < $goal['y'] + $goal['size_y']
    );
}


// ==================================================
// PROCESAMIENTO DEL LOBBY
// ==================================================

$transaccion = false;

try {

    // Bloquear escritura para evitar modificaciones simultáneas

    $db->exec('BEGIN IMMEDIATE');
    $transaccion = true;

    // --------------------------------------------------
    // ELIMINAR SALAS ABANDONADAS (60 SEGUNDOS)
    // --------------------------------------------------

    $stmt = $db->prepare("
        DELETE FROM lobbies
        WHERE updated_at <= :limite
    ");

    $stmt->execute([
        ':limite' => time() - 10
    ]);

    // --------------------------------------------------
    // CARGAR LOBBY
    // --------------------------------------------------

    $stmt = $db->prepare("
        SELECT estado, updated_at
        FROM lobbies
        WHERE codigo = :codigo
    ");
    
    $stmt->execute([
        ':codigo' => $lobby_code
    ]);

    $fila = $stmt->fetch();

    // Si la sala no existe, solo se permite crearla
    // mediante una petición de entrada inicial.

    if (!$fila && $accio !== 'entrar') {

        $db->exec('ROLLBACK');
        $transaccion = false;

        responder([
            "error" => "La sala no existe o ha caducado"
        ], 410);
    }

    if ($fila) {

        $estat = json_decode($fila['estado'], true);

        if (!is_array($estat)) {
            throw new RuntimeException("Estat de lobby invàlid");
        }

    } else {

        $estat = [];
    }

    // --------------------------------------------------
    // INICIALIZAR ESTADO
    // --------------------------------------------------

    if (!isset($estat['jugadors']) || !is_array($estat['jugadors'])) {
        $estat['jugadors'] = [];
    }

    if (!isset($estat['presents_ronda']) || !is_array($estat['presents_ronda'])) {
        $estat['presents_ronda'] = [];
    }

    if (!array_key_exists('guanyador', $estat)) {
        $estat['guanyador'] = null;
    }

    if (!isset($estat['timestamp_victoria'])) {
        $estat['timestamp_victoria'] = 0;
    }

    // --------------------------------------------------
    // REGISTRAR JUGADOR
    // --------------------------------------------------

    if (!isset($estat['jugadors'][$jugador_id])) {

        $num_jugadors = count($estat['jugadors']);

        // Máximo dos jugadores

        if ($num_jugadors >= 2) {

            $db->exec('ROLLBACK');
            $transaccion = false;

            responder([
                "error" => "La sala està plena"
            ], 403);
        }

        $estat['jugadors'][$jugador_id] = [
            "x" => ($num_jugadors % 2 == 0) ? 0 : 15,
            "y" => 5,
            "size_x" => 1,
            "size_y" => 1
        ];
    }

    // Registrar presencia

    $estat['presents_ronda'][$jugador_id] = true;

    // --------------------------------------------------
    // INICIALIZAR META
    // --------------------------------------------------

    if (!isset($estat['win_area'])) {

        $estat['win_area'] = [
            "x" => 7,
            "y" => 5,
            "size_x" => 2,
            "size_y" => 2
        ];
    }

    // --------------------------------------------------
    // INICIALIZAR OBSTÁCULOS
    // --------------------------------------------------

    if (!isset($estat['obstacles']) || empty($estat['obstacles'])) {

        generar_nous_obstacles($estat);
    }

    // --------------------------------------------------
    // PROCESAR ACCIONES DEL CLIENTE
    // --------------------------------------------------

    if (!empty($entrada)) {

        // Reiniciar partida

        $is_action_reset = (
            isset($entrada['accio']) &&
            $entrada['accio'] === 'reiniciar'
        );

        // Movimiento

        $is_action_movement = (
            ($estat['guanyador'] ?? null) === null &&
            isset($entrada['x'], $entrada['y']) &&
            is_numeric($entrada['x']) &&
            is_numeric($entrada['y'])
        );

        if ($is_action_reset) {

            start_match($estat);

        } elseif ($is_action_movement) {

            // ANTICHEAT: comprobar que el movimiento es válido
            if (!movimiento_valido(
                $estat,
                $jugador_id,
                $entrada['x'],
                $entrada['y']
            )) {

                // Ignorar el movimiento sospechoso
                $posicio = [
                    "x" => $estat['jugadors'][$jugador_id]['x'],
                    "y" => $estat['jugadors'][$jugador_id]['y']
                ];

            } else {

                // Movimiento permitido
                $posicio = handle_movement(
                    $estat,
                    $jugador_id,
                    $entrada['x'],
                    $entrada['y']
                );
            }

            // Mantener el sistema de timestamp del primer archivo

            $temps_accio = isset($entrada['timestamp'])
                && is_numeric($entrada['timestamp'])
                ? $entrada['timestamp']
                : time();

            // Comprobar victoria

            if (position_inside_goal(
                $estat['win_area'],
                $posicio['x'],
                $posicio['y']
            )) {

                if (
                    $estat['timestamp_victoria'] == 0 ||
                    $temps_accio < $estat['timestamp_victoria']
                ) {

                    $estat['guanyador'] = $jugador_id;
                    $estat['timestamp_victoria'] = $temps_accio;
                }
            }
        }
    }

    // --------------------------------------------------
    // GUARDAR ESTADO EN SQLITE
    // --------------------------------------------------

    $estat_json = json_encode(
        $estat,
        JSON_UNESCAPED_UNICODE
    );

    if ($estat_json === false) {
        throw new RuntimeException("Error codificant l'estat");
    }

    $stmt = $db->prepare("
        INSERT INTO lobbies (
            codigo,
            estado,
            updated_at
        )
        VALUES (
            :codigo,
            :estado,
            :updated_at
        )
        ON CONFLICT(codigo)
        DO UPDATE SET
            estado = excluded.estado,
            updated_at = excluded.updated_at
    ");

    // Actualizar el tiempo de actividad únicamente
    // cuando la petición procede de sincronitzarEstat().

    $ultimo_update = in_array($accio, ['entrar', 'sincronitzar'], true)
        ? time()
        : (int)$fila['updated_at'];

    $stmt->execute([
        ':codigo' => $lobby_code,
        ':estado' => $estat_json,
        ':updated_at' => $ultimo_update
    ]);
    // --------------------------------------------------
    // CONFIRMAR TRANSACCIÓN
    // --------------------------------------------------

    $db->exec('COMMIT');
    $transaccion = false;

    // --------------------------------------------------
    // RESPUESTA AL CLIENTE
    // --------------------------------------------------

    echo $estat_json;

} catch (Throwable $e) {

    // Deshacer cambios si hay algún error

    if ($transaccion) {

        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollback_error) {
            // Ignorar error de rollback
        }
    }

    error_log("Error serverConSql.php: " . $e->getMessage());

    responder([
        "error" => "Error intern del servidor"
    ], 500);
}

?>
