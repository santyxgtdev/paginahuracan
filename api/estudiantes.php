<?php
declare(strict_types=1);

/**
 * RF-01 — Registrar estudiantes (datos personales, acudiente y categoría).
 * POST /api/estudiantes.php  (JSON)
 *
 * {
 *   "estudiante": { "nombres", "apellidos", "tipo_documento", "numero_documento",
 *                   "fecha_nacimiento": "YYYY-MM-DD", "categoria_id": (opcional) },
 *   "acudiente":  { "nombres", "apellidos", "tipo_documento", "numero_documento",
 *                   "telefono", "correo", "parentesco" },
 *   "autorizacion_datos": true
 * }
 */

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// TEMPORAL: todavía no hay login, así que solo se permite desde tu propio PC.
// Quitar este bloque cuando exista el módulo de login y los roles.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    responder(403, ['error' => 'Acceso no autorizado.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responder(405, ['error' => 'Método no permitido.']);
}

$crudo = file_get_contents('php://input');
if ($crudo === false || strlen($crudo) > 20000) {
    responder(400, ['error' => 'Solicitud inválida.']);
}
$datos = json_decode($crudo, true);
if (!is_array($datos) || !is_array($datos['estudiante'] ?? null) || !is_array($datos['acudiente'] ?? null)) {
    responder(400, ['error' => 'Se esperaba un JSON con "estudiante" y "acudiente".']);
}

$est = $datos['estudiante'];
$acu = $datos['acudiente'];

function texto(array $a, string $k): string
{
    return trim((string)($a[$k] ?? ''));
}

// ---------- Validación ----------
$errores = [];
$patronNombre = '/^\p{L}[\p{L}\s\'.-]{1,79}$/u';

foreach (['nombres', 'apellidos'] as $campo) {
    if (!preg_match($patronNombre, texto($est, $campo))) {
        $errores["estudiante.$campo"] = 'Obligatorio, solo letras (2 a 80 caracteres).';
    }
    if (!preg_match($patronNombre, texto($acu, $campo))) {
        $errores["acudiente.$campo"] = 'Obligatorio, solo letras (2 a 80 caracteres).';
    }
}

if (!in_array(texto($est, 'tipo_documento'), ['RC', 'TI', 'CC', 'CE', 'PAS'], true)) {
    $errores['estudiante.tipo_documento'] = 'Debe ser RC, TI, CC, CE o PAS.';
}
if (!in_array(texto($acu, 'tipo_documento'), ['CC', 'CE', 'PAS'], true)) {
    $errores['acudiente.tipo_documento'] = 'Debe ser CC, CE o PAS.';
}
if (!preg_match('/^[A-Za-z0-9]{5,20}$/', texto($est, 'numero_documento'))) {
    $errores['estudiante.numero_documento'] = 'Entre 5 y 20 letras o números, sin espacios ni puntos.';
}
if (!preg_match('/^[A-Za-z0-9]{5,20}$/', texto($acu, 'numero_documento'))) {
    $errores['acudiente.numero_documento'] = 'Entre 5 y 20 letras o números, sin espacios ni puntos.';
}
if (!preg_match('/^\+?[0-9 ]{7,20}$/', texto($acu, 'telefono'))) {
    $errores['acudiente.telefono'] = 'Teléfono inválido (7 a 20 dígitos).';
}
$correo = texto($acu, 'correo');
if (strlen($correo) > 120 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $errores['acudiente.correo'] = 'Correo electrónico inválido.';
}
if (!preg_match('/^\p{L}[\p{L}\s]{1,29}$/u', texto($acu, 'parentesco'))) {
    $errores['acudiente.parentesco'] = 'Obligatorio (por ejemplo: Madre, Padre, Tío).';
}

$fecha = texto($est, 'fecha_nacimiento');
$dt = DateTime::createFromFormat('!Y-m-d', $fecha);
if (!$dt || $dt->format('Y-m-d') !== $fecha) {
    $errores['estudiante.fecha_nacimiento'] = 'Fecha inválida (formato AAAA-MM-DD).';
} elseif ($dt > new DateTime('today') || (int)$dt->format('Y') < 2000) {
    $errores['estudiante.fecha_nacimiento'] = 'La fecha de nacimiento no es razonable.';
}

