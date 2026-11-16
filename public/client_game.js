const canvas = document.getElementById('jocCanvas');
const ctx = canvas.getContext('2d');

const SERVER_URL = 'servidor.php';

console.log(el_meu_id);

let cell_size = 50; 
let grid_size = { x: 16, y: 12 };

let velocitat = 1;

let estat_joc = null;
let latencia = 0;

let pos_local = { x: null, y: null };
let havia_guanyador = false; // Per detectar quan hi ha hagut un reinici
let lobby_code = null; // Variable para guardar el código de la sala

let puntuacions = {};
let victoria_registrada = false;


// Cambiar a latencia mitjana dels ultims n valors
// Fer que tothom vegi la latencia de tothom
async function mesurarLatencia() {
    if (!lobby_code) return;

    const inici = Date.now();

    try {
        const resposta = await fetch(
            `${SERVER_URL}?lobby=${encodeURIComponent(lobby_code)}&accio=ping&t=${inici}`,
            { cache: 'no-store' }
        );

        if (resposta.ok) {
            latencia = Date.now() - inici;
            document.getElementById('ping').innerText = latencia;
        }
    } catch (e) {
        console.error("Error mesurant latència:", e);
    }
}
setInterval(mesurarLatencia, 1000);

async function sincronitzarEstat() {
    if (!lobby_code) return; 
    try {
        const resposta = await fetch(`${SERVER_URL}?lobby=${encodeURIComponent(lobby_code)}&accio=sincronitzar&t=${Date.now()}`);
        if (!resposta.ok) return; 
        
        estat_joc = await resposta.json();

        // Añadimos la comprobación de desconexión del oponente
        if (estat_joc.tancada) {
            alert(estat_joc.motiu);
            lobby_code = null;
            window.location.href = '?page=game';
            return;
        }
        
        // Actualizamos el marcador visual con los datos que trae el servidor
        actualitzarMarcador();
        
        if (estat_joc.jugadors[el_meu_id]) {
            // Inicializar si es la primera vez
            if (pos_local.x === null) {
                pos_local.x = estat_joc.jugadors[el_meu_id].x;
                pos_local.y = estat_joc.jugadors[el_meu_id].y;
            } else {
                // Si el servidor y el cliente se desincronizan por más de 1 casilla (movimiento rechazado), 
                // forzamos al cliente a volver a la posición real del servidor
                const dist_x = Math.abs(pos_local.x - estat_joc.jugadors[el_meu_id].x);
                const dist_y = Math.abs(pos_local.y - estat_joc.jugadors[el_meu_id].y);
                
                if (dist_x > 1 || dist_y > 1) {
                    pos_local.x = estat_joc.jugadors[el_meu_id].x;
                    pos_local.y = estat_joc.jugadors[el_meu_id].y;
                }
            }
        }

        if (estat_joc.guanyador) {
            document.getElementById('missatge-victoria').innerText = "El guanyador és: " + estat_joc.guanyador;
            document.getElementById('boto-reiniciar').style.display = 'block';
            havia_guanyador = true;
            
            // Eliminamos todo el bloque de 'victoria_registrada' y 'puntuacions' 
            // porque el servidor (SQLite) ya suma el punto de forma automática.

        } else {
            document.getElementById('missatge-victoria').innerText = "";
            document.getElementById('boto-reiniciar').style.display = 'none';
            
            // Eliminamos el reseteo de 'victoria_registrada' aquí también.
            
            if (havia_guanyador) {
                pos_local = { x: null, y: null };
                havia_guanyador = false;
            }
        }
    } catch (e) { console.error("Error sincronitzant", e); }
}

setInterval(sincronitzarEstat, 100); 

// Botó de reiniciar
document.getElementById('boto-reiniciar').addEventListener('click', async () => {

    if (!lobby_code) return;

    try {
        const resposta = await fetch(SERVER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                accio: 'reiniciar',
                lobby: lobby_code
            })
        });

        const data = await resposta.json();

        if (!resposta.ok || data.error) {
            console.error("Error reiniciant:", data.error);
            return;
        }

        estat_joc = data;
        pos_local = { x: null, y: null };

    } catch (e) {
        console.error("Error reiniciant la partida:", e);
    }
});

