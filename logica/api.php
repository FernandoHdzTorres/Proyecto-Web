<?php

require_once __DIR__ . '/funciones.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$entrada = $metodo === 'POST' ? leerEntrada() : $_GET;
$accion = $entrada['action'] ?? $_GET['action'] ?? '';

if ($accion === 'csrf' && $metodo === 'GET') {
    respuestaJson(200, ['token_csrf' => tokenCsrf()]);
}

if ($metodo !== 'GET' && $metodo !== 'POST') {
    header('Allow: GET, POST');
    respuestaJson(405, ['error' => 'Método no permitido.']);
}

if ($metodo === 'POST') {
    validarCsrf($entrada);
}

try {
    require_once __DIR__ . '/conexion.php';
    $accionesPublicas = ['me', 'perfil', 'catalogos', 'feed', 'comentarios', 'escuadrones',
        'mensajes_escuadron', 'productos', 'producto', 'buscar_usuarios'];
    $vistaPrevia = $metodo === 'GET'
        && filter_var($entrada['preview'] ?? false, FILTER_VALIDATE_BOOLEAN)
        && in_array($accion, $accionesPublicas, true);
    if ($vistaPrevia) {
        $usuarioId = 0;
        $esAdministrador = false;
    } else {
        $usuarioId = usuarioAutenticado();
        $stmtAdmin = $conexion->prepare('SELECT es_administrador FROM usuarios WHERE usuario_id = :id');
        $stmtAdmin->execute([':id' => $usuarioId]);
        $esAdministrador = (bool)$stmtAdmin->fetchColumn();
    }

    if ($metodo === 'GET') {
        switch ($accion) {
            case 'me':
            case 'perfil':
                $objetivoId = filter_var($entrada['usuario_id'] ?? $usuarioId, FILTER_VALIDATE_INT);
                if ($vistaPrevia && !$objetivoId) {
                    $objetivoId = (int)$conexion->query(
                        "SELECT u.usuario_id FROM usuarios u
                         JOIN perfiles p ON p.usuario_id = u.usuario_id
                         WHERE u.estado_cuenta = 'activo'
                         ORDER BY u.usuario_id LIMIT 1"
                    )->fetchColumn();
                }
                if (!$objetivoId) {
                    respuestaJson(422, ['error' => 'El perfil solicitado no es válido.']);
                }

                $stmt = $conexion->prepare(
                    'SELECT u.usuario_id, p.nombre, p.apellido, p.gamertag, p.biografia,
                            p.url_avatar, p.url_portada, u.correo_electronico,
                            u.es_administrador
                     FROM usuarios u JOIN perfiles p ON p.usuario_id = u.usuario_id
                     WHERE u.usuario_id = :usuario_id AND u.estado_cuenta = \'activo\''
                );
                $stmt->execute([':usuario_id' => $objetivoId]);
                $perfil = $stmt->fetch();
                if (!$perfil) {
                    respuestaJson(404, ['error' => 'No se encontró el perfil.']);
                }

                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM amistades
                     WHERE estado_amistad = 'aceptada'
                       AND (usuario_menor_id = :usuario_menor_id OR usuario_mayor_id = :usuario_mayor_id)"
                );
                $stmt->execute([
                    ':usuario_menor_id' => $objetivoId,
                    ':usuario_mayor_id' => $objetivoId,
                ]);
                $perfil['amigos'] = (int)$stmt->fetchColumn();

                $stmt = $conexion->prepare(
                    'SELECT j.juego_id, j.nombre, j.url_imagen, f.motivo
                     FROM juegos_favoritos_usuario f JOIN juegos j ON j.juego_id = f.juego_id
                     WHERE f.usuario_id = :usuario_id ORDER BY f.orden_visualizacion, j.nombre'
                );
                $stmt->execute([':usuario_id' => $objetivoId]);
                $perfil['juegos'] = $stmt->fetchAll();

                $stmt = $conexion->prepare(
                    'SELECT p.plataforma_id, p.nombre, p.url_imagen
                     FROM plataformas_favoritas_usuario f JOIN plataformas p ON p.plataforma_id = f.plataforma_id
                     WHERE f.usuario_id = :usuario_id ORDER BY p.nombre'
                );
                $stmt->execute([':usuario_id' => $objetivoId]);
                $perfil['plataformas'] = $stmt->fetchAll();

                $stmt = $conexion->prepare(
                    'SELECT COUNT(*) FROM publicaciones WHERE autor_usuario_id = :usuario_id'
                );
                $stmt->execute([':usuario_id' => $objetivoId]);
                $perfil['publicaciones'] = (int)$stmt->fetchColumn();

                $stmt = $conexion->prepare(
                    'SELECT COALESCE(SUM(v.valor), 0)
                     FROM votos_honor v
                     LEFT JOIN publicaciones pub ON pub.publicacion_id = v.publicacion_id
                     LEFT JOIN comentarios com ON com.comentario_id = v.comentario_id
                     WHERE pub.autor_usuario_id = :autor_publicacion
                        OR com.autor_usuario_id = :autor_comentario'
                );
                $stmt->execute([
                    ':autor_publicacion' => $objetivoId,
                    ':autor_comentario' => $objetivoId,
                ]);
                $perfil['honor'] = (int)$stmt->fetchColumn();

                unset($perfil['correo_electronico']);
                $perfil['estado_amistad'] = null;
                $perfil['solicitado_por_usuario_id'] = null;
                if (!$vistaPrevia && $objetivoId !== $usuarioId) {
                    $stmt = $conexion->prepare(
                        'SELECT estado_amistad, solicitado_por_usuario_id FROM amistades
                         WHERE usuario_menor_id = LEAST(:usuario_a, :usuario_b)
                           AND usuario_mayor_id = GREATEST(:usuario_c, :usuario_d)'
                    );
                    $stmt->execute([
                        ':usuario_a' => $usuarioId,
                        ':usuario_b' => $objetivoId,
                        ':usuario_c' => $usuarioId,
                        ':usuario_d' => $objetivoId,
                    ]);
                    $amistad = $stmt->fetch();
                    if ($amistad) {
                        $perfil['estado_amistad'] = $amistad['estado_amistad'];
                        $perfil['solicitado_por_usuario_id'] = (int)$amistad['solicitado_por_usuario_id'];
                    }
                }
                respuestaJson(200, ['perfil' => $perfil, 'es_mi_perfil' => $objetivoId === $usuarioId]);

            case 'catalogos':
                $juegos = $conexion->query('SELECT juego_id, nombre, url_imagen FROM juegos WHERE esta_activo = 1 ORDER BY nombre')->fetchAll();
                $plataformas = $conexion->query('SELECT plataforma_id, nombre, url_imagen FROM plataformas WHERE esta_activa = 1 ORDER BY nombre')->fetchAll();
                respuestaJson(200, ['juegos' => $juegos, 'plataformas' => $plataformas]);

            case 'feed':
                $autorId = filter_var($entrada['autor_usuario_id'] ?? null, FILTER_VALIDATE_INT);
                $filtroAutor = $autorId ? ' AND p.autor_usuario_id = :autor_usuario_id' : '';
                $stmt = $conexion->prepare(
                    "SELECT p.publicacion_id, p.autor_usuario_id, p.contenido, p.tipo_publicacion, p.creado_en,
                            pr.nombre, pr.apellido, pr.gamertag, pr.url_avatar,
                            (SELECT COUNT(*) FROM reacciones_publicacion r WHERE r.publicacion_id = p.publicacion_id) AS total_reacciones,
                            (SELECT COUNT(*) FROM comentarios c WHERE c.publicacion_id = p.publicacion_id) AS total_comentarios,
                            (SELECT tipo_reaccion FROM reacciones_publicacion r
                             WHERE r.publicacion_id = p.publicacion_id AND r.usuario_id = :usuario_reaccion) AS mi_reaccion,
                            (SELECT COALESCE(SUM(v.valor), 0) FROM votos_honor v WHERE v.publicacion_id = p.publicacion_id) AS honor,
                            (SELECT valor FROM votos_honor v
                             WHERE v.publicacion_id = p.publicacion_id AND v.votante_usuario_id = :usuario_voto) AS mi_honor
                     FROM publicaciones p JOIN perfiles pr ON pr.usuario_id = p.autor_usuario_id
                     WHERE p.visibilidad = 'publica' $filtroAutor
                     ORDER BY p.creado_en DESC, p.publicacion_id DESC LIMIT 50"
                );
                $parametros = [
                    ':usuario_reaccion' => $usuarioId,
                    ':usuario_voto' => $usuarioId,
                ];
                if ($autorId) {
                    $parametros[':autor_usuario_id'] = $autorId;
                }
                $stmt->execute($parametros);
                $publicaciones = $stmt->fetchAll();

                if ($publicaciones) {
                    $ids = array_column($publicaciones, 'publicacion_id');
                    $marcas = implode(',', array_fill(0, count($ids), '?'));
                    $mediaStmt = $conexion->prepare(
                        "SELECT publicacion_id, tipo_multimedia, url_multimedia, texto_alternativo
                         FROM multimedia_publicacion WHERE publicacion_id IN ($marcas)
                         ORDER BY orden_visualizacion"
                    );
                    $mediaStmt->execute($ids);
                    $multimedia = [];
                    foreach ($mediaStmt->fetchAll() as $archivo) {
                        $multimedia[$archivo['publicacion_id']][] = $archivo;
                    }
                    foreach ($publicaciones as &$publicacion) {
                        $publicacion['multimedia'] = $multimedia[$publicacion['publicacion_id']] ?? [];
                    }
                    unset($publicacion);
                }
                respuestaJson(200, ['publicaciones' => $publicaciones]);

            case 'comentarios':
                $publicacionId = filter_var($entrada['publicacion_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$publicacionId) {
                    respuestaJson(422, ['error' => 'La publicación no es válida.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT c.comentario_id, c.autor_usuario_id, c.contenido, c.creado_en,
                            p.nombre, p.apellido, p.gamertag, p.url_avatar,
                            (SELECT COALESCE(SUM(v.valor), 0) FROM votos_honor v WHERE v.comentario_id = c.comentario_id) AS honor,
                            (SELECT valor FROM votos_honor v
                             WHERE v.comentario_id = c.comentario_id AND v.votante_usuario_id = :usuario_id) AS mi_honor
                     FROM comentarios c JOIN perfiles p ON p.usuario_id = c.autor_usuario_id
                     WHERE c.publicacion_id = :publicacion_id ORDER BY c.creado_en, c.comentario_id'
                );
                $stmt->execute([':publicacion_id' => $publicacionId, ':usuario_id' => $usuarioId]);
                respuestaJson(200, ['comentarios' => $stmt->fetchAll()]);

            case 'escuadrones':
                $stmt = $conexion->prepare(
                    "SELECT e.escuadron_id, e.nombre, e.descripcion, e.max_miembros,
                            e.etiqueta_duracion, e.estado_escuadron, e.creado_en,
                            j.nombre AS juego, pl.nombre AS plataforma,
                            pe.nombre AS creador_nombre, pe.gamertag AS creador_gamertag,
                            COUNT(DISTINCT CASE WHEN m.estado_membresia = 'activo' THEN m.usuario_id END) AS miembros,
                            MAX(CASE WHEN m.usuario_id = :usuario_id AND m.estado_membresia = 'activo' THEN 1 ELSE 0 END) AS soy_miembro
                     FROM escuadrones e
                     JOIN juegos j ON j.juego_id = e.juego_id
                     LEFT JOIN plataformas pl ON pl.plataforma_id = e.plataforma_id
                     JOIN perfiles pe ON pe.usuario_id = e.creado_por_usuario_id
                     LEFT JOIN miembros_escuadron m ON m.escuadron_id = e.escuadron_id
                     WHERE e.estado_escuadron IN ('abierto', 'lleno')
                     GROUP BY e.escuadron_id
                     HAVING miembros > 0
                     ORDER BY e.creado_en DESC LIMIT 100"
                );
                $stmt->execute([':usuario_id' => $usuarioId]);
                respuestaJson(200, ['escuadrones' => $stmt->fetchAll()]);

            case 'mensajes_escuadron':
                $escuadronId = filter_var($entrada['escuadron_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$escuadronId) {
                    respuestaJson(422, ['error' => 'La escuadra no es válida.']);
                }
                $stmt = $conexion->prepare(
                    "SELECT 1 FROM miembros_escuadron
                     WHERE escuadron_id = :escuadron_id AND usuario_id = :usuario_id AND estado_membresia = 'activo'"
                );
                $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
                if (!$stmt->fetchColumn()) {
                    respuestaJson(403, ['error' => 'Debes pertenecer a la escuadra para ver su canal.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT m.mensaje_escuadron_id, m.contenido, m.creado_en, p.nombre, p.gamertag, p.url_avatar
                     FROM mensajes_escuadron m JOIN perfiles p ON p.usuario_id = m.remitente_usuario_id
                     WHERE m.escuadron_id = :escuadron_id ORDER BY m.creado_en DESC LIMIT 100'
                );
                $stmt->execute([':escuadron_id' => $escuadronId]);
                respuestaJson(200, ['mensajes' => array_reverse($stmt->fetchAll())]);

            case 'productos':
                $plataforma = $entrada['plataforma'] ?? '';
                $busqueda = $entrada['q'] ?? '';
                $sql = "SELECT pr.producto_id, pr.nombre, pr.descripcion, pr.precio, pr.codigo_moneda,
                               pr.cantidad_stock, pl.nombre AS plataforma, pe.gamertag AS vendedor,
                               (SELECT url_imagen FROM imagenes_producto i
                                WHERE i.producto_id = pr.producto_id ORDER BY i.orden_visualizacion LIMIT 1) AS imagen
                        FROM productos pr
                        JOIN plataformas pl ON pl.plataforma_id = pr.plataforma_id
                        JOIN perfiles pe ON pe.usuario_id = pr.vendedor_usuario_id
                        WHERE pr.estado_producto = 'disponible' AND pr.cantidad_stock > 0";
                $parametros = [];
                if ($plataforma !== '') {
                    $sql .= ' AND pl.nombre = :plataforma';
                    $parametros[':plataforma'] = $plataforma;
                }
                if ($busqueda !== '') {
                    $sql .= ' AND (pr.nombre LIKE :busqueda_nombre OR pr.descripcion LIKE :busqueda_descripcion)';
                    $parametros[':busqueda_nombre'] = '%' . $busqueda . '%';
                    $parametros[':busqueda_descripcion'] = '%' . $busqueda . '%';
                }
                $sql .= ' ORDER BY pr.creado_en DESC LIMIT 100';
                $stmt = $conexion->prepare($sql);
                $stmt->execute($parametros);
                respuestaJson(200, ['productos' => $stmt->fetchAll()]);

            case 'carrito':
                $stmt = $conexion->prepare('SELECT carrito_id FROM carritos WHERE usuario_id = :usuario_id');
                $stmt->execute([':usuario_id' => $usuarioId]);
                $carritoId = $stmt->fetchColumn();
                if (!$carritoId) {
                    respuestaJson(200, ['articulos' => [], 'total' => 0]);
                }
                $stmt = $conexion->prepare(
                    'SELECT a.producto_id, a.cantidad, a.precio_unitario, a.codigo_moneda,
                            p.nombre, p.cantidad_stock,
                            (SELECT url_imagen FROM imagenes_producto i
                             WHERE i.producto_id = p.producto_id ORDER BY i.orden_visualizacion LIMIT 1) AS imagen
                     FROM articulos_carrito a JOIN productos p ON p.producto_id = a.producto_id
                     WHERE a.carrito_id = :carrito_id ORDER BY a.agregado_en DESC'
                );
                $stmt->execute([':carrito_id' => $carritoId]);
                $articulos = $stmt->fetchAll();
                $total = array_sum(array_map(
                    static fn(array $articulo): float => (float)$articulo['precio_unitario'] * (int)$articulo['cantidad'],
                    $articulos
                ));
                respuestaJson(200, ['articulos' => $articulos, 'total' => round($total, 2)]);

            case 'producto':
                $productoId = filter_var($entrada['producto_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$productoId) {
                    respuestaJson(422, ['error' => 'El producto no es válido.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT pr.producto_id, pr.vendedor_usuario_id, pr.nombre, pr.descripcion,
                            pr.precio, pr.codigo_moneda, pr.cantidad_stock, pr.estado_producto,
                            pl.nombre AS plataforma, pe.nombre AS vendedor_nombre, pe.gamertag AS vendedor_gamertag
                     FROM productos pr JOIN plataformas pl ON pl.plataforma_id = pr.plataforma_id
                     JOIN perfiles pe ON pe.usuario_id = pr.vendedor_usuario_id
                     WHERE pr.producto_id = :producto_id'
                );
                $stmt->execute([':producto_id' => $productoId]);
                $producto = $stmt->fetch();
                if (!$producto) {
                    respuestaJson(404, ['error' => 'No se encontró el producto.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT url_imagen FROM imagenes_producto
                     WHERE producto_id = :producto_id ORDER BY orden_visualizacion'
                );
                $stmt->execute([':producto_id' => $productoId]);
                $producto['imagenes'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                respuestaJson(200, ['producto' => $producto]);

            case 'notificaciones':
                $stmt = $conexion->prepare(
                    'SELECT n.notificacion_id, n.tipo_notificacion, n.mensaje, n.creado_en, n.leido_en,
                            n.actor_usuario_id, n.mensaje_directo_id,
                            p.gamertag AS actor,
                            md.conversacion_id, COALESCE(n.escuadron_id, me.escuadron_id) AS escuadron_id,
                            a.estado_amistad
                     FROM notificaciones n LEFT JOIN perfiles p ON p.usuario_id = n.actor_usuario_id
                     LEFT JOIN mensajes_directos md ON md.mensaje_id = n.mensaje_directo_id
                     LEFT JOIN mensajes_escuadron me ON me.mensaje_escuadron_id = n.mensaje_escuadron_id
                     LEFT JOIN amistades a
                       ON n.actor_usuario_id IS NOT NULL
                      AND a.usuario_menor_id = LEAST(n.destinatario_usuario_id, n.actor_usuario_id)
                      AND a.usuario_mayor_id = GREATEST(n.destinatario_usuario_id, n.actor_usuario_id)
                     WHERE n.destinatario_usuario_id = :usuario_id
                     ORDER BY n.creado_en DESC LIMIT 50'
                );
                $stmt->execute([':usuario_id' => $usuarioId]);
                $notificaciones = $stmt->fetchAll();
                $stmt = $conexion->prepare('SELECT COUNT(*) FROM notificaciones WHERE destinatario_usuario_id = :usuario_id AND leido_en IS NULL');
                $stmt->execute([':usuario_id' => $usuarioId]);
                respuestaJson(200, ['notificaciones' => $notificaciones, 'no_leidas' => (int)$stmt->fetchColumn()]);

            case 'conversaciones':
                $stmt = $conexion->prepare(
                    'SELECT c.conversacion_id, otro.usuario_id AS otro_usuario_id,
                            otro.nombre, otro.gamertag, otro.url_avatar,
                            md.contenido AS ultimo_mensaje, md.creado_en AS ultimo_mensaje_en
                     FROM miembros_conversacion propio
                     JOIN conversaciones c ON c.conversacion_id = propio.conversacion_id
                     JOIN miembros_conversacion ajeno ON ajeno.conversacion_id = c.conversacion_id
                         AND ajeno.usuario_id <> :usuario_otro
                     JOIN perfiles otro ON otro.usuario_id = ajeno.usuario_id
                     LEFT JOIN mensajes_directos md ON md.mensaje_id = (
                         SELECT MAX(m2.mensaje_id) FROM mensajes_directos m2
                         WHERE m2.conversacion_id = c.conversacion_id
                     )
                     WHERE propio.usuario_id = :usuario_id ORDER BY md.creado_en DESC, c.creado_en DESC'
                );
                $stmt->execute([':usuario_otro' => $usuarioId, ':usuario_id' => $usuarioId]);
                respuestaJson(200, ['conversaciones' => $stmt->fetchAll()]);

            case 'mensajes':
                $conversacionId = filter_var($entrada['conversacion_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$conversacionId) {
                    respuestaJson(422, ['error' => 'La conversación no es válida.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT 1 FROM miembros_conversacion
                     WHERE conversacion_id = :conversacion_id AND usuario_id = :usuario_id'
                );
                $stmt->execute([':conversacion_id' => $conversacionId, ':usuario_id' => $usuarioId]);
                if (!$stmt->fetchColumn()) {
                    respuestaJson(403, ['error' => 'No tienes acceso a esta conversación.']);
                }
                $stmt = $conexion->prepare(
                    'SELECT m.mensaje_id, m.remitente_usuario_id, m.contenido, m.creado_en,
                            p.nombre, p.gamertag
                     FROM mensajes_directos m JOIN perfiles p ON p.usuario_id = m.remitente_usuario_id
                     WHERE m.conversacion_id = :conversacion_id ORDER BY m.creado_en DESC LIMIT 100'
                );
                $stmt->execute([':conversacion_id' => $conversacionId]);
                respuestaJson(200, ['mensajes' => array_reverse($stmt->fetchAll())]);

            case 'buscar_usuarios':
                $q = $entrada['q'] ?? '';
                $stmt = $conexion->prepare(
                    "SELECT u.usuario_id, p.nombre, p.gamertag, p.url_avatar,
                            a.estado_amistad, a.solicitado_por_usuario_id
                     FROM usuarios u JOIN perfiles p ON p.usuario_id = u.usuario_id
                     LEFT JOIN amistades a
                       ON a.usuario_menor_id = LEAST(u.usuario_id, :usuario_menor_id)
                      AND a.usuario_mayor_id = GREATEST(u.usuario_id, :usuario_mayor_id)
                     WHERE u.usuario_id <> :usuario_id AND u.estado_cuenta = 'activo'
                       AND (p.gamertag LIKE :q_gamertag OR p.nombre LIKE :q_nombre)
                     ORDER BY p.gamertag LIMIT 20"
                );
                $stmt->execute([
                    ':usuario_menor_id' => $usuarioId,
                    ':usuario_mayor_id' => $usuarioId,
                    ':usuario_id' => $usuarioId,
                    ':q_gamertag' => '%' . $q . '%',
                    ':q_nombre' => '%' . $q . '%',
                ]);
                respuestaJson(200, ['usuarios' => $stmt->fetchAll()]);

            case 'administracion':
                if (!$esAdministrador) {
                    respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
                }
                $stmt = $conexion->query(
                    "SELECT u.usuario_id, u.correo_electronico, u.estado_cuenta,
                            p.nombre, p.apellido, p.gamertag,
                            (COALESCE((SELECT SUM(v.valor) FROM votos_honor v
                                JOIN publicaciones pub ON pub.publicacion_id = v.publicacion_id
                                WHERE pub.autor_usuario_id = u.usuario_id), 0)
                            + COALESCE((SELECT SUM(v.valor) FROM votos_honor v
                                JOIN comentarios com ON com.comentario_id = v.comentario_id
                                WHERE com.autor_usuario_id = u.usuario_id), 0)) AS honor
                     FROM usuarios u
                     JOIN perfiles p ON p.usuario_id = u.usuario_id
                     WHERE u.estado_cuenta = 'activo' AND u.es_administrador = 0
                     HAVING honor < 0
                     ORDER BY honor ASC"
                );
                $cuentas = $stmt->fetchAll();
                $juegos = $conexion->query('SELECT juego_id, nombre, url_imagen FROM juegos WHERE esta_activo = 1 ORDER BY nombre')->fetchAll();
                $plataformas = $conexion->query('SELECT plataforma_id, nombre, url_imagen FROM plataformas WHERE esta_activa = 1 ORDER BY nombre')->fetchAll();
                respuestaJson(200, [
                    'cuentas' => $cuentas,
                    'juegos' => $juegos,
                    'plataformas' => $plataformas,
                ]);

            default:
                respuestaJson(404, ['error' => 'La acción solicitada no existe.']);
        }
    }

    switch ($accion) {
        case 'publicar':
            $contenido = $entrada['contenido'] ?? '';
            $tipo = $entrada['tipo'] ?? 'estado';
            $tiposValidos = ['estado', 'foto', 'video', 'noticia', 'logro'];
            if (!in_array($tipo, $tiposValidos, true)) {
                respuestaJson(422, ['error' => 'El tipo de publicación no es válido.']);
            }
            $mediaUrl = subirImagen($_FILES['multimedia'] ?? [], 'publicaciones');
            if ($mediaUrl !== null) {
                $tipo = 'foto';
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                'INSERT INTO publicaciones (autor_usuario_id, contenido, tipo_publicacion)
                 VALUES (:usuario_id, :contenido, :tipo)'
            );
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':contenido' => $contenido,
                ':tipo' => $tipo,
            ]);
            $publicacionId = (int)$conexion->lastInsertId();
            if ($mediaUrl !== null) {
                $stmt = $conexion->prepare(
                    "INSERT INTO multimedia_publicacion (publicacion_id, tipo_multimedia, url_multimedia)
                     VALUES (:publicacion_id, 'imagen', :url)"
                );
                $stmt->execute([':publicacion_id' => $publicacionId, ':url' => $mediaUrl]);
            }
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Publicación creada.', 'publicacion_id' => $publicacionId]);

        case 'comentar':
            $publicacionId = filter_var($entrada['publicacion_id'] ?? null, FILTER_VALIDATE_INT);
            $contenido = $entrada['contenido'] ?? '';
            if (!$publicacionId) {
                respuestaJson(422, ['error' => 'La publicación no es válida.']);
            }
            $stmt = $conexion->prepare(
                "SELECT autor_usuario_id FROM publicaciones
                 WHERE publicacion_id = :publicacion_id AND comentarios_habilitados = 1"
            );
            $stmt->execute([':publicacion_id' => $publicacionId]);
            $publicacion = $stmt->fetch();
            if (!$publicacion) {
                respuestaJson(404, ['error' => 'No se encontró la publicación o sus comentarios están cerrados.']);
            }
            $stmt = $conexion->prepare(
                'INSERT INTO comentarios (publicacion_id, autor_usuario_id, contenido)
                 VALUES (:publicacion_id, :usuario_id, :contenido)'
            );
            $stmt->execute([
                ':publicacion_id' => $publicacionId,
                ':usuario_id' => $usuarioId,
                ':contenido' => $contenido,
            ]);
            if ((int)$publicacion['autor_usuario_id'] !== $usuarioId) {
                crearNotificacion($conexion, (int)$publicacion['autor_usuario_id'], $usuarioId, 'comentario', 'Comentó tu publicación.', 'publicacion_id', $publicacionId);
            }
            respuestaJson(201, ['mensaje' => 'Comentario agregado.', 'comentario_id' => (int)$conexion->lastInsertId()]);

        case 'reaccionar':
            $publicacionId = filter_var($entrada['publicacion_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$publicacionId) {
                respuestaJson(422, ['error' => 'La publicación no es válida.']);
            }
            $stmt = $conexion->prepare('SELECT autor_usuario_id FROM publicaciones WHERE publicacion_id = :id');
            $stmt->execute([':id' => $publicacionId]);
            $autorId = $stmt->fetchColumn();
            if (!$autorId) {
                respuestaJson(404, ['error' => 'No se encontró la publicación.']);
            }
            $stmt = $conexion->prepare(
                'SELECT tipo_reaccion FROM reacciones_publicacion
                 WHERE publicacion_id = :publicacion_id AND usuario_id = :usuario_id'
            );
            $stmt->execute([':publicacion_id' => $publicacionId, ':usuario_id' => $usuarioId]);
            $reaccionActual = $stmt->fetchColumn();
            if ($reaccionActual) {
                $stmt = $conexion->prepare(
                    'DELETE FROM reacciones_publicacion WHERE publicacion_id = :publicacion_id AND usuario_id = :usuario_id'
                );
                $stmt->execute([':publicacion_id' => $publicacionId, ':usuario_id' => $usuarioId]);
                $activa = false;
            } else {
                $stmt = $conexion->prepare(
                    "INSERT INTO reacciones_publicacion (publicacion_id, usuario_id, tipo_reaccion)
                     VALUES (:publicacion_id, :usuario_id, 'me_gusta')"
                );
                $stmt->execute([':publicacion_id' => $publicacionId, ':usuario_id' => $usuarioId]);
                $activa = true;
                if ((int)$autorId !== $usuarioId) {
                    crearNotificacion($conexion, (int)$autorId, $usuarioId, 'reaccion', 'Le gustó tu publicación.', 'publicacion_id', $publicacionId);
                }
            }
            respuestaJson(200, ['activa' => $activa]);

        case 'votar_honor':
            $tipo = $entrada['tipo_objetivo'] ?? '';
            $objetivoId = filter_var($entrada['objetivo_id'] ?? null, FILTER_VALIDATE_INT);
            $valor = filter_var($entrada['valor'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($tipo, ['publicacion', 'comentario'], true) || !$objetivoId || !in_array($valor, [-1, 1], true)) {
                respuestaJson(422, ['error' => 'El voto de honor no es válido.']);
            }
            $columna = $tipo === 'publicacion' ? 'publicacion_id' : 'comentario_id';
            $tabla = $tipo === 'publicacion' ? 'publicaciones' : 'comentarios';
            $idColumna = $tipo === 'publicacion' ? 'publicacion_id' : 'comentario_id';
            $autorColumna = 'autor_usuario_id';
            $stmt = $conexion->prepare("SELECT $autorColumna FROM $tabla WHERE $idColumna = :id");
            $stmt->execute([':id' => $objetivoId]);
            $autorId = $stmt->fetchColumn();
            if (!$autorId) {
                respuestaJson(404, ['error' => 'No se encontró el elemento.']);
            }
            if ((int)$autorId === $usuarioId) {
                respuestaJson(422, ['error' => 'No puedes votar por tu propio contenido.']);
            }
            $stmt = $conexion->prepare(
                "SELECT voto_honor_id, valor FROM votos_honor
                 WHERE $columna = :objetivo_id AND votante_usuario_id = :usuario_id"
            );
            $stmt->execute([':objetivo_id' => $objetivoId, ':usuario_id' => $usuarioId]);
            $votoActual = $stmt->fetch();
            if ($votoActual && (int)$votoActual['valor'] === $valor) {
                $stmt = $conexion->prepare('DELETE FROM votos_honor WHERE voto_honor_id = :id');
                $stmt->execute([':id' => $votoActual['voto_honor_id']]);
                $nuevoValor = 0;
            } elseif ($votoActual) {
                $stmt = $conexion->prepare('UPDATE votos_honor SET valor = :valor WHERE voto_honor_id = :id');
                $stmt->execute([':valor' => $valor, ':id' => $votoActual['voto_honor_id']]);
                $nuevoValor = $valor;
            } else {
                $stmt = $conexion->prepare(
                    "INSERT INTO votos_honor (votante_usuario_id, $columna, valor)
                     VALUES (:usuario_id, :objetivo_id, :valor)"
                );
                $stmt->execute([':usuario_id' => $usuarioId, ':objetivo_id' => $objetivoId, ':valor' => $valor]);
                $nuevoValor = $valor;
            }
            respuestaJson(200, ['mi_honor' => $nuevoValor]);

        case 'actualizar_perfil':
            $nombre = $entrada['nombre'] ?? '';
            $apellido = $entrada['apellido'] ?? '';
            $gamertag = $entrada['gamertag'] ?? '';
            $biografia = $entrada['biografia'] ?? '';
            $avatar = subirImagen($_FILES['foto_perfil'] ?? [], 'perfiles');
            $portada = subirImagen($_FILES['portada'] ?? [], 'perfiles');

            $conexion->beginTransaction();
            $sql = 'UPDATE perfiles SET nombre = :nombre, apellido = :apellido,
                    gamertag = :gamertag, biografia = :biografia';
            $parametros = [
                ':nombre' => $nombre,
                ':apellido' => $apellido,
                ':gamertag' => $gamertag,
                ':biografia' => $biografia,
                ':usuario_id' => $usuarioId,
            ];
            if ($avatar !== null) {
                $sql .= ', url_avatar = :avatar';
                $parametros[':avatar'] = $avatar;
            }
            if ($portada !== null) {
                $sql .= ', url_portada = :portada';
                $parametros[':portada'] = $portada;
            }
            $sql .= ' WHERE usuario_id = :usuario_id';
            $stmt = $conexion->prepare($sql);
            $stmt->execute($parametros);

            $juegoIds = $entrada['juegos_favoritos'] ?? [];
            if (!is_array($juegoIds)) {
                $juegoIds = [$juegoIds];
            }
            $motivo = $entrada['juego_motivo'] ?? '';
            $conexion->prepare('DELETE FROM juegos_favoritos_usuario WHERE usuario_id = :id')
                ->execute([':id' => $usuarioId]);
            foreach ($juegoIds as $juegoId) {
                $juegoId = filter_var($juegoId, FILTER_VALIDATE_INT);
                if (!$juegoId) continue;
                $stmt = $conexion->prepare(
                    'INSERT INTO juegos_favoritos_usuario (usuario_id, juego_id, motivo)
                     VALUES (:usuario_id, :juego_id, :motivo)'
                );
                $stmt->execute([
                    ':usuario_id' => $usuarioId,
                    ':juego_id' => $juegoId,
                    ':motivo' => $motivo,
                ]);
            }

            $plataformaIds = $entrada['plataformas_favoritas'] ?? [];
            if (!is_array($plataformaIds)) {
                $plataformaIds = [$plataformaIds];
            }
            $conexion->prepare('DELETE FROM plataformas_favoritas_usuario WHERE usuario_id = :id')
                ->execute([':id' => $usuarioId]);
            foreach ($plataformaIds as $plataformaId) {
                $plataformaId = filter_var($plataformaId, FILTER_VALIDATE_INT);
                if (!$plataformaId) continue;
                $stmt = $conexion->prepare(
                    'INSERT INTO plataformas_favoritas_usuario (usuario_id, plataforma_id)
                     VALUES (:usuario_id, :plataforma_id)'
                );
                $stmt->execute([':usuario_id' => $usuarioId, ':plataforma_id' => $plataformaId]);
            }
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Perfil actualizado.']);

        case 'crear_escuadron':
            $nombre = $entrada['nombre'] ?? '';
            $juegoNombre = $entrada['juego'] ?? '';
            $plataformaNombre = $entrada['plataforma'] ?? 'Cualquiera';
            $descripcion = $entrada['descripcion'] ?? '';
            $duracion = $entrada['duracion'] ?? '';
            $cupo = filter_var($entrada['cupo'] ?? 4, FILTER_VALIDATE_INT);
            if (!in_array($cupo, [2, 3, 4, 5, 8], true)) {
                respuestaJson(422, ['error' => 'Revisa los datos de la escuadra.']);
            }

            $stmt = $conexion->prepare('SELECT juego_id FROM juegos WHERE nombre = :nombre AND esta_activo = 1');
            $stmt->execute([':nombre' => $juegoNombre]);
            $juegoId = $stmt->fetchColumn();
            if (!$juegoId) {
                respuestaJson(422, ['error' => 'El juego seleccionado no está disponible.']);
            }
            $plataformaId = null;
            if ($plataformaNombre !== '' && $plataformaNombre !== 'Cualquiera') {
                $plataformaDb = $plataformaNombre === 'Nintendo Switch' ? 'Nintendo' : $plataformaNombre;
                $stmt = $conexion->prepare('SELECT plataforma_id FROM plataformas WHERE nombre = :nombre');
                $stmt->execute([':nombre' => $plataformaDb]);
                $plataformaId = $stmt->fetchColumn();
                if (!$plataformaId) {
                    respuestaJson(422, ['error' => 'La plataforma seleccionada no está disponible.']);
                }
            }

            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                "INSERT INTO escuadrones
                    (creado_por_usuario_id, juego_id, plataforma_id, nombre, descripcion,
                     max_miembros, etiqueta_duracion, estado_escuadron)
                 VALUES (:usuario_id, :juego_id, :plataforma_id, :nombre, :descripcion, :cupo, :duracion, 'abierto')"
            );
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':juego_id' => $juegoId,
                ':plataforma_id' => $plataformaId,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':cupo' => $cupo,
                ':duracion' => $duracion,
            ]);
            $escuadronId = (int)$conexion->lastInsertId();
            $stmt = $conexion->prepare(
                "INSERT INTO miembros_escuadron (escuadron_id, usuario_id, estado_membresia)
                 VALUES (:escuadron_id, :usuario_id, 'activo')"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Escuadra creada.', 'escuadron_id' => $escuadronId]);

        case 'unirse_escuadron':
            $escuadronId = filter_var($entrada['escuadron_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$escuadronId) {
                respuestaJson(422, ['error' => 'La escuadra no es válida.']);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                'SELECT max_miembros, estado_escuadron FROM escuadrones
                 WHERE escuadron_id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $escuadronId]);
            $escuadron = $stmt->fetch();
            if (!$escuadron || !in_array($escuadron['estado_escuadron'], ['abierto', 'lleno'], true)) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'La escuadra ya no tiene espacios disponibles.']);
            }
            $stmt = $conexion->prepare(
                "SELECT estado_membresia FROM miembros_escuadron
                 WHERE escuadron_id = :escuadron_id AND usuario_id = :usuario_id"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            if ($stmt->fetchColumn() === 'activo') {
                $conexion->commit();
                respuestaJson(200, ['mensaje' => 'Ya perteneces a esta escuadra.']);
            }
            $stmt = $conexion->prepare(
                "SELECT COUNT(*) FROM miembros_escuadron
                 WHERE escuadron_id = :escuadron_id AND estado_membresia = 'activo'"
            );
            $stmt->execute([':escuadron_id' => $escuadronId]);
            $miembrosActivos = (int)$stmt->fetchColumn();
            if ($escuadron['estado_escuadron'] === 'lleno' || $miembrosActivos >= (int)$escuadron['max_miembros']) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'La escuadra ya no tiene espacios disponibles.']);
            }
            $stmt = $conexion->prepare(
                "INSERT INTO miembros_escuadron (escuadron_id, usuario_id, estado_membresia)
                 VALUES (:escuadron_id, :usuario_id, 'activo')
                 ON DUPLICATE KEY UPDATE estado_membresia = 'activo', unido_en = CURRENT_TIMESTAMP, salio_en = NULL"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            $stmt = $conexion->prepare(
                "UPDATE escuadrones SET estado_escuadron = 'lleno'
                 WHERE escuadron_id = :id AND :miembros + 1 >= max_miembros"
            );
            $stmt->execute([':id' => $escuadronId, ':miembros' => $miembrosActivos]);
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Te uniste a la escuadra.']);

        case 'salir_escuadron':
            $escuadronId = filter_var($entrada['escuadron_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$escuadronId) {
                respuestaJson(422, ['error' => 'La escuadra no es válida.']);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare('SELECT escuadron_id FROM escuadrones WHERE escuadron_id = :id FOR UPDATE');
            $stmt->execute([':id' => $escuadronId]);
            if (!$stmt->fetchColumn()) {
                $conexion->rollBack();
                respuestaJson(404, ['error' => 'La escuadra ya no existe.']);
            }
            $stmt = $conexion->prepare(
                "UPDATE miembros_escuadron SET estado_membresia = 'salio', salio_en = CURRENT_TIMESTAMP
                 WHERE escuadron_id = :escuadron_id AND usuario_id = :usuario_id AND estado_membresia = 'activo'"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            if ($stmt->rowCount() === 0) {
                $conexion->rollBack();
                respuestaJson(403, ['error' => 'No perteneces a esta escuadra.']);
            }
            $stmt = $conexion->prepare(
                "SELECT COUNT(*) FROM miembros_escuadron
                 WHERE escuadron_id = :escuadron_id AND estado_membresia = 'activo'"
            );
            $stmt->execute([':escuadron_id' => $escuadronId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $stmt = $conexion->prepare(
                    'DELETE FROM notificaciones WHERE escuadron_id = :escuadron_id
                     OR mensaje_escuadron_id IN
                        (SELECT mensaje_escuadron_id FROM mensajes_escuadron WHERE escuadron_id = :mensajes_escuadron_id)'
                );
                $stmt->execute([':escuadron_id' => $escuadronId, ':mensajes_escuadron_id' => $escuadronId]);
                $stmt = $conexion->prepare('DELETE FROM mensajes_escuadron WHERE escuadron_id = :escuadron_id');
                $stmt->execute([':escuadron_id' => $escuadronId]);
                $stmt = $conexion->prepare('DELETE FROM miembros_escuadron WHERE escuadron_id = :escuadron_id');
                $stmt->execute([':escuadron_id' => $escuadronId]);
                $stmt = $conexion->prepare('DELETE FROM escuadrones WHERE escuadron_id = :escuadron_id');
                $stmt->execute([':escuadron_id' => $escuadronId]);
            } else {
                $stmt = $conexion->prepare(
                    "UPDATE escuadrones SET estado_escuadron = 'abierto'
                     WHERE escuadron_id = :escuadron_id AND estado_escuadron = 'lleno'"
                );
                $stmt->execute([':escuadron_id' => $escuadronId]);
            }
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Saliste de la escuadra.']);

        case 'mensaje_escuadron':
            $escuadronId = filter_var($entrada['escuadron_id'] ?? null, FILTER_VALIDATE_INT);
            $contenido = $entrada['contenido'] ?? '';
            if (!$escuadronId) {
                respuestaJson(422, ['error' => 'La escuadra no es válida.']);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare('SELECT escuadron_id FROM escuadrones WHERE escuadron_id = :id FOR UPDATE');
            $stmt->execute([':id' => $escuadronId]);
            if (!$stmt->fetchColumn()) {
                $conexion->rollBack();
                respuestaJson(404, ['error' => 'La escuadra ya no existe.']);
            }
            $stmt = $conexion->prepare(
                "SELECT 1 FROM miembros_escuadron
                 WHERE escuadron_id = :escuadron_id AND usuario_id = :usuario_id AND estado_membresia = 'activo'"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            if (!$stmt->fetchColumn()) {
                $conexion->rollBack();
                respuestaJson(403, ['error' => 'Debes pertenecer a la escuadra para escribir en el canal.']);
            }
            $stmt = $conexion->prepare(
                'INSERT INTO mensajes_escuadron (escuadron_id, remitente_usuario_id, contenido)
                 VALUES (:escuadron_id, :usuario_id, :contenido)'
            );
            $stmt->execute([
                ':escuadron_id' => $escuadronId,
                ':usuario_id' => $usuarioId,
                ':contenido' => $contenido,
            ]);
            $mensajeId = (int)$conexion->lastInsertId();
            $stmt = $conexion->prepare(
                "SELECT usuario_id FROM miembros_escuadron
                 WHERE escuadron_id = :escuadron_id AND estado_membresia = 'activo' AND usuario_id <> :usuario_id"
            );
            $stmt->execute([':escuadron_id' => $escuadronId, ':usuario_id' => $usuarioId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $destinatarioId) {
                crearNotificacion($conexion, (int)$destinatarioId, $usuarioId, 'mensaje', 'Envió un mensaje en tu escuadra.', 'mensaje_escuadron_id', $mensajeId);
            }
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Mensaje enviado.']);

        case 'abrir_conversacion':
            $otroUsuarioId = filter_var($entrada['otro_usuario_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$otroUsuarioId || $otroUsuarioId === $usuarioId) {
                respuestaJson(422, ['error' => 'Selecciona a otro usuario.']);
            }
            $stmt = $conexion->prepare(
                "SELECT usuario_menor_id, usuario_mayor_id FROM amistades
                 WHERE usuario_menor_id = LEAST(:usuario_1, :usuario_2)
                   AND usuario_mayor_id = GREATEST(:usuario_3, :usuario_4)
                   AND estado_amistad = 'aceptada'"
            );
            $stmt->execute([
                ':usuario_1' => $usuarioId,
                ':usuario_2' => $otroUsuarioId,
                ':usuario_3' => $usuarioId,
                ':usuario_4' => $otroUsuarioId,
            ]);
            if (!$stmt->fetch()) {
                respuestaJson(403, ['error' => 'Solo puedes iniciar un chat con una amistad aceptada.']);
            }
            $stmt = $conexion->prepare(
                'SELECT c.conversacion_id FROM conversaciones c
                 JOIN miembros_conversacion a ON a.conversacion_id = c.conversacion_id AND a.usuario_id = :usuario_a
                 JOIN miembros_conversacion b ON b.conversacion_id = c.conversacion_id AND b.usuario_id = :usuario_b
                 WHERE (SELECT COUNT(*) FROM miembros_conversacion x
                        WHERE x.conversacion_id = c.conversacion_id) = 2 LIMIT 1'
            );
            $stmt->execute([':usuario_a' => $usuarioId, ':usuario_b' => $otroUsuarioId]);
            $conversacionId = $stmt->fetchColumn();
            if (!$conversacionId) {
                $conexion->beginTransaction();
                $stmt = $conexion->prepare('INSERT INTO conversaciones (creado_por_usuario_id) VALUES (:usuario_id)');
                $stmt->execute([':usuario_id' => $usuarioId]);
                $conversacionId = (int)$conexion->lastInsertId();
                $stmt = $conexion->prepare(
                    'INSERT INTO miembros_conversacion (conversacion_id, usuario_id) VALUES
                     (:conversacion_id, :usuario_a), (:conversacion_id, :usuario_b)'
                );
                $stmt->execute([
                    ':conversacion_id' => $conversacionId,
                    ':usuario_a' => $usuarioId,
                    ':usuario_b' => $otroUsuarioId,
                ]);
                $conexion->commit();
            }
            respuestaJson(200, ['conversacion_id' => (int)$conversacionId]);

        case 'enviar_mensaje':
            $conversacionId = filter_var($entrada['conversacion_id'] ?? null, FILTER_VALIDATE_INT);
            $contenido = $entrada['contenido'] ?? '';
            if (!$conversacionId) {
                respuestaJson(422, ['error' => 'La conversación no es válida.']);
            }
            $stmt = $conexion->prepare(
                'SELECT usuario_id FROM miembros_conversacion
                 WHERE conversacion_id = :conversacion_id AND usuario_id = :usuario_id'
            );
            $stmt->execute([':conversacion_id' => $conversacionId, ':usuario_id' => $usuarioId]);
            if (!$stmt->fetchColumn()) {
                respuestaJson(403, ['error' => 'No tienes acceso a esta conversación.']);
            }
            $stmt = $conexion->prepare(
                'INSERT INTO mensajes_directos (conversacion_id, remitente_usuario_id, contenido)
                 VALUES (:conversacion_id, :usuario_id, :contenido)'
            );
            $stmt->execute([
                ':conversacion_id' => $conversacionId,
                ':usuario_id' => $usuarioId,
                ':contenido' => $contenido,
            ]);
            $mensajeId = (int)$conexion->lastInsertId();
            $stmt = $conexion->prepare(
                'SELECT usuario_id FROM miembros_conversacion
                 WHERE conversacion_id = :conversacion_id AND usuario_id <> :usuario_id'
            );
            $stmt->execute([':conversacion_id' => $conversacionId, ':usuario_id' => $usuarioId]);
            $destinatarioId = $stmt->fetchColumn();
            if ($destinatarioId) {
                $stmt = $conexion->prepare(
                    'INSERT INTO recibos_lectura_mensajes (mensaje_id, destinatario_usuario_id)
                     VALUES (:mensaje_id, :destinatario_id)'
                );
                $stmt->execute([':mensaje_id' => $mensajeId, ':destinatario_id' => $destinatarioId]);
                crearNotificacion($conexion, (int)$destinatarioId, $usuarioId, 'mensaje', 'Te envió un mensaje.', 'mensaje_directo_id', $mensajeId);
            }
            respuestaJson(201, ['mensaje' => 'Mensaje enviado.', 'mensaje_id' => $mensajeId]);

        case 'solicitar_amistad':
            $otroUsuarioId = filter_var($entrada['otro_usuario_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$otroUsuarioId || $otroUsuarioId === $usuarioId) {
                respuestaJson(422, ['error' => 'La solicitud no es válida.']);
            }
            $menor = min($usuarioId, $otroUsuarioId);
            $mayor = max($usuarioId, $otroUsuarioId);
            $stmt = $conexion->prepare(
                "INSERT INTO amistades
                    (usuario_menor_id, usuario_mayor_id, solicitado_por_usuario_id, estado_amistad)
                 VALUES (:menor, :mayor, :solicitante, 'pendiente')"
            );
            $conexion->beginTransaction();
            try {
                $stmt->execute([':menor' => $menor, ':mayor' => $mayor, ':solicitante' => $usuarioId]);
            } catch (PDOException $e) {
                $conexion->rollBack();
                if ($e->getCode() === '23000') {
                    respuestaJson(409, ['error' => 'Ya existe una solicitud o amistad entre estas cuentas.']);
                }
                throw $e;
            }
            crearNotificacion($conexion, $otroUsuarioId, $usuarioId, 'amistad', 'Te envió una solicitud de amistad.');
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Solicitud de amistad enviada.']);

        case 'responder_amistad':
            $otroUsuarioId = filter_var($entrada['otro_usuario_id'] ?? null, FILTER_VALIDATE_INT);
            $aceptar = filter_var($entrada['aceptar'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$otroUsuarioId || $otroUsuarioId === $usuarioId) {
                respuestaJson(422, ['error' => 'La solicitud no es válida.']);
            }
            $menor = min($usuarioId, $otroUsuarioId);
            $mayor = max($usuarioId, $otroUsuarioId);
            $estado = $aceptar ? 'aceptada' : 'rechazada';
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                "UPDATE amistades SET estado_amistad = :estado, respondido_en = CURRENT_TIMESTAMP
                 WHERE usuario_menor_id = :menor AND usuario_mayor_id = :mayor
                   AND solicitado_por_usuario_id <> :usuario_id AND estado_amistad = 'pendiente'"
            );
            $stmt->execute([
                ':estado' => $estado,
                ':menor' => $menor,
                ':mayor' => $mayor,
                ':usuario_id' => $usuarioId,
            ]);
            if (!$stmt->rowCount()) {
                $conexion->rollBack();
                respuestaJson(404, ['error' => 'No se encontró una solicitud pendiente de esa cuenta.']);
            }
            if ($aceptar) {
                crearNotificacion($conexion, $otroUsuarioId, $usuarioId, 'amistad', 'Aceptó tu solicitud de amistad.');
            }
            $conexion->commit();
            respuestaJson(200, ['mensaje' => $aceptar ? 'Solicitud aceptada.' : 'Solicitud rechazada.']);

        case 'publicar_producto':
            $nombre = $entrada['nombre'] ?? '';
            $descripcion = $entrada['descripcion'] ?? '';
            $precio = filter_var($entrada['precio'] ?? null, FILTER_VALIDATE_FLOAT);
            $plataformaNombre = $entrada['plataforma'] ?? '';
            $cantidadStock = filter_var($entrada['cantidad_stock'] ?? 1, FILTER_VALIDATE_INT);
            if ($precio === false || $precio < 0) {
                respuestaJson(422, ['error' => 'El precio no es válido.']);
            }
            if (!$cantidadStock || $cantidadStock < 1 || $cantidadStock > 999) {
                respuestaJson(422, ['error' => 'La cantidad no es válida.']);
            }
            $stmt = $conexion->prepare('SELECT plataforma_id FROM plataformas WHERE nombre = :nombre AND esta_activa = 1');
            $stmt->execute([':nombre' => $plataformaNombre]);
            $plataformaId = $stmt->fetchColumn();
            if (!$plataformaId) {
                respuestaJson(422, ['error' => 'Selecciona una plataforma válida.']);
            }
            $imagen = subirImagen($_FILES['imagen'] ?? [], 'productos');
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                "INSERT INTO productos
                    (vendedor_usuario_id, plataforma_id, nombre, descripcion, precio, codigo_moneda, cantidad_stock)
                 VALUES (:usuario_id, :plataforma_id, :nombre, :descripcion, :precio, 'MXN', :cantidad_stock)"
            );
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':plataforma_id' => $plataformaId,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':precio' => $precio,
                ':cantidad_stock' => $cantidadStock,
            ]);
            $productoId = (int)$conexion->lastInsertId();
            if ($imagen !== null) {
                $stmt = $conexion->prepare(
                    'INSERT INTO imagenes_producto (producto_id, url_imagen) VALUES (:producto_id, :url)'
                );
                $stmt->execute([':producto_id' => $productoId, ':url' => $imagen]);
            }
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Artículo publicado.', 'producto_id' => $productoId]);

        case 'agregar_carrito':
            $productoId = filter_var($entrada['producto_id'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($entrada['cantidad'] ?? 1, FILTER_VALIDATE_INT);
            if (!$productoId || !$cantidad || $cantidad < 1 || $cantidad > 50) {
                respuestaJson(422, ['error' => 'El producto o la cantidad no son válidos.']);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                "SELECT precio, codigo_moneda, cantidad_stock, vendedor_usuario_id
                 FROM productos WHERE producto_id = :id AND estado_producto = 'disponible' FOR UPDATE"
            );
            $stmt->execute([':id' => $productoId]);
            $producto = $stmt->fetch();
            if (!$producto || (int)$producto['vendedor_usuario_id'] === $usuarioId) {
                $conexion->rollBack();
                respuestaJson(404, ['error' => 'No puedes agregar este artículo al carrito.']);
            }
            $stmt = $conexion->prepare(
                'INSERT INTO carritos (usuario_id) VALUES (:usuario_id)
                 ON DUPLICATE KEY UPDATE carrito_id = LAST_INSERT_ID(carrito_id)'
            );
            $stmt->execute([':usuario_id' => $usuarioId]);
            $stmt = $conexion->prepare('SELECT carrito_id FROM carritos WHERE usuario_id = :usuario_id');
            $stmt->execute([':usuario_id' => $usuarioId]);
            $carritoId = (int)$stmt->fetchColumn();
            $stmt = $conexion->prepare(
                'INSERT INTO articulos_carrito
                    (carrito_id, producto_id, cantidad, precio_unitario, codigo_moneda)
                 VALUES (:carrito_id, :producto_id, :cantidad, :precio, :moneda)
                 ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)'
            );
            $stmt->execute([
                ':carrito_id' => $carritoId,
                ':producto_id' => $productoId,
                ':cantidad' => $cantidad,
                ':precio' => $producto['precio'],
                ':moneda' => $producto['codigo_moneda'],
            ]);
            $stmt = $conexion->prepare(
                'SELECT cantidad FROM articulos_carrito WHERE carrito_id = :carrito_id AND producto_id = :producto_id'
            );
            $stmt->execute([':carrito_id' => $carritoId, ':producto_id' => $productoId]);
            if ((int)$stmt->fetchColumn() > (int)$producto['cantidad_stock']) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'La cantidad solicitada supera el inventario disponible.']);
            }
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Artículo agregado al carrito.']);

        case 'quitar_carrito':
            $productoId = filter_var($entrada['producto_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productoId) {
                respuestaJson(422, ['error' => 'El artículo no es válido.']);
            }
            $stmt = $conexion->prepare(
                'DELETE a FROM articulos_carrito a
                 JOIN carritos c ON c.carrito_id = a.carrito_id
                 WHERE c.usuario_id = :usuario_id AND a.producto_id = :producto_id'
            );
            $stmt->execute([':usuario_id' => $usuarioId, ':producto_id' => $productoId]);
            respuestaJson(200, ['mensaje' => 'Artículo eliminado del carrito.']);

        case 'comprar':
            $productoId = filter_var($entrada['producto_id'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($entrada['cantidad'] ?? 1, FILTER_VALIDATE_INT);
            if (!$productoId || !$cantidad || $cantidad < 1 || $cantidad > 50) {
                respuestaJson(422, ['error' => 'El producto o la cantidad no son válidos.']);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare(
                "SELECT producto_id, vendedor_usuario_id, nombre, precio, codigo_moneda, cantidad_stock
                 FROM productos WHERE producto_id = :id AND estado_producto = 'disponible' FOR UPDATE"
            );
            $stmt->execute([':id' => $productoId]);
            $producto = $stmt->fetch();
            if (!$producto || (int)$producto['vendedor_usuario_id'] === $usuarioId || $cantidad > (int)$producto['cantidad_stock']) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'El artículo ya no está disponible en esa cantidad.']);
            }
            $subtotal = round((float)$producto['precio'] * $cantidad, 2);
            $stmt = $conexion->prepare(
                "INSERT INTO pedidos (comprador_usuario_id, subtotal, costo_envio, total, codigo_moneda)
                 VALUES (:usuario_id, :subtotal, 0, :total, :moneda)"
            );
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':subtotal' => $subtotal,
                ':total' => $subtotal,
                ':moneda' => $producto['codigo_moneda'],
            ]);
            $pedidoId = (int)$conexion->lastInsertId();
            $stmt = $conexion->prepare(
                'INSERT INTO articulos_pedido (pedido_id, producto_id, copia_nombre_producto, cantidad, precio_unitario)
                 VALUES (:pedido_id, :producto_id, :nombre, :cantidad, :precio)'
            );
            $stmt->execute([
                ':pedido_id' => $pedidoId,
                ':producto_id' => $productoId,
                ':nombre' => $producto['nombre'],
                ':cantidad' => $cantidad,
                ':precio' => $producto['precio'],
            ]);
            $restante = (int)$producto['cantidad_stock'] - $cantidad;
            $stmt = $conexion->prepare(
                "UPDATE productos SET cantidad_stock = :cantidad,
                    estado_producto = IF(:restante = 0, 'vendido', 'disponible')
                 WHERE producto_id = :producto_id"
            );
            $stmt->execute([
                ':cantidad' => $restante,
                ':restante' => $restante,
                ':producto_id' => $productoId,
            ]);
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Comprado con éxito.', 'pedido_id' => $pedidoId, 'cantidad_stock' => $restante]);

        case 'finalizar_compra':
            $conexion->beginTransaction();
            $stmt = $conexion->prepare('SELECT carrito_id FROM carritos WHERE usuario_id = :usuario_id FOR UPDATE');
            $stmt->execute([':usuario_id' => $usuarioId]);
            $carritoId = $stmt->fetchColumn();
            if (!$carritoId) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'Tu carrito está vacío.']);
            }
            $stmt = $conexion->prepare(
                "SELECT p.producto_id, p.vendedor_usuario_id, p.nombre,
                        a.precio_unitario AS precio, a.codigo_moneda, p.cantidad_stock,
                        p.estado_producto, a.cantidad
                 FROM articulos_carrito a JOIN productos p ON p.producto_id = a.producto_id
                 WHERE a.carrito_id = :carrito_id FOR UPDATE"
            );
            $stmt->execute([':carrito_id' => $carritoId]);
            $articulos = $stmt->fetchAll();
            if (!$articulos) {
                $conexion->rollBack();
                respuestaJson(409, ['error' => 'Tu carrito no contiene artículos disponibles.']);
            }
            foreach ($articulos as $articulo) {
                if (
                    (int)$articulo['vendedor_usuario_id'] === $usuarioId ||
                    $articulo['estado_producto'] !== 'disponible' ||
                    (int)$articulo['cantidad'] > (int)$articulo['cantidad_stock'] ||
                    $articulo['codigo_moneda'] !== $articulos[0]['codigo_moneda']
                ) {
                    $conexion->rollBack();
                    respuestaJson(409, ['error' => 'Un artículo no está disponible en la cantidad o moneda indicada.']);
                }
            }
            $subtotal = 0.0;
            foreach ($articulos as $articulo) {
                $subtotal += (float)$articulo['precio'] * (int)$articulo['cantidad'];
            }
            $subtotal = round($subtotal, 2);
            $stmt = $conexion->prepare(
                "INSERT INTO pedidos (comprador_usuario_id, subtotal, costo_envio, total, codigo_moneda)
                 VALUES (:usuario_id, :subtotal, 0, :total, :moneda)"
            );
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':subtotal' => $subtotal,
                ':total' => $subtotal,
                ':moneda' => $articulos[0]['codigo_moneda'],
            ]);
            $pedidoId = (int)$conexion->lastInsertId();
            $insertarArticulo = $conexion->prepare(
                'INSERT INTO articulos_pedido (pedido_id, producto_id, copia_nombre_producto, cantidad, precio_unitario)
                 VALUES (:pedido_id, :producto_id, :nombre, :cantidad, :precio)'
            );
            $actualizarInventario = $conexion->prepare(
                "UPDATE productos SET cantidad_stock = :restante,
                    estado_producto = IF(:estado_restante = 0, 'vendido', 'disponible')
                 WHERE producto_id = :producto_id"
            );
            foreach ($articulos as $articulo) {
                $insertarArticulo->execute([
                    ':pedido_id' => $pedidoId,
                    ':producto_id' => $articulo['producto_id'],
                    ':nombre' => $articulo['nombre'],
                    ':cantidad' => $articulo['cantidad'],
                    ':precio' => $articulo['precio'],
                ]);
                $restante = (int)$articulo['cantidad_stock'] - (int)$articulo['cantidad'];
                $actualizarInventario->execute([
                    ':restante' => $restante,
                    ':estado_restante' => $restante,
                    ':producto_id' => $articulo['producto_id'],
                ]);
            }
            $conexion->prepare('DELETE FROM articulos_carrito WHERE carrito_id = :carrito_id')
                ->execute([':carrito_id' => $carritoId]);
            $conexion->commit();
            respuestaJson(201, ['mensaje' => 'Comprado con éxito.', 'pedido_id' => $pedidoId]);

        case 'eliminar_usuario':
            if (!$esAdministrador) {
                respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
            }
            $usuarioEliminarId = filter_var($entrada['usuario_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$usuarioEliminarId || $usuarioEliminarId === $usuarioId) {
                respuestaJson(422, ['error' => 'Selecciona una cuenta válida.']);
            }
            $stmt = $conexion->prepare("UPDATE usuarios SET estado_cuenta = 'eliminado' WHERE usuario_id = :id");
            $stmt->execute([':id' => $usuarioEliminarId]);
            respuestaJson(200, ['mensaje' => 'Cuenta eliminada.']);

        case 'agregar_juego':
        case 'actualizar_juego':
            if (!$esAdministrador) {
                respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
            }
            $nombre = trim($entrada['nombre'] ?? '');
            $juegoId = filter_var($entrada['juego_id'] ?? null, FILTER_VALIDATE_INT);
            if ($nombre === '') {
                respuestaJson(422, ['error' => 'El nombre del juego es obligatorio.']);
            }
            $imagen = subirImagen($_FILES['imagen'] ?? [], 'juegos');
            if ($accion === 'agregar_juego') {
                if ($imagen === null) {
                    respuestaJson(422, ['error' => 'La imagen del juego es obligatoria.']);
                }
                $stmt = $conexion->prepare('INSERT INTO juegos (nombre, url_imagen) VALUES (:nombre, :imagen)');
                $stmt->execute([':nombre' => $nombre, ':imagen' => $imagen]);
            } else {
                if (!$juegoId) {
                    respuestaJson(422, ['error' => 'Selecciona un juego válido.']);
                }
                $sql = 'UPDATE juegos SET nombre = :nombre';
                $params = [':nombre' => $nombre, ':id' => $juegoId];
                if ($imagen !== null) {
                    $sql .= ', url_imagen = :imagen';
                    $params[':imagen'] = $imagen;
                }
                $sql .= ' WHERE juego_id = :id';
                $stmt = $conexion->prepare($sql);
                $stmt->execute($params);
            }
            respuestaJson(200, ['mensaje' => 'Catálogo de juegos actualizado.']);

        case 'eliminar_juego':
            if (!$esAdministrador) {
                respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
            }
            $juegoId = filter_var($entrada['juego_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$juegoId) {
                respuestaJson(422, ['error' => 'Selecciona un juego válido.']);
            }
            $conexion->beginTransaction();
            $conexion->prepare('DELETE FROM juegos_favoritos_usuario WHERE juego_id = :id')->execute([':id' => $juegoId]);
            $conexion->prepare('UPDATE juegos SET esta_activo = 0 WHERE juego_id = :id')->execute([':id' => $juegoId]);
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Juego eliminado del catálogo y de todos los perfiles.']);

        case 'agregar_plataforma':
        case 'actualizar_plataforma':
            if (!$esAdministrador) {
                respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
            }
            $nombre = trim($entrada['nombre'] ?? '');
            $plataformaId = filter_var($entrada['plataforma_id'] ?? null, FILTER_VALIDATE_INT);
            if ($nombre === '') {
                respuestaJson(422, ['error' => 'El nombre de la consola es obligatorio.']);
            }
            $imagen = subirImagen($_FILES['imagen'] ?? [], 'plataformas');
            if ($accion === 'agregar_plataforma') {
                if ($imagen === null) {
                    respuestaJson(422, ['error' => 'La imagen de la consola es obligatoria.']);
                }
                $stmt = $conexion->prepare('INSERT INTO plataformas (nombre, url_imagen) VALUES (:nombre, :imagen)');
                $stmt->execute([':nombre' => $nombre, ':imagen' => $imagen]);
            } else {
                if (!$plataformaId) {
                    respuestaJson(422, ['error' => 'Selecciona una consola válida.']);
                }
                $sql = 'UPDATE plataformas SET nombre = :nombre';
                $params = [':nombre' => $nombre, ':id' => $plataformaId];
                if ($imagen !== null) {
                    $sql .= ', url_imagen = :imagen';
                    $params[':imagen'] = $imagen;
                }
                $sql .= ' WHERE plataforma_id = :id';
                $stmt = $conexion->prepare($sql);
                $stmt->execute($params);
            }
            respuestaJson(200, ['mensaje' => 'Catálogo de consolas actualizado.']);

        case 'eliminar_plataforma':
            if (!$esAdministrador) {
                respuestaJson(403, ['error' => 'No tienes permiso de administrador.']);
            }
            $plataformaId = filter_var($entrada['plataforma_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$plataformaId) {
                respuestaJson(422, ['error' => 'Selecciona una consola válida.']);
            }
            $conexion->beginTransaction();
            $conexion->prepare('DELETE FROM plataformas_favoritas_usuario WHERE plataforma_id = :id')->execute([':id' => $plataformaId]);
            $conexion->prepare('UPDATE plataformas SET esta_activa = 0 WHERE plataforma_id = :id')->execute([':id' => $plataformaId]);
            $conexion->commit();
            respuestaJson(200, ['mensaje' => 'Consola eliminada del catálogo y de todos los perfiles.']);

        case 'marcar_notificaciones':
            if (isset($entrada['ids'])) {
                $ids = $entrada['ids'];
                if (!is_array($ids) || count($ids) > 50 || array_filter($ids, static fn($id) => !filter_var($id, FILTER_VALIDATE_INT) || (int)$id < 1)) {
                    respuestaJson(422, ['error' => 'Las notificaciones no son válidas.']);
                }
                if ($ids) {
                    $marcadores = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $conexion->prepare(
                        "UPDATE notificaciones SET leido_en = CURRENT_TIMESTAMP
                         WHERE destinatario_usuario_id = ? AND leido_en IS NULL AND notificacion_id IN ($marcadores)"
                    );
                    $stmt->execute([$usuarioId, ...$ids]);
                }
            } else {
                $stmt = $conexion->prepare(
                    'UPDATE notificaciones SET leido_en = CURRENT_TIMESTAMP
                     WHERE destinatario_usuario_id = :usuario_id AND leido_en IS NULL'
                );
                $stmt->execute([':usuario_id' => $usuarioId]);
            }
            respuestaJson(200, ['mensaje' => 'Notificaciones marcadas como leídas.']);

        default:
            respuestaJson(404, ['error' => 'La acción solicitada no existe.']);
    }
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Gamerbook API error (' . $accion . '): ' . $e->getMessage());
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        respuestaJson(409, ['error' => 'Ese dato ya está registrado o no es válido.']);
    }
    respuestaJson(500, ['error' => 'Ocurrió un error al procesar la solicitud. Inténtalo más tarde.']);
}