if (($datos['autorizacion_datos'] ?? false) !== true) {
    $errores['autorizacion_datos'] = 'El acudiente debe autorizar el tratamiento de datos.';
}

$categoriaManual = null;
if (isset($est['categoria_id']) && $est['categoria_id'] !== '' && $est['categoria_id'] !== null) {
    $categoriaManual = filter_var($est['categoria_id'], FILTER_VALIDATE_INT);
    if ($categoriaManual === false || $categoriaManual < 1) {
        $errores['estudiante.categoria_id'] = 'Categoría inválida.';
    }
}

if ($errores) {
    responder(422, ['error' => 'Hay datos por corregir.', 'campos' => $errores]);
}

// ---------- Guardado ----------
$pdo = null;
try {
    $pdo = conectar();

    // Categoría: la elegida manualmente o la sugerida por año de nacimiento.
    if ($categoriaManual !== null) {
        $q = $pdo->prepare('SELECT id, nombre FROM categorias WHERE id = :id');
        $q->execute([':id' => $categoriaManual]);
    } else {
        $q = $pdo->prepare(
            'SELECT id, nombre FROM categorias WHERE :anio BETWEEN anio_nac_min AND anio_nac_max LIMIT 1'
        );
        $q->execute([':anio' => (int)$dt->format('Y')]);
    }
    $categoria = $q->fetch();
    if (!$categoria) {
        responder(422, [
            'error' => 'No hay categoría para ese año de nacimiento.',
            'campos' => ['estudiante.fecha_nacimiento' => 'Elige una categoría manualmente o revisa la fecha.'],
        ]);
    }

    $pdo->beginTransaction();

    // Acudiente: si ya existe por documento (hermanos), se reutiliza sin modificar sus datos.
    $q = $pdo->prepare('SELECT id FROM acudientes WHERE numero_documento = :doc');
    $q->execute([':doc' => texto($acu, 'numero_documento')]);
    $acudienteId = $q->fetchColumn();
    $acudienteExistente = $acudienteId !== false;

    if (!$acudienteExistente) {
        $q = $pdo->prepare(
            'INSERT INTO acudientes (nombres, apellidos, tipo_documento, numero_documento, telefono, correo)
             VALUES (:nombres, :apellidos, :tipo, :doc, :tel, :correo)'
        );
        $q->execute([
            ':nombres'   => texto($acu, 'nombres'),
            ':apellidos' => texto($acu, 'apellidos'),
            ':tipo'      => texto($acu, 'tipo_documento'),
            ':doc'       => texto($acu, 'numero_documento'),
            ':tel'       => texto($acu, 'telefono'),
            ':correo'    => $correo,
        ]);
        $acudienteId = (int)$pdo->lastInsertId();
    }

    $q = $pdo->prepare(
        'INSERT INTO estudiantes
           (nombres, apellidos, tipo_documento, numero_documento, fecha_nacimiento, categoria_id, autorizacion_datos_en)
         VALUES (:nombres, :apellidos, :tipo, :doc, :nac, :cat, NOW())'
    );
    $q->execute([
        ':nombres'   => texto($est, 'nombres'),
        ':apellidos' => texto($est, 'apellidos'),
        ':tipo'      => texto($est, 'tipo_documento'),
        ':doc'       => texto($est, 'numero_documento'),
        ':nac'       => $fecha,
        ':cat'       => (int)$categoria['id'],
    ]);
    $estudianteId = (int)$pdo->lastInsertId();

    $q = $pdo->prepare(
        'INSERT INTO estudiante_acudiente (estudiante_id, acudiente_id, parentesco, es_principal)
         VALUES (:e, :a, :p, 1)'
    );
    $q->execute([':e' => $estudianteId, ':a' => (int)$acudienteId, ':p' => texto($acu, 'parentesco')]);

    $pdo->commit();

    responder(201, [
        'ok'                  => true,
        'estudiante_id'       => $estudianteId,
        'acudiente_id'        => (int)$acudienteId,
        'acudiente_existente' => $acudienteExistente,
        'categoria'           => ['id' => (int)$categoria['id'], 'nombre' => $categoria['nombre']],
    ]);
} catch (PDOException $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 1062 = clave duplicada (documento ya registrado)
    if (($e->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Ya existe un registro con ese número de documento.']);
    }
    error_log('RF-01 estudiantes.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo guardar. Intenta de nuevo.']);
}