// Configuración y funciones comunes
const API_URL = "logica/api.php";
let tokenCsrf = "";
let usuarioActual = null;
const modoVistaPrevia = new URLSearchParams(window.location.search).get("preview") === "1";

const notificar = (mensaje, error = false) => {
    let aviso = document.querySelector(".aviso-sitio");
    if (!aviso) {
        aviso = document.createElement("div");
        aviso.className = "aviso-sitio";
        aviso.setAttribute("role", "status");
        document.body.append(aviso);
    }
    aviso.textContent = mensaje;
    aviso.classList.toggle("aviso-sitio--error", error);
    aviso.classList.add("aviso-sitio--visible");
    window.clearTimeout(aviso.timeoutId);
    aviso.timeoutId = window.setTimeout(() => aviso.classList.remove("aviso-sitio--visible"), 4000);
};

const llamarApi = async (accion, opciones = {}) => {
    const metodo = opciones.method || "GET";
    const url = new URL(API_URL, window.location.href);
    if (modoVistaPrevia) url.searchParams.set("preview", "1");
    if (metodo === "GET") {
        url.searchParams.set("action", accion);
        Object.entries(opciones.params || {}).forEach(([clave, valor]) => {
            if (valor !== null && valor !== undefined && valor !== "") {
                url.searchParams.set(clave, valor);
            }
        });
    } else {
        url.searchParams.set("action", accion);
    }

    const cabeceras = { Accept: "application/json" };
    let cuerpo = opciones.body;
    if (metodo !== "GET") {
        cabeceras["X-CSRF-Token"] = tokenCsrf;
        if (!(cuerpo instanceof FormData)) {
            cabeceras["Content-Type"] = "application/json";
            cuerpo = JSON.stringify({ ...cuerpo, action: accion });
        }
    }

    const respuesta = await fetch(url, { method: metodo, headers: cabeceras, body: cuerpo, credentials: "same-origin" });
    const datos = await respuesta.json().catch(() => ({}));
    if (!respuesta.ok) {
        if (respuesta.status === 401) {
            window.location.href = "index.php";
        }
        throw new Error(datos.error || "No se pudo completar la solicitud.");
    }
    return datos;
};

const escaparTexto = (valor) => String(valor ?? "");
const formatoFecha = (valor) => {
    if (!valor) return "";
    const fecha = new Date(String(valor).replace(" ", "T"));
    return Number.isNaN(fecha.getTime()) ? String(valor) : fecha.toLocaleString("es-MX", { dateStyle: "medium", timeStyle: "short" });
};

const cargarSesion = async () => {
    const datosToken = await fetch(`${API_URL}?action=csrf`, { credentials: "same-origin" }).then((respuesta) => respuesta.json());
    tokenCsrf = datosToken.token_csrf;
    const datos = await llamarApi("me");
    usuarioActual = datos.perfil;
    if (Number(usuarioActual.es_administrador) === 1) {
        const nav = document.querySelector(".barra-superior__nav");
        if (nav && !nav.querySelector('a[href="administracion.html"]')) {
            const enlaceAdmin = crearElemento("a", "", "Administración");
            enlaceAdmin.href = "administracion.html";
            nav.append(enlaceAdmin);
        }
    }
    return datos;
};

const aplicarImagenPerfil = (perfil, incluirPerfilCompleto = false) => {
    if (incluirPerfilCompleto) {
        const portada = document.querySelector(".perfil__portada");
        const avatarPerfil = document.querySelector(".perfil__imagen");
        if (portada)
        {
            portada.style.backgroundImage = perfil.url_portada ? `url("${encodeURI(perfil.url_portada)}")` : "";
            if(perfil.url_portada)
            {
                portada.classList.remove("perfil__portada");
                portada.classList.add("perfil__portada-act");
            }
        } 
        if (avatarPerfil) avatarPerfil.style.backgroundImage = perfil.url_avatar ? `url("${encodeURI(perfil.url_avatar)}")` : "";
    }

    document.querySelectorAll(".posts__principal .posts__imagen, footer.posts__up .posts__imagen").forEach((avatar) => {
        if (!avatar.dataset.avatarPublicacion) {
            avatar.style.backgroundImage = perfil.url_avatar ? `url("${encodeURI(perfil.url_avatar)}")` : "";
        }
    });
};

const prepararBusquedaGlobal = () => {
    const contenedor = document.querySelector(".barra-superior__busqueda");
    const entrada = contenedor?.querySelector('input');
    if (!contenedor || !entrada) return;

    contenedor.style.position = "relative";
    const resultados = document.createElement("div");
    resultados.className = "barra-superior__resultados no-mostrar";
    contenedor.append(resultados);

    let temporizador;
    entrada.addEventListener("input", () => {
        window.clearTimeout(temporizador);
        const texto = entrada.value.trim();
        if (texto.length < 2) {
            resultados.classList.add("no-mostrar");
            resultados.replaceChildren();
            return;
        }

        temporizador = window.setTimeout(async () => {
            try {
                const datos = await llamarApi("buscar_usuarios", { params: { q: texto } });
                resultados.replaceChildren();

                if (!datos.usuarios.length) {
                    resultados.append(crearElemento("p", "barra-superior__sin-resultados", "No se encontraron usuarios."));
                }

                datos.usuarios.forEach((usuario) => {
                    const boton = crearElemento("button", "barra-superior__resultado");
                    boton.type = "button";

                    const avatar = crearElemento("span", "barra-superior__resultado-avatar");
                    if (usuario.url_avatar) {
                        avatar.style.backgroundImage = `url("${encodeURI(usuario.url_avatar)}")`;
                    }

                    const nombre = crearElemento("span", "barra-superior__resultado-nombre");
                    nombre.textContent = usuario.gamertag || usuario.nombre;
                    boton.append(avatar, nombre);
                    boton.addEventListener("click", () => {
                        window.location.href = `perfil.html?usuario_id=${usuario.usuario_id}`;
                    });
                    resultados.append(boton);
                });
                resultados.classList.remove("no-mostrar");
            } catch (error) {
                notificar(error.message, true);
            }
        }, 300);
    });

    document.addEventListener("click", (evento) => {
        if (!contenedor.contains(evento.target)) {
            resultados.classList.add("no-mostrar");
        }
    });
};

const crearElemento = (etiqueta, clase, texto) => {
    const elemento = document.createElement(etiqueta);
    if (clase) elemento.className = clase;
    if (texto !== undefined) elemento.textContent = escaparTexto(texto);
    return elemento;
};

const crearBoton = (clase, texto, etiqueta) => {
    const boton = crearElemento("button", clase, texto);
    boton.type = "button";
    if (etiqueta) boton.setAttribute("aria-label", etiqueta);
    return boton;
};

const crearIcono = (ruta) => {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    const trazo = document.createElementNS("http://www.w3.org/2000/svg", "path");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "2");
    svg.setAttribute("stroke-linecap", "round");
    svg.setAttribute("stroke-linejoin", "round");
    svg.setAttribute("aria-hidden", "true");
    trazo.setAttribute("d", ruta);
    svg.append(trazo);
    return svg;
};

