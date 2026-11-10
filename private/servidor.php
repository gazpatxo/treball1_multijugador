<?php
session_start();
header('Content-Type: application/json');

$dades_entrada = json_decode(file_get_contents('php://input'), true);

// Lectura de l'identificador de l'usuari via sessió o galeta
$jugador_id = $_SESSION['user_name'] ?? $_COOKIE['jugador_sessio'] ?? null;

if (!$jugador_id) {
    die(json_encode(["error" => "No hi ha sessió activa"]));
}

// Recibir y validar el código de la sala
$lobby_code = $dades_entrada['lobby'] ?? $_GET['lobby'] ?? null;

if (!$lobby_code) {
    die(json_encode(["error" => "Codi de lobby no proporcionat"]));
}

$dades_entrada = json_decode(file_get_contents('php://input'), true);
if (!isset($_COOKIE['jugador_sessio'])) {
    die(json_encode(["error" => "No hi ha sessió"]));
}
$jugador_id = $_COOKIE['jugador_sessio'];

// Recibir y validar el código de la sala
$lobby_code = $dades_entrada['lobby'] ?? $_GET['lobby'] ?? null;

if (!$lobby_code) {
    die(json_encode(["error" => "Codi de lobby no proporcionat"]));
}

// Eliminador DE SALAS ABANDONADAS 
if (isset($dades_entrada['lobby']) && !isset($dades_entrada['x']) && !isset($dades_entrada['accio'])) {
    $archivos_lobby = glob(__DIR__ . '/partida_*.json');
    $tiempo_actual = time();

    foreach ($archivos_lobby as $archivo) {
        // Eliminar archivos que lleven más de 60 segundos sin modificarse
        if ($tiempo_actual - filemtime($archivo) > 60) {
            unlink($archivo); 
        }
    }
}


// Usar un archivo dinámico para cada sala (ej: partida_ABCD.json)
$arxiu_lobby = __DIR__ . '/partida_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($lobby_code)) . '.json';
$fp = fopen($arxiu_lobby, 'c+');

if ($fp === false) {
    http_response_code(500);
    die(json_encode([
        'error' => 'No es pot obrir o crear el lobby',
        'directori' => __DIR__
    ]));
}

flock($fp, LOCK_EX);

rewind($fp);
$contingut = stream_get_contents($fp);
$estat = $contingut ? json_decode($contingut, true) : [];

if (!is_array($estat)) {
    $estat = [];
}

if (!isset($estat['jugadors']) || !is_array($estat['jugadors'])) {
    $estat['jugadors'] = [];
}

// Inicialitzar el registre de presència
if (!isset($estat['presents_ronda'])) {
    $estat['presents_ronda'] = [];
}

// Registrar el jugador actual si no existeix
if (!isset($estat['jugadors'][$jugador_id])) {
    $num_jugadors = count($estat['jugadors']);

    // 3. Bloquear si ya hay 2 jugadores en esta sala
    if ($num_jugadors >= 2) {
        flock($fp, LOCK_UN);
        fclose($fp);
        die(json_encode(["error" => "La sala està plena"]));
    }

    $estat['jugadors'][$jugador_id] = [
        "x" => ($num_jugadors % 2 == 0) ? 0 : 15,
        "y" => 5,
        "size_x" => 1,
        "size_y" => 1
    ];
}

// Qualsevol petició del jugador confirma que és present
$estat['presents_ronda'][$jugador_id] = true;

// Assigna la posicio en ($x, $y) al jugador $id 
function set_position(&$estat, $id, $x, $y){
    $estat['jugadors'][$id]['x'] = $x;
    $estat['jugadors'][$id]['y'] = $y;
}

// Comprova si dues caixes de la graella col·lisionen
function caixes_colisionen($a_x, $a_y, $a_sx, $a_sy, $b_x, $b_y, $b_sx, $b_sy) {
    return ($a_x < $b_x + $b_sx &&
            $a_x + $a_sx > $b_x &&
            $a_y < $b_y + $b_sy &&
            $a_y + $a_sy > $b_y);
}

