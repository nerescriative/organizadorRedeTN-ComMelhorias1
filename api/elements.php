<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
Auth::check();
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'];
$type   = $_GET['type'] ?? $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── SISTEMA DE EXCLUSÃO DEFINITIVA (MÉTODO DELETE OU PARÂMETRO) ──────────────
if ($method == 'DELETE' || strpos($type, 'delete_') === 0 || (isset($_GET['action']) && $_GET['action'] == 'delete')) {
    try {
        $id_alvo = (int)($body['id'] ?? $_GET['id'] ?? 0);
        $acao_delete = $type ?: $_GET['type'] ?: '';

        if (!$id_alvo) {
            echo json_encode(['success' => false, 'error' => 'ID do elemento não identificado.']);
            exit;
        }

        switch ($acao_delete) {
            case 'delete_cabo':
                $db->query("DELETE FROM fusoes WHERE cabo_entrada_id = ? OR cabo_saida_id = ?", [$id_alvo, $id_alvo]);
                $db->query("DELETE FROM cabo_pontos WHERE cabo_id = ?", [$id_alvo]);
                $db->query("DELETE FROM cabo_reservas WHERE cabo_id = ?", [$id_alvo]);
                $db->query("DELETE FROM cabos WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Cabo removido com sucesso!']);
                exit;

            case 'delete_reserva':
                $db->query("DELETE FROM cabo_reservas WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Reserva técnica removida!']);
                exit;

            case 'delete_poste':
                $db->query("UPDATE cabo_pontos SET elemento_id = NULL, elemento_tipo = NULL WHERE elemento_tipo = 'poste' AND elemento_id = ?", [$id_alvo]);
                $db->query("DELETE FROM postes WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Poste excluído com sucesso!']);
                exit;

            case 'delete_ceo':
                $db->query("DELETE FROM fusoes WHERE ceo_id = ?", [$id_alvo]);
                $db->query("DELETE FROM elemento_splitters WHERE elem_tipo = 'ceo' AND elem_id = ?", [$id_alvo]);
                $db->query("UPDATE cabo_pontos SET elemento_id = NULL, elemento_tipo = NULL WHERE elemento_tipo = 'ceo' AND elemento_id = ?", [$id_alvo]);
                $db->query("DELETE FROM ceos WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Caixa de Emenda (CEO) excluída!']);
                exit;

            case 'delete_cto':
                $db->query("DELETE FROM fusoes WHERE cto_id = ?", [$id_alvo]);
                $db->query("DELETE FROM elemento_splitters WHERE elem_tipo = 'cto' AND elem_id = ?", [$id_alvo]);
                $db->query("UPDATE cabo_pontos SET elemento_id = NULL, elemento_tipo = NULL WHERE elemento_tipo = 'cto' AND elemento_id = ?", [$id_alvo]);
                $db->query("UPDATE clientes SET cto_id = NULL, porta_cto = NULL WHERE cto_id = ?", [$id_alvo]);
                $db->query("DELETE FROM ctos WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Caixa de Atendimento (CTO) excluída!']);
                exit;

            case 'delete_cliente':
                $db->query("DELETE FROM clientes WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Assinante / ONU removido com sucesso!']);
                exit;

            case 'delete_splitter':
                $db->query("DELETE FROM fusoes WHERE spl_ent_id = ? OR spl_sai_id = ?", [$id_alvo, $id_alvo]);
                $db->query("DELETE FROM elemento_splitters WHERE splitter_id = ?", [$id_alvo]);
                $db->query("DELETE FROM splitters WHERE id = ?", [$id_alvo]);
                echo json_encode(['success' => true, 'message' => 'Splitter óptico removido do estoque e caixas!']);
                exit;

            default:
                echo json_encode(['success' => true, 'message' => 'Operação concluída via interceptador.']);
                exit;
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── PROCESSAMENTO POST (INSERÇÕES E MODIFICAÇÕES COMPLETO) ───────────────────
if ($method == 'POST') {
    try {
        switch ($type) {
            case 'rack': {
                $id = $db->insert('racks', ['codigo' => trim($body['codigo'] ?? ''), 'nome' => trim($body['nome'] ?? ''), 'lat' => (float)$body['lat'], 'lng' => (float)$body['lng'], 'localizacao' => trim($body['localizacao'] ?? ''), 'status' => 'ativo']);
                echo json_encode(['success' => true, 'data' => $db->fetch('SELECT * FROM racks WHERE id=?', [$id])]); exit;
            }
            case 'cabo':
            case 'update_cabo': {
                $cabo_id = (int)($body['cabo_id'] ?? $body['id'] ?? $body['id_cabo'] ?? $_GET['id'] ?? 0);
                $codigo  = trim($body['codigo'] ?? '');
                $pontos  = $body['pontos'] ?? $body['points'] ?? $body['coordinates'] ?? [];
                if ($cabo_id) { $db->query("DELETE FROM cabo_pontos WHERE cabo_id = ?", [$cabo_id]); } 
                else { $cabo_id = $db->insert('cabos', ['codigo' => $codigo, 'tipo' => trim($body['tipo'] ?? 'monomodo'), 'num_fibras' => (int)($body['num_fibras'] ?? 12), 'status' => trim($body['status'] ?? 'ativo'), 'comprimento_real' => $body['comprimento_real'] ? (float)$body['comprimento_real'] : null, 'observacoes' => trim($body['observacoes'] ?? ''), 'comprimento_m' => 0]); }
                if (is_array($pontos)) {
                    foreach ($pontos as $index => $pt) {
                        $lat_p = (float)($pt['lat'] ?? $pt['latLng']['lat'] ?? $pt ?? 0); $lng_p = (float)($pt['lng'] ?? $pt['latLng']['lng'] ?? $pt ?? 0);
                        if ($lat_p && $lng_p) { $db->insert('cabo_pontos', ['cabo_id' => $cabo_id, 'lat' => $lat_p, 'lng' => $lng_p, 'ordem' => $index + 1, 'elemento_tipo' => trim($pt['et'] ?? $pt['elemento_tipo'] ?? $pt['type'] ?? ''), 'elemento_id' => ($pt['eid'] ?? $pt['elemento_id'] ?? $pt['id']) ? (int)($pt['eid'] ?? $pt['elemento_id'] ?? $pt['id']) : null, 'sequencia' => $index + 1]); }
                    }
                }
                echo json_encode(['success' => true, 'data' => $db->fetch('SELECT * FROM cabos WHERE id=?', [$cabo_id])]); exit;
            }
            case 'add_reserva':
            case 'reserva': {
                $id = $db->insert('cabo_reservas', ['cabo_id' => (int)$body['cabo_id'], 'metros' => (int)$body['metros'], 'lat' => (float)$body['lat'], 'lng' => (float)$body['lng'], 'descricao' => 'Reserva técnica']);
                echo json_encode(['success' => true, 'data' => $db->fetch('SELECT * FROM cabo_reservas WHERE id=?', [$id])]); exit;
            }
            case 'poste':
            case 'ceo':
            case 'cto':
            case 'cliente': {
                $tabela = $type . 's'; if($type == 'ceo') $tabela = 'ceos';
                $id = $db->insert($tabela, ['codigo' => $body['codigo'] ?? uniqid(), 'nome' => $body['nome'] ?? null, 'lat' => (float)$body['lat'], 'lng' => (float)$body['lng'], 'status' => 'ativo']);
                echo json_encode(['success' => true, 'data' => $db->fetch("SELECT * FROM $tabela WHERE id=?", [$id])]); exit;
            }
            default:
                echo json_encode(['success' => true, 'message' => 'Operação concluída.']); exit;
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }
}
echo json_encode(['success' => true, 'message' => 'API Operacional.']);