// Barra de navegación y notificaciones
const prepararBarra = () => {
    const botonSalir = document.getElementById("boton-salir");
    botonSalir?.addEventListener("click", async () => {
        try {
            await fetch("logica/cerrar-sesion.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ token_csrf: tokenCsrf }),
                credentials: "same-origin",
            });
        } catch (error) {
            notificar(error.message, true);
        } finally {
            window.location.href = "index.php";
        }
    });

    document.getElementById("boton-mensaje")?.addEventListener("click", () => {
        window.location.href = "chat.html";
    });

    const botonNotificaciones = document.querySelector(".barra-superior__botones button:nth-child(2)");
    if (botonNotificaciones) {
        const panel = document.getElementById("ventana-notificaciones") || document.createElement("section");
        panel.id = "ventana-notificaciones";
        panel.className = "ventana-notificaciones no-mostrar";
        panel.setAttribute("role", "region");
        panel.setAttribute("aria-label", "Notificaciones");
        botonNotificaciones.setAttribute("aria-label", "Notificaciones");
        botonNotificaciones.setAttribute("aria-controls", panel.id);
        botonNotificaciones.setAttribute("aria-expanded", "false");
        const indicador = crearElemento("span", "notificaciones-contador");
        indicador.hidden = true;
        indicador.setAttribute("aria-hidden", "true");
        botonNotificaciones.append(indicador);
        botonNotificaciones.parentElement.append(panel);

        let consultando = false;
        let errorNotificaciones = false;
        const cargarNotificaciones = async () => {
            if (consultando) return;
            consultando = true;
            try {
                const datos = await llamarApi("notificaciones");
                let noLeidas = Number(datos.no_leidas);
                if (botonNotificaciones.getAttribute("aria-expanded") !== "true") {
                    indicador.hidden = noLeidas === 0;
                    indicador.textContent = noLeidas > 99 ? "99+" : String(noLeidas);
                    botonNotificaciones.setAttribute("aria-label", noLeidas ? `Notificaciones: ${noLeidas} sin leer` : "Notificaciones");
                    errorNotificaciones = false;
                    return;
                }
                const titulo = crearElemento("h2", "", "Notificaciones");
                const lista = crearElemento("ul", "ventana-notificaciones__lista");
                if (!datos.notificaciones.length) {
                    lista.append(crearElemento("li", "ventana-notificaciones__item", "No tienes notificaciones."));
                }
                datos.notificaciones.forEach((notificacion) => {
                    const item = crearElemento("li", "ventana-notificaciones__item");
                    item.append(
                        crearElemento("p", "", notificacion.actor ? `${notificacion.actor} ${notificacion.mensaje}` : notificacion.mensaje),
                        crearElemento("span", "", formatoFecha(notificacion.creado_en))
                    );
                    if (notificacion.tipo_notificacion === "mensaje" && notificacion.conversacion_id) {
                        const abrir = crearBoton("ventana-notificaciones__accion", "Abrir mensaje");
                        abrir.addEventListener("click", () => {
                            window.location.href = `chat.html?conversacion=${notificacion.conversacion_id}`;
                        });
                        item.append(abrir);
                    }
                    if (notificacion.escuadron_id) {
                        const abrir = crearBoton("ventana-notificaciones__accion", "Abrir escuadrón");
                        abrir.addEventListener("click", () => {
                            window.location.href = `escuadrones.html?escuadron=${encodeURIComponent(notificacion.escuadron_id)}`;
                        });
                        item.append(abrir);
                    }
                    if (notificacion.tipo_notificacion === "amistad"
                        && notificacion.estado_amistad === "pendiente"
                        && notificacion.actor_usuario_id) {
                        const aceptar = crearBoton("ventana-notificaciones__accion", "Aceptar solicitud");
                        aceptar.addEventListener("click", async () => {
                            try {
                                await llamarApi("responder_amistad", {
                                    method: "POST",
                                    body: { otro_usuario_id: notificacion.actor_usuario_id, aceptar: true },
                                });
                                notificar("Solicitud de amistad aceptada.");
                                await cargarNotificaciones();
                            } catch (error) {
                                notificar(error.message, true);
                            }
                        });
                        item.append(aceptar);
                    }
                    lista.append(item);
                });
                panel.replaceChildren(titulo, lista);
                const ids = datos.notificaciones.filter((notificacion) => !notificacion.leido_en).map((notificacion) => notificacion.notificacion_id);
                if (ids.length) {
                    await llamarApi("marcar_notificaciones", { method: "POST", body: { ids } });
                    noLeidas = Math.max(0, noLeidas - ids.length);
                }
                indicador.hidden = noLeidas === 0;
                indicador.textContent = noLeidas > 99 ? "99+" : String(noLeidas);
                botonNotificaciones.setAttribute("aria-label", noLeidas ? `Notificaciones: ${noLeidas} sin leer` : "Notificaciones");
                errorNotificaciones = false;
            } catch (error) {
                if (!errorNotificaciones) notificar(error.message, true);
                errorNotificaciones = true;
            } finally {
                consultando = false;
            }
        };

        cargarNotificaciones();
        window.setInterval(cargarNotificaciones, 15000);
        botonNotificaciones.addEventListener("click", async () => {
            const mostrar = botonNotificaciones.getAttribute("aria-expanded") !== "true";
            panel.classList.toggle("no-mostrar", !mostrar);
            botonNotificaciones.setAttribute("aria-expanded", String(mostrar));
            if (mostrar) await cargarNotificaciones();
        });
        document.addEventListener("click", (evento) => {
            if (!botonNotificaciones.contains(evento.target) && !panel.contains(evento.target)) {
                panel.classList.add("no-mostrar");
                botonNotificaciones.setAttribute("aria-expanded", "false");
            }
        });
    }
};

// Publicaciones, comentarios y votos de honor
const crearPublicacion = (publicacion) => {
    const tarjeta = crearElemento("article", "posts__comun");
    tarjeta.dataset.publicacionId = publicacion.publicacion_id;

    const cabecera = crearElemento("header", "post-header");
    const info = crearElemento("div", "post-header__info");
    const avatar = crearElemento("div", "posts__imagen");
    if (publicacion.url_avatar) {
        if (Number(publicacion.autor_usuario_id) !== Number(usuarioActual?.usuario_id)) {
            avatar.dataset.avatarPublicacion = "true";
        }
        avatar.style.backgroundImage = `url("${encodeURI(publicacion.url_avatar)}")`;
    }
    const datosAutor = crearElemento("div");
    datosAutor.append(
        crearElemento("h3", "", publicacion.gamertag || `${publicacion.nombre} ${publicacion.apellido}`),
        crearElemento("p", "", formatoFecha(publicacion.creado_en))
    );
    info.append(avatar, datosAutor);
    const opciones = crearBoton("", "•••", "Opciones de honor del post");
    cabecera.append(info, opciones);

    const principal = crearElemento("main", "post-main");
    const contenido = crearElemento("div", "post-main__contenido");
    if (publicacion.contenido) contenido.append(crearElemento("p", "", publicacion.contenido));
    (publicacion.multimedia || []).forEach((archivo) => {
        if (archivo.tipo_multimedia === "imagen") {
            const imagen = crearElemento("img");
            imagen.src = archivo.url_multimedia;
            imagen.alt = archivo.texto_alternativo || "";
            contenido.append(imagen);
        }
    });

    const reacciones = crearElemento("div", "post-main__reacciones");
    const botones = crearElemento("div", "post-main__botones");
    const meGusta = crearBoton("btn-corazon", "", "Me gusta");
    meGusta.append(crearIcono("M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"));
    if (publicacion.mi_reaccion) meGusta.classList.add("svg-corazon");
    const verComentarios = crearBoton("btn-comentarios", "", "Ver comentarios");
    verComentarios.append(crearIcono("M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"));
    botones.append(meGusta, verComentarios);

    const contadores = crearElemento("div", "post-main__nreacciones");
    const contadorMeGusta = crearElemento("div", "post-main__nreacciones-div");
    contadorMeGusta.append(crearElemento("p", "info-corazones", publicacion.total_reacciones));
    const contadorComentarios = crearElemento("div", "post-main__nreacciones-div");
    contadorComentarios.append(crearElemento("p", "", publicacion.total_comentarios));
    contadores.append(contadorMeGusta, contadorComentarios);
    reacciones.append(botones, contadores);
    principal.append(contenido, reacciones);

    const footer = crearElemento("footer", "posts__up");
    const comentarioRapido = document.createElement("input");
    comentarioRapido.className = "posts__input";
    comentarioRapido.type = "text";
    comentarioRapido.maxLength = 300;
    comentarioRapido.placeholder = "Escribe un comentario...";
    const enviarComentario = crearBoton("posts__enviar", "", "Enviar comentario");
    enviarComentario.append(crearIcono("m22 2-7 20-4-9-9-4 20-7Zm0 0L11 13"));
    const avatarComentario = crearElemento("div", "posts__imagen");
    footer.append(avatarComentario, comentarioRapido, enviarComentario);
    if (usuarioActual?.url_avatar) {
        avatarComentario.style.backgroundImage = `url("${encodeURI(usuarioActual.url_avatar)}")`;
    }
    tarjeta.append(cabecera, principal, footer);

    meGusta.addEventListener("click", async () => {
        try {
            const datos = await llamarApi("reaccionar", { method: "POST", body: { publicacion_id: publicacion.publicacion_id } });
            meGusta.classList.toggle("svg-corazon", datos.activa);
            const contador = contadorMeGusta.querySelector("p");
            contador.textContent = String(Number(contador.textContent) + (datos.activa ? 1 : -1));
        } catch (error) {
            notificar(error.message, true);
        }
    });

    const abrirComentarios = async () => {
        try {
            await mostrarComentarios(publicacion, tarjeta);
        } catch (error) {
            notificar(error.message, true);
        }
    };
    verComentarios.addEventListener("click", abrirComentarios);
    enviarComentario.addEventListener("click", () => enviarComentarioRapido(publicacion, comentarioRapido, tarjeta));
    comentarioRapido.addEventListener("keydown", (evento) => {
        if (evento.key === "Enter") {
            evento.preventDefault();
            enviarComentarioRapido(publicacion, comentarioRapido, tarjeta);
        }
    });
    opciones.addEventListener("click", () => mostrarHonor(publicacion.publicacion_id));
    return tarjeta;
};

const enviarComentarioRapido = async (publicacion, entrada, tarjeta) => {
    const contenido = entrada.value.trim();
    if (!contenido) return;
    try {
        await llamarApi("comentar", { method: "POST", body: { publicacion_id: publicacion.publicacion_id, contenido } });
        entrada.value = "";
        const contador = tarjeta.querySelectorAll(".post-main__nreacciones-div p")[1];
        contador.textContent = String(Number(contador.textContent) + 1);
        notificar("Comentario publicado.");
    } catch (error) {
        notificar(error.message, true);
    }
};

