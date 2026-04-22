<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
Auth::check();

header('Content-Type: application/json; charset=utf-8');
$db    = Database::getInstance();
$oltId = (int)($_GET['olt_id'] ?? 0);

if (!$oltId) {
    echo json_encode(['success'=>false,'error'=>'olt_id obrigatório']);
    exit;
}

$pons = $db->fetchAll(
    "SELECT id, slot, numero_pon, descricao, potencia_dbm
     FROM olt_pons WHERE olt_id=?
     ORDER BY slot ASC, numero_pon ASC",
    [$oltId]
);

echo json_encode(['success'=>true,'pons'=>$pons]);
