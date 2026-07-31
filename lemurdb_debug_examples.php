<?php

/**
 * LemurDB — Debug Examples (CRUD completo)
 *
 * Inspecciona la estructura SQL y parámetros de SELECT,
 * INSERT, UPDATE y DELETE sin ejecutar contra la base de datos.
 *
 * Uso: php lemurdb_debug_examples.php
 */

require_once 'LemurDB.php';

// ─────────────────────────────────────────────────────────────────────────────
// CONEXIÓN (Singleton)
// ─────────────────────────────────────────────────────────────────────────────
$db = LemurDB::getInstance([
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'db'       => 'lemur_test',
    'username' => 'root',
    'password' => 'secret',
    'prefix'   => 'app_',
]);

// ─────────────────────────────────────────────────────────────────────────────
// HELPER — imprime SQL y parámetros sin ejecutar
// ─────────────────────────────────────────────────────────────────────────────
function debug(string $label, string $sql, array $params): void
{
    $sep = str_repeat('─', 64);
    echo "\n{$sep}\n";
    echo "📌 {$label}\n";
    echo "{$sep}\n";
    echo "  SQL    : {$sql}\n";
    echo "  PARAMS : " . json_encode($params) . "\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// ██████  SELECT
// ═════════════════════════════════════════════════════════════════════════════

$q = $db->query('users')->select();
debug('[SELECT] Todos los campos', $q->toSql(), $q->getParams());

$q = $db->query('users')->select(['id', 'name', 'email']);
debug('[SELECT] Columnas específicas', $q->toSql(), $q->getParams());

$q = $db->query('users')->select(['id', 'name'])->where(['status' => 'active']);
debug('[SELECT] WHERE simple', $q->toSql(), $q->getParams());

$q = $db->query('users')
    ->select(['id', 'name', 'role'])
    ->where(['status' => 'active', 'role' => 'admin']);
debug('[SELECT] WHERE múltiple AND', $q->toSql(), $q->getParams());

$q = $db->query('users')
    ->select(['id', 'name'])
    ->where(['status' => 'active'])
    ->orWhere(['status' => 'pending']);
debug('[SELECT] WHERE + orWhere', $q->toSql(), $q->getParams());

$q = $db->query('products')
    ->select(['id', 'name'])
    ->like('name', '%cable%')
    ->orLike('description', '%cable%');
debug('[SELECT] LIKE + orLike', $q->toSql(), $q->getParams());

$q = $db->query('orders')
    ->select(['id', 'total'])
    ->between('total', 100, 500)
    ->orBetween('total', 1000, 2000);
debug('[SELECT] BETWEEN + orBetween', $q->toSql(), $q->getParams());

$q = $db->query('orders')
    ->select(['id', 'total', 'created_at'])
    ->between('created_at', '2024-01-01', '2024-12-31')
    ->orderby('created_at DESC')
    ->limit(50, 0);
debug('[SELECT] BETWEEN fechas + ORDER BY + LIMIT', $q->toSql(), $q->getParams());

$q = $db->query('products')
    ->select(['id', 'name', 'price', 'stock'])
    ->where(['status' => 'active', 'category' => 'electronics'])
    ->like('name', '%wireless%')
    ->orLike('name', '%bluetooth%')
    ->between('price', 20, 300)
    ->orderby('price ASC')
    ->limit(25);
debug('[SELECT] Query compleja encadenada', $q->toSql(), $q->getParams());

// ═════════════════════════════════════════════════════════════════════════════
// ██████  INSERT
// ═════════════════════════════════════════════════════════════════════════════

// INSERT — fila única
$data = [
    'name'       => 'John Doe',
    'email'      => 'john@example.com',
    'status'     => 'active',
    'created_at' => '2024-06-01 10:00:00',
];
$q = $db->query('users');
debug('[INSERT] Fila única', $q->toInsertSql($data), array_values($data));

// INSERT — producto con precio y stock
$product = [
    'sku'      => 'USB-C-001',
    'name'     => 'USB-C Cable 2m',
    'price'    => 14.99,
    'stock'    => 200,
    'category' => 'electronics',
    'status'   => 'active',
];
$q = $db->query('products');
debug('[INSERT] Producto completo', $q->toInsertSql($product), array_values($product));

// insertBatch — múltiples filas (debug manual del SQL generado)
$rows = [
    ['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
    ['name' => 'Bob',   'email' => 'bob@example.com',   'status' => 'pending'],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active'],
];
$columns     = array_keys($rows[0]);
$columnList  = implode(', ', $columns);
$placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
$valueSets   = implode(', ', array_fill(0, count($rows), $placeholder));
$batchSql    = "INSERT INTO app_users ({$columnList}) VALUES {$valueSets}";
$batchParams = array_merge(...array_map('array_values', $rows));
debug('[INSERT] Batch — 3 filas', $batchSql, $batchParams);

// ═════════════════════════════════════════════════════════════════════════════
// ██████  UPDATE
// ═════════════════════════════════════════════════════════════════════════════

// UPDATE — por ID
$updateData = ['name' => 'Jane Doe', 'status' => 'inactive'];
$q = $db->query('users')->where(['id' => 5]);
debug(
    '[UPDATE] Por ID',
    $q->toUpdateSql($updateData),
    array_merge(array_values($updateData), $q->getParams())
);

// UPDATE — campo único, múltiples WHERE
$updateData = ['status' => 'expired'];
$q = $db->query('subscriptions')
    ->where(['user_id' => 42, 'plan' => 'monthly']);
debug(
    '[UPDATE] Campo único, múltiples WHERE',
    $q->toUpdateSql($updateData),
    array_merge(array_values($updateData), $q->getParams())
);

// UPDATE — precio de un producto por SKU
$updateData = ['price' => 19.99, 'updated_at' => '2024-06-15 12:00:00'];
$q = $db->query('products')->where(['sku' => 'USB-C-001']);
debug(
    '[UPDATE] Precio de producto por SKU',
    $q->toUpdateSql($updateData),
    array_merge(array_values($updateData), $q->getParams())
);

// UPDATE — múltiples campos, WHERE + orWhere
$updateData = ['notified' => 1];
$q = $db->query('users')
    ->where(['status' => 'active'])
    ->orWhere(['status' => 'pending']);
debug(
    '[UPDATE] WHERE + orWhere',
    $q->toUpdateSql($updateData),
    array_merge(array_values($updateData), $q->getParams())
);

// ═════════════════════════════════════════════════════════════════════════════
// ██████  DELETE
// ═════════════════════════════════════════════════════════════════════════════

// DELETE — por ID
$q = $db->query('users')->where(['id' => 10]);
debug('[DELETE] Por ID', $q->toDeleteSql(), $q->getParams());

// DELETE — múltiples condiciones AND
$q = $db->query('sessions')
    ->where(['user_id' => 42, 'active' => 0]);
debug('[DELETE] Múltiples condiciones AND', $q->toDeleteSql(), $q->getParams());

// DELETE — OR entre condiciones
$q = $db->query('notifications')
    ->where(['status' => 'read'])
    ->orWhere(['status' => 'archived']);
debug('[DELETE] WHERE + orWhere', $q->toDeleteSql(), $q->getParams());

// DELETE — logs expirados con BETWEEN en fecha
$q = $db->query('logs')
    ->where(['level' => 'debug'])
    ->between('created_at', '2023-01-01', '2023-12-31');
debug('[DELETE] Logs expirados con BETWEEN', $q->toDeleteSql(), $q->getParams());

// ═════════════════════════════════════════════════════════════════════════════
// ██████  GUARD — WHERE faltante en UPDATE / DELETE
// ═════════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('─', 64) . "\n";
echo "⚠️  GUARD — Protección contra UPDATE / DELETE sin WHERE\n";
echo str_repeat('─', 64) . "\n";

try {
    $db->query('users')->update(['status' => 'inactive']);
} catch (\RuntimeException $e) {
    echo "  UPDATE sin WHERE → " . $e->getMessage() . "\n";
}

try {
    $db->query('orders')->delete();
} catch (\RuntimeException $e) {
    echo "  DELETE sin WHERE → " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('─', 64) . "\n";
echo "✅ Debug completado — SELECT, INSERT, UPDATE, DELETE.\n";
echo str_repeat('─', 64) . "\n\n";