const crearModal = (clase, titulo) => {
    const dialogo = document.createElement("dialog");
    dialogo.className = clase;
    const cabecera = crearElemento("header", "modal-comentarios__cabecera");
    cabecera.append(crearElemento("h2", "", titulo));
    const cerrar = crearBoton("modal-comentarios__cerrar", "×", "Cerrar");
    cabecera.append(cerrar);
    dialogo.append(cabecera);
    cerrar.addEventListener("click", () => dialogo.close());
    dialogo.addEventListener("click", (evento) => {
        if (evento.target === dialogo) dialogo.close();
    });
    document.body.append(dialogo);
    return dialogo;
};

let modalComentarios;
let publicacionModalActiva;
let tarjetaModalActiva;
const mostrarComentarios = async (publicacion, tarjeta) => {
    publicacionModalActiva = publicacion;
    tarjetaModalActiva = tarjeta;
    if (!modalComentarios) modalComentarios = crearModal("modal-comentarios", "Comentarios");
    const titulo = modalComentarios.querySelector("h2");
    titulo.textContent = `Comentarios de ${publicacion.gamertag}`;
    let lista = modalComentarios.querySelector(".modal-comentarios__lista");
    if (!lista) {
        lista = crearElemento("ul", "modal-comentarios__lista");
        modalComentarios.append(lista);
    }
    let formulario = modalComentarios.querySelector(".modal-comentarios__formulario");
    if (!formulario) {
        formulario = crearElemento("form", "modal-comentarios__formulario");
        const entrada = document.createElement("input");
        entrada.type = "text";
        entrada.name = "contenido";
        entrada.maxLength = 300;
        entrada.placeholder = "Escribe un comentario...";
        entrada.required = true;
        const enviar = crearElemento("button", "", "Enviar");
        enviar.type = "submit";
        formulario.append(entrada, enviar);
        formulario.addEventListener("submit", async (evento) => {
            evento.preventDefault();
            const publicacionId = formulario.dataset.publicacionId;
            try {
                await llamarApi("comentar", {
                    method: "POST",
                    body: { publicacion_id: publicacionId, contenido: entrada.value.trim() },
                });
                entrada.value = "";
                await mostrarComentarios(publicacionModalActiva, tarjetaModalActiva);
                const contador = tarjetaModalActiva.querySelectorAll(".post-main__nreacciones-div p")[1];
                contador.textContent = String(Number(contador.textContent) + 1);
            } catch (error) {
                notificar(error.message, true);
            }
        });
        modalComentarios.append(formulario);
    }
    formulario.dataset.publicacionId = publicacion.publicacion_id;
    const datos = await llamarApi("comentarios", { params: { publicacion_id: publicacion.publicacion_id } });
    lista.replaceChildren();
    if (!datos.comentarios.length) lista.append(crearElemento("li", "modal-comentarios__vacio", "Aún no hay comentarios en esta publicación."));
    datos.comentarios.forEach((comentario) => {
        const item = crearElemento("li", "modal-comentarios__comentario");
        const fila = crearElemento("div", "modal-comentarios__comentario-cabecera");
        fila.append(crearElemento("strong", "", comentario.gamertag));
        const honor = crearElemento("div", "modal-comentarios__honor");
        const arriba = crearBoton("modal-comentarios__honor-boton modal-comentarios__honor-boton--dar", "+", "Dar honor");
        const cuenta = crearElemento("span", "modal-comentarios__honor-puntaje", comentario.honor);
        const abajo = crearBoton("modal-comentarios__honor-boton modal-comentarios__honor-boton--restar", "−", "Restar honor");
        arriba.setAttribute("aria-pressed", String(Number(comentario.mi_honor) === 1));
        abajo.setAttribute("aria-pressed", String(Number(comentario.mi_honor) === -1));
        [arriba, abajo].forEach((boton, indice) => boton.addEventListener("click", async () => {
            try {
                await llamarApi("votar_honor", {
                    method: "POST",
                    body: { tipo_objetivo: "comentario", objetivo_id: comentario.comentario_id, valor: indice === 0 ? 1 : -1 },
                });
                await mostrarComentarios(publicacion, tarjeta);
            } catch (error) {
                notificar(error.message, true);
            }
        }));
        honor.append(arriba, cuenta, abajo);
        fila.append(honor);
        item.append(fila, crearElemento("p", "", comentario.contenido));
        lista.append(item);
    });
    if (!modalComentarios.open) modalComentarios.showModal();
};

let modalHonor;
const mostrarHonor = async (publicacionId) => {
    if (!modalHonor) {
        modalHonor = crearModal("modal-comentarios modal-honor-post", "Honor de la publicación");
        modalHonor.append(crearElemento("p", "modal-honor-post__descripcion", "Reconoce o resta honor al autor de esta publicación."));
        const botones = crearElemento("div", "modal-comentarios__honor modal-honor-post__controles");
        const positivo = crearBoton("modal-comentarios__honor-boton modal-comentarios__honor-boton--dar", "+", "Dar honor al autor");
        const negativo = crearBoton("modal-comentarios__honor-boton modal-comentarios__honor-boton--restar", "−", "Restar honor al autor");
        botones.append(positivo, negativo);
        modalHonor.append(botones);
        positivo.addEventListener("click", () => votarHonorPost(modalHonor.dataset.publicacionId, 1));
        negativo.addEventListener("click", () => votarHonorPost(modalHonor.dataset.publicacionId, -1));
    }
    modalHonor.dataset.publicacionId = publicacionId;
    modalHonor.showModal();
};

const votarHonorPost = async (publicacionId, valor) => {
    try {
        await llamarApi("votar_honor", { method: "POST", body: { tipo_objetivo: "publicacion", objetivo_id: publicacionId, valor } });
        notificar("Voto de honor actualizado.");
    } catch (error) {
        notificar(error.message, true);
    }
};

// Crear y cargar publicaciones
const crearCompositor = () => {
    let compositor = document.querySelector(".posts__principal");
    const destinoCompositor = document.querySelector(".muro__posts section") || document.querySelector(".posts");
    if (!compositor && destinoCompositor) {
        compositor = crearElemento("div", "posts__principal");
        compositor.innerHTML = '<div class="posts__up"><div class="posts__imagen"></div><input class="posts__input" type="text" maxlength="5000" placeholder="¿Qué estás jugando hoy?"><button class="posts__enviar" type="button" aria-label="Enviar publicación"></button></div><div class="posts__botones"><button type="button">Foto/Video</button><button type="button" data-post-type="noticia">Noticia</button><button type="button" data-post-type="logro">Logro</button></div>';
        destinoCompositor.prepend(compositor);
    }
    const entrada = compositor?.querySelector(".posts__input");
    const boton = compositor?.querySelector(".posts__enviar");
    boton?.replaceChildren(crearIcono("m22 2-7 20-4-9-9-4 20-7Zm0 0L11 13"));
    if (usuarioActual) aplicarImagenPerfil(usuarioActual);
    let tipo = "estado";
    let archivoAdjunto = null;
    let selectorArchivo = compositor?.querySelector('input[type="file"]');
    if (!selectorArchivo && compositor) {
        selectorArchivo = document.createElement("input");
        selectorArchivo.type = "file";
        selectorArchivo.accept = "image/jpeg,image/png,image/webp,image/gif";
        selectorArchivo.hidden = true;
        selectorArchivo.name = "multimedia";
        compositor.append(selectorArchivo);
    }
    selectorArchivo?.addEventListener("change", () => {
        archivoAdjunto = selectorArchivo.files?.[0] || null;
        if (archivoAdjunto) notificar(`Imagen lista: ${archivoAdjunto.name}`);
    });
    compositor?.querySelectorAll(".posts__botones button").forEach((opcion) => {
        opcion.addEventListener("click", () => {
            if (opcion.textContent.toLowerCase().includes("foto")) {
                selectorArchivo?.click();
                return;
            }
            tipo = opcion.dataset.postType || "estado";
            notificar(`La publicación se guardará como ${opcion.textContent.trim().toLowerCase()}.`);
        });
    });
    const publicar = async () => {
        if (!entrada?.value.trim() && !archivoAdjunto) return;
        try {
            const datos = new FormData();
            datos.set("contenido", entrada.value.trim());
            datos.set("tipo", tipo);
            if (archivoAdjunto) datos.set("multimedia", archivoAdjunto);
            await llamarApi("publicar", { method: "POST", body: datos });
            entrada.value = "";
            archivoAdjunto = null;
            selectorArchivo.value = "";
            tipo = "estado";
            await cargarFeed();
            notificar("Publicación creada.");
        } catch (error) {
            notificar(error.message, true);
        }
    };
    boton?.addEventListener("click", publicar);
    entrada?.addEventListener("keydown", (evento) => {
        if (evento.key === "Enter") {
            evento.preventDefault();
            publicar();
        }
    });
};