// Funció modular per generar obstacles aleatoris (amb plantilles)
function generar_nous_obstacles(&$estat) {
    $plantilles = [
        [
            ['x' => 3, 'y' => 2, 'size_x' => 2, 'size_y' => 1], 
            ['x' => 3, 'y' => 3, 'size_x' => 1, 'size_y' => 6], 
            ['x' => 3, 'y' => 9, 'size_x' => 2, 'size_y' => 1]  
        ],
        [
            ['x' => 2, 'y' => 1, 'size_x' => 1, 'size_y' => 4], 
            ['x' => 3, 'y' => 4, 'size_x' => 1, 'size_y' => 2],
            ['x' => 4, 'y' => 6, 'size_x' => 1, 'size_y' => 3], 
            ['x' => 5, 'y' => 9, 'size_x' => 1, 'size_y' => 2], 
            ['x' => 5, 'y' => 7, 'size_x' => 1, 'size_y' => 1],
            ['x' => 6, 'y' => 5, 'size_x' => 1, 'size_y' => 2]  
        ],
        [
            ['x' => 2, 'y' => 2, 'size_x' => 1, 'size_y' => 8], 
            ['x' => 3, 'y' => 2, 'size_x' => 4, 'size_y' => 1], 
            ['x' => 3, 'y' => 9, 'size_x' => 4, 'size_y' => 1], 
            ['x' => 6, 'y' => 3, 'size_x' => 1, 'size_y' => 2], 
            ['x' => 6, 'y' => 7, 'size_x' => 1, 'size_y' => 2]  
        ],
        [
            ['x' => 2, 'y' => 2, 'size_x' => 1, 'size_y' => 10], 
            ['x' => 5, 'y' => 0, 'size_x' => 1, 'size_y' => 8]
        ],
        [
            ['x' => 3, 'y' => 2, 'size_x' => 1, 'size_y' => 6], 
            ['x' => 3, 'y' => 8, 'size_x' => 5, 'size_y' => 1], 
            ['x' => 5, 'y' => 2, 'size_x' => 3, 'size_y' => 1], 
            ['x' => 5, 'y' => 3, 'size_x' => 1, 'size_y' => 3]
        ]
    ];

    $plantilla_escollida = $plantilles[array_rand($plantilles)];
    
    $estat['obstacles'] = [];

    foreach ($plantilla_escollida as $obs) {
        $estat['obstacles'][] = $obs;
        
        // Corregido el salto de línea roto
        $simetric_x = 16 - $obs['x'] - $obs['size_x'];
        
        $estat['obstacles'][] = [
            'x' => $simetric_x,
            'y' => $obs['y'],
            'size_x' => $obs['size_x'],
            'size_y' => $obs['size_y']
        ];
    }
}

function start_match(&$estat){
    foreach ($estat['jugadors'] as $id => $dades) {
        if (!isset($estat['presents_ronda'][$id])) {
            unset($estat['jugadors'][$id]);
        }
    }

    $estat['guanyador'] = null;
    $estat['timestamp_victoria'] = 0;

    $i = 0;
    foreach ($estat['jugadors'] as $id => $dades) {
        set_position($estat, $id, ($i % 2 == 0) ? 0 : 15, 5);
        $i++;
    }

    $estat['presents_ronda'] = [];

    generar_nous_obstacles($estat);
}

// Controla el moviment del jugador $id a la casella ($x,$y), retorna la posicio final del jugador
function handle_movement(&$estat, $id, $x, $y){
    $mida_x = $estat['jugadors'][$id]['size_x'] ?? 1;
    $mida_y = $estat['jugadors'][$id]['size_y'] ?? 1;

    $x = max(0, min(16 - $mida_x, $x));
    $y = max(0, min(12 - $mida_y, $y));

    if (isset($estat['obstacles'])) {
        foreach ($estat['obstacles'] as $obs) {
            if (caixes_colisionen($x, $y, $mida_x, $mida_y, $obs['x'], $obs['y'], $obs['size_x'], $obs['size_y'])) {
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

function position_inside_goal($goal, $x, $y){
    $first_cell_x = $goal['x'];
    $first_cell_y = $goal['y'];
    $last_cell_x  = $first_cell_x + $goal['size_x'];
    $last_cell_y  = $first_cell_y + $goal['size_y'];

    return ($x >= $first_cell_x && $x < $last_cell_x
         && $y >= $first_cell_y && $y < $last_cell_y);
}

// Definir la meta de forma automática si la sala es nueva
if (!isset($estat['win_area'])) {
    $estat['win_area'] = [
        "x" => 7,
        "y" => 5,
        "size_x" => 2,
        "size_y" => 2
    ];
}

// Generar los obstáculos iniciales si la sala no tiene
if (!isset($estat['obstacles']) || empty($estat['obstacles'])) {
    generar_nous_obstacles($estat);
}

// Processar accions del client
if ($dades_entrada) {
    $is_action_reset = isset($dades_entrada['accio']) && $dades_entrada['accio'] === 'reiniciar';
    $is_action_movement = ($estat['guanyador'] ?? null) === null && isset($dades_entrada['x'], $dades_entrada['y']);

    if ($is_action_reset) {
        start_match($estat);
    } elseif ($is_action_movement) {
        $positon = handle_movement($estat, $jugador_id, $dades_entrada['x'], $dades_entrada['y']);

        $temps_accio = $dades_entrada['timestamp'] ?? time();
        if (isset($estat['win_area']) && position_inside_goal($estat['win_area'], $positon['x'], $positon['y'])) {
            if ($estat['timestamp_victoria'] == 0 || $temps_accio < $estat['timestamp_victoria']) {
                $estat['guanyador'] = $jugador_id;
                $estat['timestamp_victoria'] = $temps_accio;
            }
        }
    }
}

// Guardar l'estat actualitzat
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($estat));
fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($estat);
?>