async function enviarMoviment() {

    if (estat_joc && estat_joc.guanyador) return;
    if (pos_local.x === null || !lobby_code) return;

    const timestamp_just = Date.now() - latencia;

    const payload = {
        accio: 'moviment',
        x: pos_local.x,
        y: pos_local.y,
        timestamp: timestamp_just,
        lobby: lobby_code
    };

    try {
        const resposta = await fetch(SERVER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        if (!resposta.ok) {
            console.error("Error enviant moviment:", resposta.status);
        }

    } catch (e) {
        console.error("Error enviant moviment:", e);
    }
}

window.addEventListener('keydown', (e) => {
    if(e.repeat) return;

    const teclas_moviment = [
        'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
        'w', 'a', 's', 'd',
        'W', 'A', 'S', 'D'
    ];

    if (!teclas_moviment.includes(e.key)) return;

    e.preventDefault();

    if (pos_local.x === null || (estat_joc && estat_joc.guanyador)) return;

    // NUEVO: Bloquear el movimiento si no hay 2 jugadores en la sala
    if (estat_joc && Object.keys(estat_joc.jugadors).length < 2) {
        console.log("Esperant al segon jugador...");
        return; 
    }
    
    let mogut = false;

    function move_to(dir_x, dir_y){
        let nova_x = pos_local.x + dir_x;
        let nova_y = pos_local.y + dir_y;

        nova_x = Math.max(0, Math.min(grid_size.x - 1, nova_x));
        nova_y = Math.max(0, Math.min(grid_size.y - 1, nova_y));

        let colisio = false;
        let mida_x = estat_joc.jugadors[el_meu_id] ? estat_joc.jugadors[el_meu_id].size_x : 1;
        let mida_y = estat_joc.jugadors[el_meu_id] ? estat_joc.jugadors[el_meu_id].size_y : 1;

        if (estat_joc && estat_joc.obstacles) {
            for (let obs of estat_joc.obstacles) {
                if (caixes_colisionen(nova_x, nova_y, mida_x, mida_y, obs.x, obs.y, obs.size_x, obs.size_y)) {
                    colisio = true;
                    break;
                }
            }
        }

        if (!colisio) {
            pos_local.x = nova_x;
            pos_local.y = nova_y;
            mogut = true;
        }
    }

    if (e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') move_to(0, -velocitat);
    if (e.key === 'ArrowDown' || e.key === 's' || e.key === 'S') move_to(0, velocitat);
    if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') move_to(-velocitat, 0);
    if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') move_to(velocitat, 0);

    if (mogut) {
        enviarMoviment();
    }
});

function dibuixar() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (estat_joc) {
        ctx.fillStyle = 'blue';
        const c = estat_joc.win_area;
        ctx.fillRect(c.x*cell_size, c.y*cell_size, c.size_x*cell_size, c.size_y*cell_size);

        if (estat_joc.obstacles) {
            ctx.fillStyle = 'black';
            for (let obs of estat_joc.obstacles) {
                ctx.fillRect(obs.x * cell_size, obs.y * cell_size, obs.size_x * cell_size, obs.size_y * cell_size);
            }
        }
        for (const [id, dades] of Object.entries(estat_joc.jugadors)) {
            ctx.fillStyle = (id === el_meu_id) ? 'green' : 'red';
            const x_dibuix = (id === el_meu_id && pos_local.x !== null) ? pos_local.x : dades.x;
            const y_dibuix = (id === el_meu_id && pos_local.y !== null) ? pos_local.y : dades.y;
            ctx.fillRect(x_dibuix*cell_size, y_dibuix*cell_size, dades.size_x*cell_size, dades.size_y*cell_size);
            ctx.fillStyle = 'black';
            
            // Limitar el nombre mostrado a los primeros 5 caracteres
            const nom_mostrat = id.substring(0, 5);
            ctx.fillText(nom_mostrat, x_dibuix*cell_size+cell_size/4, y_dibuix*cell_size+cell_size/2);
        }
    }
    requestAnimationFrame(dibuixar);
}

function caixes_colisionen(a_x, a_y, a_sx, a_sy, b_x, b_y, b_sx, b_sy) {
    return (a_x < b_x + b_sx &&
            a_x + a_sx > b_x &&
            a_y < b_y + b_sy &&
            a_y + a_sy > b_y);
}

// Menú de selección de Lobby
function mostrarMenuLobby() {
    const accions_valides = ['C', 'U'];
    
    let tipus_accio = '';
    while(tipus_accio === ''){
        const accio = prompt("Escriu 'C' per Crear una sala nova, o 'U' per Unir-te a una existent:");
        if (accio && accions_valides.includes(accio.toUpperCase())) {
            tipus_accio = accio.toUpperCase();
        } else {
            alert("Acció no vàlida.");
        }
    }

    if (tipus_accio === 'C'){
        hostPartida();
    } else if (tipus_accio === 'U') {
        joinPartida();
    }

}

function hostPartida(){
    lobby_code = Math.random().toString(36).substring(2, 6).toUpperCase();
    alert("El teu codi de sala és: " + lobby_code + "\nComparteix-lo amb el teu amic.");
    
    // Forma SEGURA de actualizar el HTML
    const displayCode = document.getElementById('display-lobby-code');
    if (displayCode) { displayCode.innerText = lobby_code; }
    
    iniciarPartidaLobby();
}

async function joinPartida(){
    const codi = prompt("Introdueix el codi de la sala:");
    if (codi) {
        lobby_code = codi.toUpperCase();
        
        const displayCode = document.getElementById('display-lobby-code');
        if (displayCode) { displayCode.innerText = lobby_code; }
        
        // Llamamos con acción 'entrar'
        await iniciarPartidaLobby('entrar');
    } else {
        alert("No hai introduït cap codi.");
        mostrarMenuLobby();
    }
}

// Iniciar la partida después de introducir/crear el lobby
async function iniciarPartidaLobby(accioType = 'entrar') {
    try {
        const resposta = await fetch(SERVER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                accio: accioType, // Usamos la acción correcta aquí
                lobby: lobby_code
            })
        });

        const data = await resposta.json();

        if (!resposta.ok || data.error) {
            alert("Error: " + (data.error || resposta.status));
            lobby_code = null;
            mostrarMenuLobby(); // Vuelve a mostrar el menú para que pueda reintentar
            return;
        }

        estat_joc = data;
        pos_local = { x: null, y: null };

        await sincronitzarEstat();
        dibuixar();

    } catch (error) {
        console.error('Error al iniciar el juego:', error);
    }
}