const cargarFeed = async () => {
    const esPerfil = document.querySelector(".perfil");
    const destino = document.querySelector(".posts") || document.querySelector(".muro__posts section");
    if (!destino) return;
    const usuarioObjetivo = new URLSearchParams(window.location.search).get("usuario_id");
    let lista = destino.querySelector("#feed-publicaciones");
    if (!lista) {
        lista = crearElemento("div", "feed-publicaciones");
        lista.id = "feed-publicaciones";
        destino.append(lista);
    }
    destino.querySelectorAll(".posts__comun").forEach((post) => post.remove());
    try {
        const datos = await llamarApi("feed", {
            params: usuarioObjetivo
                ? { autor_usuario_id: usuarioObjetivo }
                : esPerfil && usuarioActual ? { autor_usuario_id: usuarioActual.usuario_id } : {},
        });
        lista.replaceChildren();
        if (!datos.publicaciones.length) {
            lista.append(crearElemento("p", "feed-vacio", "Aún no hay publicaciones. ¡Comparte la primera!"));
        }
        datos.publicaciones.forEach((publicacion) => lista.append(crearPublicacion(publicacion)));
    } catch (error) {
        lista.replaceChildren(crearElemento("p", "feed-vacio feed-vacio--error", error.message));
    }
};

const cargarAmigosEnLinea = async () => {
    const lista = document.getElementById("amigos-en-linea");
    if (!lista) return;
    try {
        const datos = await llamarApi("conversaciones");
        lista.replaceChildren();
        datos.conversaciones.forEach((conversacion) => {
            const item = document.createElement("li");
            const boton = crearElemento("button", "status-amigos__contenedor");
            boton.type = "button";

            const avatar = crearElemento("div", "status-amigos__imagen");
            if (conversacion.url_avatar) {
                avatar.style.backgroundImage = `url("${encodeURI(conversacion.url_avatar)}")`;
            }

            const info = crearElemento("div", "status-amigos__info");
            info.append(
                crearElemento("p", "", conversacion.gamertag),
                crearElemento("p", "", conversacion.ultimo_mensaje || "")
            );
            boton.append(avatar, info);
            boton.addEventListener("click", () => {
                window.location.href = `chat.html?conversacion=${conversacion.conversacion_id}`;
            });
            item.append(boton);
            lista.append(item);
        });
    } catch (error) {
        lista.replaceChildren(crearElemento("p", "feed-vacio feed-vacio--error", error.message));
    }
};

//Perfil del usuario
const cargarPerfil = async () => {
    if (!document.querySelector(".perfil")) return;
    try {
        const usuarioObjetivo = new URLSearchParams(window.location.search).get("usuario_id");
        const [{ perfil, es_mi_perfil }, catalogos] = await Promise.all([
            llamarApi("perfil", usuarioObjetivo ? { params: { usuario_id: usuarioObjetivo } } : {}),
            llamarApi("catalogos"),
        ]);
        if (es_mi_perfil) usuarioActual = { ...usuarioActual, ...perfil };
        document.querySelector(".perfil__nombre").textContent = `${perfil.nombre} ${perfil.apellido}`;
        document.querySelector(".perfil__gamertag").textContent = `#${perfil.gamertag}`;
        aplicarImagenPerfil(perfil, true);
        const numeros = document.querySelectorAll(".perfil__numero p");
        if (numeros[0]) numeros[0].textContent = perfil.amigos;
        if (numeros[1]) numeros[1].textContent = perfil.juegos.length;
        if (numeros[2]) numeros[2].textContent = perfil.honor;

        const panelVideojuegos = document.querySelector(".videojuegos");
        if (panelVideojuegos && !panelVideojuegos.querySelector(".videojuegos__favoritos")) {
            panelVideojuegos.innerHTML = `
                <div class="videojuegos__plataformas">
                    <h3>Mis plataformas favoritas</h3>
                    <div class="videojuegos__favoritos"></div>
                </div>
                <div class="videojuegos__plataformas">
                    <h3>Mis juegos favoritos</h3>
                    <div class="videojuegos__favoritos-cards"></div>
                </div>`;
        }
        const plataformas = document.querySelector(".videojuegos__favoritos");
        if (plataformas) {
            plataformas.replaceChildren();
            perfil.plataformas.forEach((plataforma) => {
                const imagen = crearElemento("img");
                imagen.alt = plataforma.nombre;
                imagen.src = plataforma.url_imagen || `img/${plataforma.nombre.toLowerCase() === "playstation" ? "ps" : plataforma.nombre.toLowerCase()}.png`;
                plataformas.append(imagen);
            });
        }
        const juegos = document.querySelector(".videojuegos__favoritos-cards");
        if (juegos) {
            juegos.replaceChildren();
            perfil.juegos.forEach((juego) => {
                const tarjeta = crearElemento("div", "videojuegos__favoritos-card");
                if (juego.url_imagen) {
                    const imagen = crearElemento("img");
                    imagen.src = juego.url_imagen;
                    imagen.alt = juego.nombre;
                    tarjeta.append(imagen);
                }
                const texto = crearElemento("div");
                texto.append(crearElemento("h4", "", juego.nombre), crearElemento("p", "", juego.motivo || ""));
                tarjeta.append(texto);
                juegos.append(tarjeta);
            });
        }

        const editar = document.getElementById("abrir-edicion-perfil");
        if (editar) editar.hidden = !es_mi_perfil;
        let botonAmistad = document.getElementById("boton-accion-amistad");
        if (!botonAmistad) {
            botonAmistad = crearBoton("perfil__amistad", "", "Acción de amistad");
            botonAmistad.id = "boton-accion-amistad";
            document.querySelector(".perfil__nombre-fila")?.append(botonAmistad);
        }
        botonAmistad.hidden = es_mi_perfil || modoVistaPrevia;
        if (!es_mi_perfil && !modoVistaPrevia) {
            if (perfil.estado_amistad === "aceptada") {
                botonAmistad.textContent = "Amigos";
                botonAmistad.disabled = true;
            } else if (perfil.estado_amistad === "pendiente"
                && Number(perfil.solicitado_por_usuario_id) === Number(usuarioActual?.usuario_id)) {
                botonAmistad.textContent = "Solicitud enviada";
                botonAmistad.disabled = true;
            } else if (perfil.estado_amistad === "pendiente") {
                botonAmistad.textContent = "Aceptar solicitud";
                botonAmistad.disabled = false;
                botonAmistad.onclick = async () => {
                    try {
                        await llamarApi("responder_amistad", {
                            method: "POST",
                            body: { otro_usuario_id: perfil.usuario_id, aceptar: true },
                        });
                        notificar("Solicitud aceptada.");
                        await cargarPerfil();
                    } catch (error) {
                        notificar(error.message, true);
                    }
                };
            } else {
                botonAmistad.textContent = "Agregar amigo";
                botonAmistad.disabled = false;
                botonAmistad.onclick = async () => {
                    try {
                        await llamarApi("solicitar_amistad", {
                            method: "POST",
                            body: { otro_usuario_id: perfil.usuario_id },
                        });
                        notificar("Solicitud enviada.");
                        await cargarPerfil();
                    } catch (error) {
                        notificar(error.message, true);
                    }
                };
            }
        }
        const modal = document.getElementById("modal-editar-perfil");
        if (modal && es_mi_perfil) {
            modal.querySelector('[name="nombre"]').value = perfil.nombre;
            modal.querySelector('[name="apellido"]').value = perfil.apellido;
            modal.querySelector('[name="gamertag"]').value = perfil.gamertag;
            modal.querySelector('[name="biografia"]').value = perfil.biografia || "";
            const listaJuegos = modal.querySelector("#editar-juegos");
            listaJuegos.replaceChildren();
            catalogos.juegos.forEach((juego) => {
                const etiqueta = document.createElement("label");
                etiqueta.className = "modal-editar-perfil__opcion";
                const casilla = document.createElement("input");
                casilla.type = "checkbox";
                casilla.name = "juegos_favoritos[]";
                casilla.value = juego.juego_id;
                casilla.checked = perfil.juegos.some((favorito) =>
                    String(favorito.juego_id) === String(juego.juego_id)
                );
                etiqueta.append(casilla, crearElemento("span", "", juego.nombre));
                listaJuegos.append(etiqueta);
            });

            const listaPlataformas = modal.querySelector("#editar-plataformas");
            listaPlataformas.replaceChildren();
            catalogos.plataformas.forEach((plataforma) => {
                const etiqueta = document.createElement("label");
                etiqueta.className = "modal-editar-perfil__opcion";
                const casilla = document.createElement("input");
                casilla.type = "checkbox";
                casilla.name = "plataformas_favoritas[]";
                casilla.value = plataforma.plataforma_id;
                casilla.checked = perfil.plataformas.some((favorita) =>
                    String(favorita.plataforma_id) === String(plataforma.plataforma_id)
                );
                etiqueta.append(casilla, crearElemento("span", "", plataforma.nombre));
                listaPlataformas.append(etiqueta);
            });
            if (perfil.juegos.length) {
                modal.querySelector("#editar-juegos-descripcion").value = perfil.juegos[0].motivo || "";
            }

            if (!modal.dataset.eventosConfigurados) {
                editar.addEventListener("click", () => modal.showModal());
                modal.querySelectorAll("#cerrar-edicion-perfil, #cerrar-formulario-perfil").forEach((boton) => {
                    boton.addEventListener("click", () => modal.close());
                });
                modal.addEventListener("click", (evento) => {
                    if (evento.target === modal) modal.close();
                });
                modal.querySelector("form").addEventListener("submit", async (evento) => {
                    evento.preventDefault();
                    const datos = new FormData(evento.currentTarget);
                    try {
                        await llamarApi("actualizar_perfil", { method: "POST", body: datos });
                        modal.close();
                        await cargarPerfil();
                        aplicarImagenPerfil(usuarioActual, true);
                        notificar("Perfil actualizado.");
                    } catch (error) {
                        notificar(error.message, true);
                    }
                });
                modal.dataset.eventosConfigurados = "true";
            }
        }
    } catch (error) {
        notificar(error.message, true);
    }
};

