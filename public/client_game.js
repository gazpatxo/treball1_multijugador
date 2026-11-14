const canvas = document.getElementById('jocCanvas');
const ctx = canvas.getContext('2d');

console.log(el_meu_id);

let cell_size = 50; 
let grid_size = { x: 16, y: 12 };

let velocitat = 1;

let estat_joc = null;
let latencia = 0;

let pos_local = { x: null, y: null };
let havia_guanyador = false; // Per detectar quan hi ha hagut un reinici
let lobby_code = null; // NUEVO: Variable para guardar el código de la sala


// Cambiar a latencia mitjana dels ultims n valors
// Fer que tothom vegi la latencia de tothom
async function mesurarLatencia() {
    if (!lobby_code) return; // Detener si no hay sala
    const inici = Date.now();
    await fetch(`partida_${lobby_code}.json?t=${inici}`); // Buscar el archivo de la sala privada
    latencia = Date.now() - inici;
    document.getElementById('ping').innerText = latencia;
}
setInterval(mesurarLatencia, 1000);

async function sincronitzarEstat() {
    if (!lobby_code) return; // Detener si no hay sala
    try {
        const resposta = await fetch(`partida_${lobby_code}.json?t=${Date.now()}`);
        if (!resposta.ok) return; // Evitar errores si el archivo aún no se ha creado
        
        estat_joc = await resposta.json();
        
        // Adoptar coordenades del servidor si no en tenim de predites
        if (pos_local.x === null && estat_joc.jugadors[el_meu_id]) {
            pos_local.x = estat_joc.jugadors[el_meu_id].x;
            pos_local.y = estat_joc.jugadors[el_meu_id].y;
        }

        if (estat_joc.guanyador) {
            document.getElementById('missatge-victoria').innerText = "El guanyador és: " + estat_joc.guanyador;
            document.getElementById('boto-reiniciar').style.display = 'block';
            havia_guanyador = true;
        } else {
            document.getElementById('missatge-victoria').innerText = "";
            document.getElementById('boto-reiniciar').style.display = 'none';
            
            // Si abans hi havia guanyador i ara no, algú ha reiniciat la partida.
            // Resegem la nostra posició local perquè forci l'actualització amb l'spawn del servidor.
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
    await fetch('servidor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accio: 'reiniciar', lobby: lobby_code }) // Enviar el código al reiniciar
    });
    pos_local = { x: null, y: null };
});

async function enviarMoviment() {
    if (estat_joc && estat_joc.guanyador) return;
    const timestamp_just = Date.now() - latencia; 
    
    // Añadir el código de la sala en el envío de movimiento
    const payload = { 
        x: pos_local.x, 
        y: pos_local.y, 
        timestamp: timestamp_just,
        lobby: lobby_code 
    };

    await fetch('servidor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
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
            ctx.fillText(id, x_dibuix*cell_size+cell_size/4, y_dibuix*cell_size+cell_size/2);
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
    const accio = prompt("Escriu 'C' per Crear una sala nova, o 'U' per Unir-te a una existent:");
    
    if (accio && accio.toUpperCase() === 'C') {
        lobby_code = Math.random().toString(36).substring(2, 6).toUpperCase();
        alert("El teu codi de sala és: " + lobby_code + "\nComparteix-lo amb el teu amic.");
        
        // Forma SEGURA de actualizar el HTML
        const displayCode = document.getElementById('display-lobby-code');
        if (displayCode) { displayCode.innerText = lobby_code; }
        
        iniciarPartidaLobby();
        
    } else if (accio && accio.toUpperCase() === 'U') {
        const codi = prompt("Introdueix el codi de la sala:");
        if (codi) {
            lobby_code = codi.toUpperCase();
            
            // Forma SEGURA de actualizar el HTML
            const displayCode = document.getElementById('display-lobby-code');
            if (displayCode) { displayCode.innerText = lobby_code; }
            
            iniciarPartidaLobby();
        } else {
            alert("No has introduït cap codi.");
        }
    } else {
        alert("Acció no vàlida. Recarrega la pàgina.");
    }
}

// Iniciar la partida después de introducir/crear el lobby
async function iniciarPartidaLobby() {
    try {
        const resposta = await fetch('servidor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lobby: lobby_code })
        });

        const data = await resposta.json();
        
        if (data.error) {
            alert("Error: " + data.error);
            lobby_code = null;
            return;
        }

        estat_joc = data;
        await sincronitzarEstat();
        dibuixar();

    } catch (error) {
        console.error('Error al iniciar el juego:', error);
    }
}

// Arrancar el menú al cargar en lugar del antiguo iniciarJoc()
mostrarMenuLobby();