function actualitzarMarcador() {
    if (!estat_joc || !estat_joc.jugadors) return;
    
    const jugadors_ids = Object.keys(estat_joc.jugadors);
    
    let jugador1 = el_meu_id;
    let jugador2 = jugadors_ids.find(id => id !== el_meu_id);

    // Leer los puntos del JSON del servidor (si no existen, poner 0)
    const punts_j1 = (estat_joc.puntuacions && estat_joc.puntuacions[jugador1]) ? estat_joc.puntuacions[jugador1] : 0;

    document.getElementById('nom-esquerra').innerText = jugador1.substring(0, 5);
    document.getElementById('punts-esquerra').innerText = punts_j1;

    if (jugador2) {
        const punts_j2 = (estat_joc.puntuacions && estat_joc.puntuacions[jugador2]) ? estat_joc.puntuacions[jugador2] : 0;

        document.getElementById('nom-dreta').innerText = jugador2.substring(0, 5) + ": ";
        document.getElementById('nom-dreta').style.color = "red";
        document.getElementById('nom-dreta').style.fontStyle = "normal";
        
        document.getElementById('punts-dreta').style.display = "inline";
        document.getElementById('punts-dreta').innerText = punts_j2;
    } else {
        document.getElementById('nom-dreta').innerText = "Esperando segundo jugador...";
        document.getElementById('nom-dreta').style.color = "gray";
        document.getElementById('nom-dreta').style.fontStyle = "italic";
        document.getElementById('punts-dreta').style.display = "none";
    }
}

// Arrancar el menú al cargar en lugar del antiguo iniciarJoc()
mostrarMenuLobby();