//Tienda y carrito
let filtroProductos = "";
const alternarFormularioVenta = () => {
    const panel = document.getElementById("formulario-venta");
    const oculto = panel.classList.toggle("no-mostrar");
    document.getElementById("vender-producto").setAttribute("aria-expanded", String(!oculto));
    if (!oculto) panel.scrollIntoView({ block: "start" });
};

const iniciarTienda = () => {
    if (!document.querySelector(".tienda")) return;
    const botonCarrito = crearBoton("boton-global boton-carrito", "Ver carrito");
    botonCarrito.id = "ver-carrito";
    document.querySelector(".div-vender")?.append(botonCarrito);
    botonCarrito.addEventListener("click", mostrarCarrito);
    const formulario = document.querySelector(".tienda__form-publicar");
    formulario?.addEventListener("submit", async (evento) => {
        evento.preventDefault();
        try {
            await llamarApi("publicar_producto", { method: "POST", body: new FormData(formulario) });
            formulario.reset();
            document.querySelector(".tienda__publicar").classList.add("no-mostrar");
            document.getElementById("vender-producto").setAttribute("aria-expanded", "false");
            await cargarProductos();
            notificar("Artículo publicado.");
        } catch (error) {
            notificar(error.message, true);
        }
    });
    document.getElementById("vender-producto")?.addEventListener("click", alternarFormularioVenta);
    document.querySelectorAll(".tienda__categorias button").forEach((boton) => {
        boton.addEventListener("click", () => {
            const nombre = boton.querySelector("img")?.alt;
            cargarProductos(nombre === "playstation" ? "PlayStation" : nombre ? nombre[0].toUpperCase() + nombre.slice(1) : "");
        });
    });
    cargarProductos();
    actualizarContadorCarrito();
};

const cargarProductos = async (plataforma = filtroProductos) => {
    const contenedor = document.querySelector(".contenedor-tienda");
    if (!contenedor) return;
    filtroProductos = plataforma;
    try {
        const datos = await llamarApi("productos", { params: { plataforma } });
        contenedor.replaceChildren();
        if (!datos.productos.length) {
            contenedor.append(crearElemento("p", "feed-vacio", "No hay artículos disponibles con esos filtros."));
        }
        datos.productos.forEach((producto) => {
            const tarjeta = crearElemento("article", "tienda__producto");
            const enlace = document.createElement("a");
            enlace.href = `producto.html?producto_id=${encodeURIComponent(producto.producto_id)}`;
            const bloqueImagen = crearElemento("div", "tienda__producto-imagen");
            const imagen = crearElemento("img");
            imagen.src = producto.imagen || "img/imagen.png";
            imagen.alt = producto.nombre;
            bloqueImagen.append(imagen);
            enlace.append(
                bloqueImagen,
                crearElemento("h4", "", producto.nombre),
                crearElemento("p", "", `${producto.codigo_moneda} $${Number(producto.precio).toFixed(2)}`),
                crearElemento("p", "descripcion", producto.descripcion)
            );

            const controles = crearElemento("div", "tienda__producto-controles");
            const cantidad = document.createElement("select");
            cantidad.className = "tienda__producto-cantidad";
            cantidad.setAttribute("aria-label", `Cantidad de ${producto.nombre}`);
            const cantidadMaxima = Math.max(1, Math.min(10, Number(producto.cantidad_stock)));
            for (let numero = 1; numero <= cantidadMaxima; numero++) {
                cantidad.append(new Option(String(numero), String(numero)));
            }
            const agregar = crearBoton("", "Agregar al carrito", `Agregar ${producto.nombre} al carrito`);
            agregar.addEventListener("click", async (evento) => {
                evento.preventDefault();
                try {
                    await llamarApi("agregar_carrito", {
                        method: "POST",
                        body: { producto_id: producto.producto_id, cantidad: Number(cantidad.value) },
                    });
                    notificar("Artículo agregado al carrito.");
                    await actualizarContadorCarrito();
                } catch (error) {
                    notificar(error.message, true);
                }
            });
            controles.append(cantidad, agregar);
            enlace.append(crearElemento("p", "tienda__producto-stock", `Disponible: ${producto.cantidad_stock}`));
            tarjeta.append(enlace, controles);
            contenedor.append(tarjeta);
        });
    } catch (error) {
        contenedor.replaceChildren(crearElemento("p", "feed-vacio feed-vacio--error", error.message));
    }
};

const actualizarContadorCarrito = async () => {
    const boton = document.getElementById("ver-carrito");
    if (!boton) return;
    try {
        const datos = await llamarApi("carrito");
        const cantidad = datos.articulos.reduce((total, articulo) => total + Number(articulo.cantidad), 0);
        boton.textContent = `Ver carrito (${cantidad})`;
    } catch (error) {
        notificar(error.message, true);
    }
};

const mostrarCarrito = async () => {
    let dialogo = document.querySelector(".carrito-modal");
    if (!dialogo) {
        dialogo = document.createElement("dialog");
        dialogo.className = "carrito-modal";
        const cabecera = crearElemento("header", "modal-comentarios__cabecera");
        cabecera.append(crearElemento("h2", "", "Tu carrito"));
        const cerrar = crearBoton("modal-comentarios__cerrar", "×", "Cerrar carrito");
        cerrar.addEventListener("click", () => dialogo.close());
        cabecera.append(cerrar);
        const lista = crearElemento("ul", "carrito-modal__lista");
        const total = crearElemento("p", "carrito-modal__total");
        const comprar = crearBoton("boton-global", "Finalizar compra");
        comprar.addEventListener("click", async () => {
            if (comprar.disabled) return;
            comprar.disabled = true;
            try {
                const resultado = await llamarApi("finalizar_compra", { method: "POST", body: {} });
                dialogo.close();
                notificar(`${resultado.mensaje} Pedido #${resultado.pedido_id}.`);
                await Promise.all([actualizarContadorCarrito(), cargarProductos()]);
            } catch (error) {
                notificar(error.message, true);
                comprar.disabled = false;
            }
        });
        dialogo.append(cabecera, lista, total, comprar);
        dialogo.addEventListener("click", (evento) => {
            if (evento.target === dialogo) dialogo.close();
        });
        document.body.append(dialogo);
    }
    try {
        const datos = await llamarApi("carrito");
        const lista = dialogo.querySelector(".carrito-modal__lista");
        lista.replaceChildren();
        if (!datos.articulos.length) lista.append(crearElemento("li", "feed-vacio", "Tu carrito está vacío."));
        datos.articulos.forEach((articulo) => {
            const item = crearElemento("li", "carrito-modal__articulo");
            item.append(
                crearElemento("span", "", `${articulo.nombre} × ${articulo.cantidad}`),
                crearElemento("strong", "", `${articulo.codigo_moneda} $${(Number(articulo.precio_unitario) * Number(articulo.cantidad)).toFixed(2)}`)
            );
            const quitar = crearBoton("", "Quitar", `Quitar ${articulo.nombre}`);
            quitar.addEventListener("click", async () => {
                try {
                    await llamarApi("quitar_carrito", { method: "POST", body: { producto_id: articulo.producto_id } });
                    await mostrarCarrito();
                    await actualizarContadorCarrito();
                } catch (error) {
                    notificar(error.message, true);
                }
            });
            item.append(quitar);
            lista.append(item);
        });
        dialogo.querySelector(".carrito-modal__total").textContent = `Total: $${Number(datos.total).toFixed(2)}`;
        dialogo.querySelector(".boton-global").disabled = !datos.articulos.length;
        if (!dialogo.open) dialogo.showModal();
    } catch (error) {
        notificar(error.message, true);
    }
};

//Detalle del producto
const iniciarProducto = async () => {
    if (!document.querySelector(".producto")) return;
    const seccion = document.querySelector(".contenedor-producto");
    const mostrarNoDisponible = (mensaje) => {
        seccion.replaceChildren(crearElemento("p", "feed-vacio", mensaje));
        seccion.hidden = false;
    };
    const params = new URLSearchParams(window.location.search);
    const productoId = params.get("producto_id");
    if (!productoId) {
        mostrarNoDisponible("Selecciona un artículo desde la tienda.");
        return;
    }
    try {
        const { producto } = await llamarApi("producto", { params: { producto_id: productoId } });
        if (producto.estado_producto !== "disponible" || Number(producto.cantidad_stock) <= 0) {
            mostrarNoDisponible("Este artículo ya no está disponible.");
            return;
        }
        const datos = seccion.children[1];
        const compra = seccion.querySelector(".contenedor-producto__barra-compra");
        const imagen = seccion.querySelector("img");
        imagen.src = producto.imagenes[0] || "img/imagen.png";
        imagen.alt = producto.nombre;
        datos.querySelector("h3").textContent = producto.nombre;
        datos.querySelector(".contenedor-producto__vendedor span").textContent = producto.vendedor_gamertag;
        datos.querySelector(".contenedor-producto__precio").textContent = `${producto.codigo_moneda} $${Number(producto.precio).toFixed(2)}`;
        datos.querySelectorAll("p")[3].textContent = producto.descripcion;
        const selector = compra.querySelector("select");
        const actualizarCantidad = (stock) => {
            compra.querySelector(".contenedor-producto__vendedor-venderdor").textContent = `Disponible · ${stock} unidades`;
            selector.replaceChildren();
            for (let cantidad = 1; cantidad <= Math.min(10, Number(stock)); cantidad++) {
                selector.append(new Option(String(cantidad), String(cantidad)));
            }
        };
        actualizarCantidad(producto.cantidad_stock);
        const cantidadActual = () => Number(selector.value || 1);
        const botones = compra.querySelectorAll("button");
        botones.forEach((boton, indice) => {
            boton.addEventListener("click", async () => {
                botones.forEach((control) => { control.disabled = true; });
                try {
                    const accion = indice === 0 ? "comprar" : "agregar_carrito";
                    const resultado = await llamarApi(accion, {
                        method: "POST",
                        body: { producto_id: producto.producto_id, cantidad: cantidadActual() },
                    });
                    notificar(resultado.mensaje);
                    if (accion === "comprar") {
                        if (Number(resultado.cantidad_stock) === 0) {
                            mostrarNoDisponible("Este artículo ya no está disponible.");
                        } else {
                            actualizarCantidad(resultado.cantidad_stock);
                        }
                    }
                } catch (error) {
                    notificar(error.message, true);
                } finally {
                    botones.forEach((control) => { control.disabled = false; });
                }
            });
        });
        seccion.hidden = false;
    } catch (error) {
        mostrarNoDisponible("No se pudo cargar el artículo.");
        notificar(error.message, true);
    }
};

//Escuadrones y canales
const iniciarEscuadrones = () => {
    if (!document.querySelector(".escuadrones")) return;
    const lista = document.querySelector(".escuadrones__lista");
    const actualizarLista = async () => {
        if (!lista) return;
        try {
            const datos = await llamarApi("escuadrones");
            lista.replaceChildren();
            const contador = document.querySelector(".escuadrones__contador");
            if (contador) contador.textContent = `${datos.escuadrones.length} disponibles`;
            datos.escuadrones.forEach((escuadron) => {
                const tarjeta = crearElemento("article", "escuadron-card");
                tarjeta.dataset.nombre = escuadron.nombre.toLowerCase();
                tarjeta.dataset.juego = escuadron.juego;
                const marca = crearElemento("div", `escuadron-card__juego escuadron-card__juego--${escuadron.juego.toLowerCase().replaceAll(" ", "-")}`);
                marca.append(crearElemento("span", "", escuadron.juego.slice(0, 1)));
                const contenido = crearElemento("div", "escuadron-card__contenido");
                const titulo = crearElemento("div", "escuadron-card__titulo");
                const textoTitulo = crearElemento("div");
                textoTitulo.append(
                    crearElemento("h4", "", escuadron.nombre),
                    crearElemento("p", "", `${escuadron.juego} · ${escuadron.plataforma || "Cualquiera"}`)
                );
                titulo.append(textoTitulo, crearElemento("span", "escuadron-card__estado", escuadron.estado_escuadron === "lleno" ? "Lleno" : "Buscando"));
                const pie = crearElemento("div", "escuadron-card__pie");
                pie.append(
                    crearElemento("span", "escuadron-card__jugadores", `♟ ${escuadron.miembros}/${escuadron.max_miembros}`),
                    crearElemento("span", "escuadron-card__duracion", `◷ ${escuadron.etiqueta_duracion}`)
                );
                const unirse = crearBoton("escuadrones__boton escuadrones__boton--unirse", escuadron.soy_miembro ? "Abrir canal →" : "Unirme →");
                unirse.disabled = escuadron.estado_escuadron === "lleno" && !Number(escuadron.soy_miembro);
                unirse.addEventListener("click", async () => {
                    try {
                        if (!Number(escuadron.soy_miembro)) {
                            await llamarApi("unirse_escuadron", { method: "POST", body: { escuadron_id: escuadron.escuadron_id } });
                        }
                        await abrirCanalEscuadron(escuadron);
                    } catch (error) {
                        notificar(error.message, true);
                    }
                });
                pie.append(unirse);
                contenido.append(titulo, crearElemento("p", "escuadron-card__descripcion", escuadron.descripcion || "Sin descripción."), pie);
                tarjeta.append(marca, contenido);
                lista.append(tarjeta);
            });
            filtrarEscuadrones();
        } catch (error) {
            lista.replaceChildren(crearElemento("p", "feed-vacio feed-vacio--error", error.message));
        }
    };

    const busqueda = document.getElementById("buscar-escuadron");
    const filtro = document.getElementById("filtro-juego");
    busqueda?.addEventListener("input", filtrarEscuadrones);
    filtro?.addEventListener("change", filtrarEscuadrones);
    document.getElementById("crear-escuadron")?.addEventListener("click", () => {
        window.location.href = "crear-escuadron.html";
    });
    document.getElementById("boton-aband-esc")?.addEventListener("click", async () => {
        const id = sessionStorage.getItem("escuadron_actual");
        if (!id) return;
        try {
            await llamarApi("salir_escuadron", { method: "POST", body: { escuadron_id: id } });
            sessionStorage.removeItem("escuadron_actual");
            document.querySelector(".escuadrones-flag")?.classList.remove("no-mostrar");
            document.querySelector(".canal-flag")?.classList.add("no-mostrar");
            await actualizarLista();
        } catch (error) {
            notificar(error.message, true);
        }
    });

    const formularioChat = document.querySelector(".escuadrones__chat-input");
    formularioChat?.addEventListener("submit", async (evento) => {
        evento.preventDefault();
        const campo = formularioChat.querySelector("input");
        const boton = formularioChat.querySelector("button");
        const id = sessionStorage.getItem("escuadron_actual");
        const contenido = campo.value.trim();
        if (!id || !contenido || boton.disabled) return;
        boton.disabled = true;
        try {
            await llamarApi("mensaje_escuadron", { method: "POST", body: { escuadron_id: id, contenido } });
            if (campo.value.trim() === contenido) campo.value = "";
            if (sessionStorage.getItem("escuadron_actual") === id) await cargarMensajesEscuadron(id, true);
        } catch (error) {
            notificar(error.message, true);
        } finally {
            boton.disabled = false;
        }
    });

    let actualizandoCanal = false;
    if (lista) window.setInterval(async () => {
        const id = sessionStorage.getItem("escuadron_actual");
        if (!id || document.hidden || actualizandoCanal || document.querySelector(".canal-flag").classList.contains("no-mostrar")) return;
        actualizandoCanal = true;
        try {
            await cargarMensajesEscuadron(id);
        } finally {
            actualizandoCanal = false;
        }
    }, 15000);

    const formulario = document.querySelector(".escuadrones__form");
    formulario?.addEventListener("submit", async (evento) => {
        evento.preventDefault();
        const datos = Object.fromEntries(new FormData(formulario));
        try {
            const resultado = await llamarApi("crear_escuadron", { method: "POST", body: datos });
            window.location.href = `escuadrones.html?escuadron=${resultado.escuadron_id}`;
        } catch (error) {
            notificar(error.message, true);
        }
    });

    const idInicial = new URLSearchParams(window.location.search).get("escuadron");
    if (idInicial) {
        llamarApi("escuadrones").then(({ escuadrones }) => {
            const actual = escuadrones.find((escuadron) => String(escuadron.escuadron_id) === idInicial);
            if (actual && Number(actual.soy_miembro)) return abrirCanalEscuadron(actual);
            else actualizarLista();
        }).catch((error) => notificar(error.message, true));
    } else {
        actualizarLista();
    }
};

const filtrarEscuadrones = () => {
    const termino = (document.getElementById("buscar-escuadron")?.value || "").trim().toLowerCase();
    const juego = document.getElementById("filtro-juego")?.value || "";
    document.querySelectorAll(".escuadron-card").forEach((tarjeta) => {
        tarjeta.hidden = !(tarjeta.dataset.nombre.includes(termino) && (!juego || tarjeta.dataset.juego === juego));
    });
};

const abrirCanalEscuadron = async (escuadron) => {
    sessionStorage.setItem("escuadron_actual", escuadron.escuadron_id);
    document.querySelector(".escuadrones-flag")?.classList.add("no-mostrar");
    document.querySelector(".canal-flag")?.classList.remove("no-mostrar");
    document.querySelector("#canal-titulo").textContent = escuadron.nombre;
    document.querySelector(".escuadrones__canal-juego").textContent = escuadron.juego;
    await cargarMensajesEscuadron(escuadron.escuadron_id, true);
};

let solicitudCanal = 0;
let errorCanal = false;
const cargarMensajesEscuadron = async (escuadronId, bajarAlFinal = false) => {
    const chat = document.querySelector(".escuadrones__chat");
    if (!chat) return;
    const solicitud = ++solicitudCanal;
    try {
        const datos = await llamarApi("mensajes_escuadron", { params: { escuadron_id: escuadronId } });
        if (solicitud !== solicitudCanal || sessionStorage.getItem("escuadron_actual") !== String(escuadronId)
            || document.querySelector(".canal-flag").classList.contains("no-mostrar")) return;
        errorCanal = false;
        const seguirAlFinal = bajarAlFinal || chat.scrollHeight - chat.scrollTop - chat.clientHeight < 50;
        const posicion = chat.scrollTop;
        chat.replaceChildren();
        datos.mensajes.forEach((mensaje) => {
            const item = crearElemento("div", "escuadrones__mensaje-chat");
            const inicial = crearElemento("span", "escuadrones__avatar escuadrones__avatar--cyan", mensaje.url_avatar ? "" : mensaje.nombre.slice(0, 1));
            if (mensaje.url_avatar) {
                inicial.style.backgroundImage = `url("${encodeURI(mensaje.url_avatar)}")`;
                inicial.setAttribute("role", "img");
                inicial.setAttribute("aria-label", `Foto de ${mensaje.gamertag}`);
            }
            const contenido = crearElemento("div");
            contenido.append(
                crearElemento("strong", "", `${mensaje.gamertag} · ${formatoFecha(mensaje.creado_en)}`),
                crearElemento("p", "", mensaje.contenido)
            );
            item.append(inicial, contenido);
            chat.append(item);
        });
        chat.scrollTop = seguirAlFinal ? chat.scrollHeight : posicion;
    } catch (error) {
        if (solicitud === solicitudCanal) {
            if (!errorCanal) notificar(error.message, true);
            errorCanal = true;
        }
    }
};

// Administración
const iniciarAdministracion = async () => {
    if (!document.querySelector(".administracion")) return;
    try {
        const datos = await llamarApi("administracion");

        const cuentas = document.getElementById("lista-cuentas-negativas");
        cuentas.replaceChildren();
        if (!datos.cuentas.length) {
            cuentas.append(crearElemento("p", "", "No hay cuentas con honor negativo."));
        }
        datos.cuentas.forEach((cuenta) => {
            const item = crearElemento("article", "administracion__item");
            item.append(
                crearElemento("strong", "", `${cuenta.nombre} ${cuenta.apellido}`),
                crearElemento("span", "", `${cuenta.gamertag} · Honor: ${cuenta.honor}`)
            );
            const eliminar = crearBoton("", "Eliminar cuenta", "Eliminar cuenta");
            eliminar.addEventListener("click", async () => {
                if (!window.confirm(`¿Eliminar la cuenta ${cuenta.gamertag}?`)) return;
                await llamarApi("eliminar_usuario", { method: "POST", body: { usuario_id: cuenta.usuario_id } });
                await iniciarAdministracion();
                notificar("Cuenta eliminada.");
            });
            item.append(eliminar);
            cuentas.append(item);
        });

        const listaJuegos = document.getElementById("lista-juegos");
        listaJuegos.replaceChildren();
        datos.juegos.forEach((juego) => {
            const item = crearElemento("article", "administracion__item");
            if (juego.url_imagen) item.append(crearElemento("img", "", ""));
            const nombre = crearElemento("strong", "", juego.nombre);
            item.append(nombre);
            if (juego.url_imagen) item.querySelector("img").src = juego.url_imagen;
            const acciones = crearElemento("div", "administracion__acciones");
            const editar = crearBoton("", "Editar");
            editar.addEventListener("click", () => {
                document.querySelector("#form-juego [name=juego_id]").value = juego.juego_id;
                document.querySelector("#form-juego [name=nombre]").value = juego.nombre;
                document.querySelector("#form-juego [name=nombre]").focus();
            });
            const eliminar = crearBoton("", "Eliminar");
            eliminar.addEventListener("click", async () => {
                await llamarApi("eliminar_juego", { method: "POST", body: { juego_id: juego.juego_id } });
                await iniciarAdministracion();
            });
            acciones.append(editar, eliminar);
            item.append(acciones);
            listaJuegos.append(item);
        });

        const listaPlataformas = document.getElementById("lista-plataformas");
        listaPlataformas.replaceChildren();
        datos.plataformas.forEach((plataforma) => {
            const item = crearElemento("article", "administracion__item");
            if (plataforma.url_imagen) item.append(crearElemento("img", "", ""));
            item.append(crearElemento("strong", "", plataforma.nombre));
            if (plataforma.url_imagen) item.querySelector("img").src = plataforma.url_imagen;
            const acciones = crearElemento("div", "administracion__acciones");
            const editar = crearBoton("", "Editar");
            editar.addEventListener("click", () => {
                document.querySelector("#form-plataforma [name=plataforma_id]").value = plataforma.plataforma_id;
                document.querySelector("#form-plataforma [name=nombre]").value = plataforma.nombre;
                document.querySelector("#form-plataforma [name=nombre]").focus();
            });
            const eliminar = crearBoton("", "Eliminar");
            eliminar.addEventListener("click", async () => {
                await llamarApi("eliminar_plataforma", { method: "POST", body: { plataforma_id: plataforma.plataforma_id } });
                await iniciarAdministracion();
            });
            acciones.append(editar, eliminar);
            item.append(acciones);
            listaPlataformas.append(item);
        });

        const formJuego = document.getElementById("form-juego");
        formJuego.onsubmit = async (evento) => {
            evento.preventDefault();
            const datosJuego = new FormData(formJuego);
            const id = datosJuego.get("juego_id");
            await llamarApi(id ? "actualizar_juego" : "agregar_juego", { method: "POST", body: datosJuego });
            formJuego.reset();
            await iniciarAdministracion();
        };
        document.getElementById("limpiar-juego").addEventListener("click", () => formJuego.reset());

        const formPlataforma = document.getElementById("form-plataforma");
        formPlataforma.onsubmit = async (evento) => {
            evento.preventDefault();
            const datosPlataforma = new FormData(formPlataforma);
            const id = datosPlataforma.get("plataforma_id");
            await llamarApi(id ? "actualizar_plataforma" : "agregar_plataforma", { method: "POST", body: datosPlataforma });
            formPlataforma.reset();
            await iniciarAdministracion();
        };
        document.getElementById("limpiar-plataforma").addEventListener("click", () => formPlataforma.reset());
    } catch (error) {
        notificar(error.message, true);
    }
};

// Chat y amistades
const iniciarChat = async () => {
    if (!document.querySelector(".mensajes")) return;
    const barra = document.querySelector(".barra-chats");
    const lista = barra.querySelector("ul");
    lista.replaceChildren();
    const busqueda = document.createElement("input");
    busqueda.id = "buscar-usuario-chat";
    busqueda.type = "search";
    busqueda.placeholder = "Buscar jugadores...";
    busqueda.setAttribute("aria-label", "Buscar jugadores");
    barra.prepend(busqueda);
    const panel = document.querySelector(".chat__contenido");
    let conversacionActual = null;
    let solicitudMensajes = 0;
    const cargarMensajes = async (conversacionId) => {
        const solicitud = ++solicitudMensajes;
        try {
            const datos = await llamarApi("mensajes", { params: { conversacion_id: conversacionId } });
            if (solicitud !== solicitudMensajes) return;
            panel.replaceChildren();
            datos.mensajes.forEach((mensaje) => {
                const item = crearElemento("div", `mensaje-directo${Number(mensaje.remitente_usuario_id) === Number(usuarioActual.usuario_id) ? " mensaje-directo--propio" : " mensaje-directo--amigo"}`);
                item.append(crearElemento("strong", "", mensaje.gamertag), crearElemento("p", "", mensaje.contenido));
                panel.append(item);
            });
            panel.scrollTop = panel.scrollHeight;
        } catch (error) {
            if (solicitud === solicitudMensajes) notificar(error.message, true);
        }
    };
    const mostrarMensajes = (conversacionId, nombre, urlAvatar) => {
        conversacionActual = String(conversacionId);
        panel.classList.remove("chat__contenido-switch");
        panel.replaceChildren();
        document.querySelector(".chat__info p:first-child").textContent = nombre;
        document.querySelector(".chat__imagen").style.backgroundImage = urlAvatar ? `url("${encodeURI(urlAvatar)}")` : "";
        return cargarMensajes(conversacionId);
    };
    const cargarConversaciones = async () => {
        const datos = await llamarApi("conversaciones");
        lista.replaceChildren();
        datos.conversaciones.forEach((conversacion) => {
            const item = document.createElement("li");
            const boton = crearElemento("button", "status-amigos__contenedor");
            boton.type = "button";
            const info = crearElemento("div", "status-amigos__info");
            info.append(crearElemento("p", "", conversacion.gamertag), crearElemento("p", "", conversacion.ultimo_mensaje || "Inicia una conversación"));
            const avatar = crearElemento("div", "status-amigos__imagen");
            if (conversacion.url_avatar) avatar.style.backgroundImage = `url("${encodeURI(conversacion.url_avatar)}")`;
            boton.append(avatar, info);
            boton.addEventListener("click", () => mostrarMensajes(conversacion.conversacion_id, conversacion.gamertag, conversacion.url_avatar));
            item.append(boton);
            lista.append(item);
        });
    };

    let temporizador;
    busqueda.addEventListener("input", () => {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(async () => {
            const q = busqueda.value.trim();
            if (q.length < 2) {
                await cargarConversaciones();
                return;
            }
            try {
                const datos = await llamarApi("buscar_usuarios", { params: { q } });
                lista.replaceChildren();
                datos.usuarios.forEach((usuario) => {
                    const item = document.createElement("li");
                    const boton = crearElemento("button", "status-amigos__contenedor");
                    boton.type = "button";
                    const avatar = crearElemento("div", "status-amigos__imagen");
                    if (usuario.url_avatar) avatar.style.backgroundImage = `url("${encodeURI(usuario.url_avatar)}")`;
                    const nombre = crearElemento("div", "status-amigos__info");
                    nombre.append(crearElemento("p", "", usuario.gamertag));
                    boton.append(avatar, nombre);
                    if (usuario.estado_amistad === "aceptada") {
                        boton.addEventListener("click", async () => {
                            try {
                                const conversacion = await llamarApi("abrir_conversacion", {
                                    method: "POST",
                                    body: { otro_usuario_id: usuario.usuario_id },
                                });
                                await cargarConversaciones();
                                await mostrarMensajes(conversacion.conversacion_id, usuario.gamertag, usuario.url_avatar);
                            } catch (error) {
                                notificar(error.message, true);
                            }
                        });
                    } else if (usuario.estado_amistad === "pendiente" && Number(usuario.solicitado_por_usuario_id) !== Number(usuarioActual.usuario_id)) {
                        nombre.append(crearElemento("p", "", "Aceptar amistad"));
                        boton.addEventListener("click", async () => {
                            try {
                                await llamarApi("responder_amistad", { method: "POST", body: { otro_usuario_id: usuario.usuario_id, aceptar: true } });
                                notificar("Solicitud de amistad aceptada.");
                                await cargarConversaciones();
                            } catch (error) {
                                notificar(error.message, true);
                            }
                        });
                    } else if (!usuario.estado_amistad) {
                        nombre.append(crearElemento("p", "", "Agregar"));
                        boton.addEventListener("click", async () => {
                            try {
                                await llamarApi("solicitar_amistad", { method: "POST", body: { otro_usuario_id: usuario.usuario_id } });
                                notificar("Solicitud de amistad enviada.");
                                busqueda.dispatchEvent(new Event("input"));
                            } catch (error) {
                                notificar(error.message, true);
                            }
                        });
                    } else {
                        boton.disabled = true;
                        nombre.append(crearElemento("p", "", "Solicitud pendiente"));
                    }
                    item.append(boton);
                    lista.append(item);
                });
            } catch (error) {
                notificar(error.message, true);
            }
        }, 250);
    });

    const enviar = async () => {
        const campo = document.querySelector(".chat__input input");
        const boton = document.querySelector(".chat__input button");
        const conversacionId = conversacionActual;
        const contenido = campo.value.trim();
        if (!conversacionId || !contenido || boton.disabled) return;
        boton.disabled = true;
        try {
            await llamarApi("enviar_mensaje", { method: "POST", body: { conversacion_id: conversacionId, contenido } });
            if (conversacionActual === conversacionId) {
                if (campo.value.trim() === contenido) campo.value = "";
                await cargarMensajes(conversacionId);
            }
        } catch (error) {
            notificar(error.message, true);
        } finally {
            boton.disabled = false;
        }
    };
    document.querySelector(".chat__input button")?.addEventListener("click", enviar);
    document.querySelector(".chat__input input")?.addEventListener("keydown", (evento) => {
        if (evento.key === "Enter") {
            evento.preventDefault();
            enviar();
        }
    });
    await cargarConversaciones();

    const conversacionInicial = new URLSearchParams(window.location.search).get("conversacion");
    if (conversacionInicial) {
        const datos = await llamarApi("conversaciones");
        const conversacion = datos.conversaciones.find((item) =>
            String(item.conversacion_id) === conversacionInicial
        );
        if (conversacion) {
            await mostrarMensajes(conversacion.conversacion_id, conversacion.gamertag, conversacion.url_avatar);
        }
    }
};

// Vista previa sin cuenta
const configurarVistaPrevia = () => {
    document.body.classList.add("vista-previa");
    const aviso = crearElemento("div", "vista-previa__aviso", "Vista previa sin contenido · ");
    const acceso = crearElemento("a", "", "Iniciar sesión");
    acceso.href = "index.php";
    aviso.append(acceso);
    document.querySelector(".barra-superior")?.after(aviso);

    document.querySelectorAll('a[href$=".html"]').forEach((enlace) => {
        const url = new URL(enlace.href);
        url.searchParams.set("preview", "1");
        enlace.href = url.href;
    });

    crearCompositor();
    const feed = document.querySelector(".muro__posts section") || document.querySelector(".posts");
    if (feed) feed.append(crearElemento("p", "feed-vacio", "No hay publicaciones en la vista previa."));

    const videojuegos = document.querySelector(".videojuegos");
    if (videojuegos) {
        videojuegos.innerHTML = '<div class="videojuegos__plataformas"><h3>Mis plataformas favoritas</h3></div><div class="videojuegos__plataformas"><h3>Mis juegos favoritos</h3></div>';
    }

    const productos = document.querySelector(".contenedor-tienda");
    if (productos) productos.append(crearElemento("p", "feed-vacio", "No hay artículos en la vista previa."));
    const detalleProducto = document.querySelector(".contenedor-producto");
    if (detalleProducto) {
        detalleProducto.replaceChildren(crearElemento("p", "feed-vacio", "No hay ningún artículo seleccionado."));
        detalleProducto.hidden = false;
    }
    const listaEscuadrones = document.querySelector(".escuadrones__lista");
    if (listaEscuadrones) {
        listaEscuadrones.append(crearElemento("p", "feed-vacio", "No hay escuadras en la vista previa."));
        document.querySelector(".escuadrones__contador").textContent = "0 disponibles";
    }

    document.querySelectorAll("button, input, select, textarea").forEach((control) => {
        control.disabled = true;
    });
    const habilitarBoton = (selector, accion) => {
        const boton = document.querySelector(selector);
        if (!boton) return;
        boton.disabled = false;
        boton.addEventListener("click", accion);
    };
    habilitarBoton("#boton-mensaje", () => { window.location.href = "chat.html?preview=1"; });
    habilitarBoton("#boton-salir", () => { window.location.href = "index.php"; });
    habilitarBoton("#crear-escuadron", () => { window.location.href = "crear-escuadron.html?preview=1"; });
    habilitarBoton("#vender-producto", alternarFormularioVenta);
};

// Inicio de la aplicación
const iniciarAplicacion = async () => {
    if (modoVistaPrevia) {
        configurarVistaPrevia();
        return;
    }
    try {
        await cargarSesion();
        prepararBarra();
        prepararBusquedaGlobal();
        crearCompositor();
        await Promise.all([cargarPerfil(), cargarFeed()]);
        await cargarAmigosEnLinea();
        iniciarTienda();
        iniciarEscuadrones();
        await iniciarChat();
        await iniciarProducto();
        await iniciarAdministracion();
    } catch (error) {
        if (!window.location.pathname.endsWith("index.php")) notificar(error.message, true);
    }
};

iniciarAplicacion();
