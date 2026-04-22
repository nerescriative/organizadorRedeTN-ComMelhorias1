<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
Auth::check();

header('Content-Type: application/json; charset=utf-8');
$db   = Database::getInstance();
$tipo = $_GET['tipo'] ?? '';  // fibra | spl_porta
$id   = (int)($_GET['id']   ?? 0);

function jr(array $d): void { echo json_encode($d); exit; }

const FIBER_ATTN_R  = 0.00025;
const LOSS_EMENDA_R = 0.10;
const LOSS_PASS_R   = 0.00;
const PON_DEFAULT_R = 5.0;

function caboLenR($db, int $id): float {
    $r = $db->fetch("SELECT comprimento_m, comprimento_real FROM cabos WHERE id=?", [$id]);
    if (!$r) return 0.0;
    $real = $r['comprimento_real'];
    return ($real !== null && (float)$real > 0) ? (float)$real : (float)($r['comprimento_m'] ?? 0);
}

function caboInfoR($db, int $id): array {
    $r = $db->fetch("SELECT id, codigo FROM cabos WHERE id=?", [$id]);
    $len = caboLenR($db, $id);
    return [
        'codigo'       => $r ? $r['codigo'] : "Cabo #$id",
        'comprimento_m'=> $len,
        'perda_cabo'   => round($len * FIBER_ATTN_R, 4),
    ];
}

/**
 * Returns passante nodes for a cable+fiber (same-cable passantes only).
 */
function getPassanteNodes($db, int $caboId, int $fibraNum): array {
    $rows = $db->fetchAll(
        "SELECT f.perda_db,
                CASE WHEN f.ceo_id IS NOT NULL THEN 'CEO'
                     WHEN f.cto_id IS NOT NULL THEN 'CTO'
                     ELSE '' END AS elem_tipo,
                COALESCE(ce.codigo, ct.codigo, '') AS elem_cod
         FROM fusoes f
         LEFT JOIN ceos ce ON ce.id=f.ceo_id
         LEFT JOIN ctos ct ON ct.id=f.cto_id
         WHERE f.cabo_entrada_id=? AND f.fibra_entrada=? AND f.tipo='passante'
           AND f.cabo_entrada_id = f.cabo_saida_id
         ORDER BY f.id ASC",
        [$caboId, $fibraNum]
    );
    $nodes = [];
    foreach ($rows as $r) {
        $nodes[] = [
            't'        => 'splice',
            'tipo'     => 'passante',
            'elem_tipo'=> $r['elem_tipo'] ?? '',
            'elem_cod' => $r['elem_cod'] ?? '',
            'perda_db' => $r['perda_db'] !== null ? (float)$r['perda_db'] : LOSS_PASS_R,
        ];
    }
    return $nodes;
}

/**
 * Build route segment for splitter $splInstId output port $porta (BACKWARD trace).
 * Returns ['rota'=>array, 'sinal'=>float|null, 'aviso'=>string|null].
 * Sinal can be null when OLT not found — route is still built.
 */
function traceSplitterForRota($db, int $splInstId, string $porta, string $elemTipo, string $elemCod, int $depth): array {
    if ($depth > 40) return ['rota'=>[],'sinal'=>null,'aviso'=>'Rastreamento muito profundo'];

    $fin = $db->fetch(
        "SELECT cabo_entrada_id, fibra_entrada,
                spl_ent_id AS up_spl_id, spl_ent_porta AS up_spl_porta,
                perda_db AS perda_in
         FROM fusoes
         WHERE spl_sai_id=?
           AND (spl_sai_porta IS NULL OR spl_sai_porta NOT LIKE 'o%')
         LIMIT 1",
        [$splInstId]
    );
    if (!$fin) return ['rota'=>[],'sinal'=>null,'aviso'=>'Entrada do splitter sem conexão'];

    $spl = $db->fetch(
        "SELECT s.perda_insercao_db, s.codigo spl_cod, s.relacao FROM elemento_splitters es
         JOIN splitters s ON s.id=es.splitter_id WHERE es.id=? LIMIT 1",
        [$splInstId]
    );

    if (empty($elemTipo) || empty($elemCod)) {
        $boxRow = $db->fetch(
            "SELECT UPPER(es.elem_tipo) AS et, COALESCE(ce.codigo, ct.codigo) AS ec
             FROM elemento_splitters es
             LEFT JOIN ceos ce ON ce.id=es.elem_id AND es.elem_tipo='ceo'
             LEFT JOIN ctos ct ON ct.id=es.elem_id AND es.elem_tipo='cto'
             WHERE es.id=? LIMIT 1",
            [$splInstId]
        );
        if ($boxRow) {
            if (empty($elemTipo)) $elemTipo = $boxRow['et'] ?? '';
            if (empty($elemCod))  $elemCod  = $boxRow['ec'] ?? '';
        }
    }

    $outF     = $db->fetch("SELECT perda_db FROM fusoes WHERE spl_ent_id=? AND spl_ent_porta=? LIMIT 1", [$splInstId, $porta]);
    $splLoss  = null;
    if ($outF && $outF['perda_db'] !== null)           $splLoss = (float)$outF['perda_db'];
    elseif ($spl && $spl['perda_insercao_db'] !== null) $splLoss = (float)$spl['perda_insercao_db'];

    $connLoss = ($fin['perda_in'] !== null) ? (float)$fin['perda_in'] : LOSS_EMENDA_R;

    if (!empty($fin['cabo_entrada_id'])) {
        $up = traceRota($db, (int)$fin['cabo_entrada_id'], (int)$fin['fibra_entrada'], $depth+1);
        $sigPreSpl = $up['sinal'] !== null ? $up['sinal'] - $connLoss : null;
        $upRota  = $up['rota'];
        $upAviso = $up['aviso'];
    } elseif (!empty($fin['up_spl_id'])) {
        $up = traceSplitterForRota($db, (int)$fin['up_spl_id'], $fin['up_spl_porta'] ?? 'o0', $elemTipo, $elemCod, $depth+1);
        $sigPreSpl = $up['sinal'] !== null ? $up['sinal'] - $connLoss : null;
        $upRota  = $up['rota'];
        $upAviso = $up['aviso'];
    } else {
        return ['rota'=>[],'sinal'=>null,'aviso'=>'Entrada do splitter sem conexão'];
    }

    $sigPostSpl = ($sigPreSpl !== null && $splLoss !== null) ? $sigPreSpl - $splLoss
               : ($sigPreSpl !== null ? $sigPreSpl : null);

    $splNode = ['t'=>'splitter','codigo'=>($spl['spl_cod']??'SPL'),'relacao'=>($spl['relacao']??'?'),
                'perda_emenda'=>$connLoss,'perda_db'=>$splLoss,'porta'=>$porta,
                'elem_tipo'=>$elemTipo,'elem_cod'=>$elemCod];
    $aviso = $splLoss === null ? 'Splitter sem perda configurada' : $upAviso;

    return ['rota'=>array_merge($upRota, [$splNode]), 'sinal'=>$sigPostSpl !== null ? round($sigPostSpl,3) : null, 'aviso'=>$aviso];
}

/**
 * Trace BACKWARD from cable $caboId / fiber $fibraNum toward OLT.
 * Returns ['rota'=>array, 'sinal'=>float|null, 'aviso'=>string|null].
 * When OLT not found: sinal=null but rota still contains all physical nodes found.
 */
function traceRota($db, int $caboId, int $fibraNum, int $depth = 0, int $skipFusaoId = 0): array {
    if ($depth > 40) return ['rota'=>[],'sinal'=>null,'aviso'=>'Rastreamento muito profundo'];

    $ci         = caboInfoR($db, $caboId);
    $caboCodigo = $ci['codigo'];
    $caboLen    = $ci['comprimento_m'];
    $perdaCabo  = $ci['perda_cabo'];

    // ── Case A: cable comes directly from OLT rack ────────────────────────────
    $rac = $db->fetch(
        "SELECT op.potencia_dbm, o.codigo olt_cod, o.nome olt_nome,
                CONCAT('PON ',op.slot,'/',op.numero_pon) pon_id
         FROM rack_conexoes rb
         JOIN rack_conexoes ra ON ra.dio_id=rb.dio_id AND ra.dio_porta=rb.dio_porta AND ra.lado='A'
         LEFT JOIN olt_pons op ON op.id=ra.olt_pon_id
         LEFT JOIN olts o ON o.id=op.olt_id
         WHERE rb.cabo_id=? AND rb.fibra_num=? AND rb.lado='B' LIMIT 1",
        [$caboId, $fibraNum]
    );
    if ($rac) {
        $potencia      = (float)($rac['potencia_dbm'] ?? PON_DEFAULT_R);
        $sinalFim      = $potencia - $caboLen * FIBER_ATTN_R;
        $passanteNodes = getPassanteNodes($db, $caboId, $fibraNum);
        $rota = array_merge(
            [
                ['t'=>'olt', 'nome'=>($rac['olt_nome']??$rac['olt_cod']??'OLT'), 'pon'=>($rac['pon_id']??'PON'), 'potencia_dbm'=>$potencia],
                ['t'=>'cabo', 'id'=>$caboId, 'codigo'=>$caboCodigo, 'comprimento_m'=>$caboLen, 'fibra_num'=>$fibraNum, 'perda_cabo'=>$perdaCabo],
            ],
            $passanteNodes
        );
        return ['rota'=>$rota, 'sinal'=>round($sinalFim,3), 'aviso'=>null];
    }

    // ── Case B: cable arrived via fusão ───────────────────────────────────────
    // Bidirectional: fusões can be stored in either drag direction.
    //   is_reversed=0 → found via cabo_saida_id (cable is downstream, normal).
    //   is_reversed=1 → found via cabo_entrada_id (cable stored as drag source,
    //                   may be physically downstream — reversed creation order).
    //
    // Priority ordering:
    //   0 = normal splitter-output (spl_ent_id + cabo_saida_id=current)
    //   1 = reversed splitter-output (spl_sai_id 'o*' + cabo_entrada_id=current)
    //   2 = normal cable-to-cable
    //   3 = reversed cable-to-cable
    $skipClause = $skipFusaoId ? "AND f.id != {$skipFusaoId}" : "";
    $f = $db->fetch(
        "SELECT f.*, ce.codigo ceo_cod, ct.codigo cto_cod,
                IF(f.cabo_saida_id=? AND f.fibra_saida=?, 0, 1) AS is_reversed
         FROM fusoes f
         LEFT JOIN ceos ce ON ce.id=f.ceo_id
         LEFT JOIN ctos ct ON ct.id=f.cto_id
         WHERE (
             (f.cabo_saida_id=? AND f.fibra_saida=?)
             OR (f.cabo_entrada_id=? AND f.fibra_entrada=?)
         )
         AND NOT (f.tipo='passante' AND f.cabo_entrada_id=f.cabo_saida_id)
         {$skipClause}
         ORDER BY
           CASE
             WHEN is_reversed=0 AND f.spl_ent_id IS NOT NULL THEN 0
             WHEN is_reversed=1 AND f.spl_sai_id IS NOT NULL AND f.spl_sai_porta LIKE 'o%' THEN 1
             WHEN is_reversed=0 THEN 2
             ELSE 3
           END ASC,
           f.id ASC
         LIMIT 1",
        [$caboId, $fibraNum, $caboId, $fibraNum, $caboId, $fibraNum]
    );

    $caboNode      = ['t'=>'cabo','id'=>$caboId,'codigo'=>$caboCodigo,'comprimento_m'=>$caboLen,'fibra_num'=>$fibraNum,'perda_cabo'=>$perdaCabo];
    $passanteNodes = getPassanteNodes($db, $caboId, $fibraNum);

    // No upstream connection at all — return physical cable node (no signal)
    if (!$f) {
        return ['rota'=>array_merge([$caboNode], $passanteNodes), 'sinal'=>null, 'aviso'=>'Sem conexão upstream mapeada'];
    }

    $isReversed  = isset($f['is_reversed']) && (int)$f['is_reversed'] === 1;
    $elemCod     = $f['ceo_cod'] ?? $f['cto_cod'] ?? null;
    $elemTipo    = $f['ceo_id'] ? 'CEO' : ($f['cto_id'] ? 'CTO' : '');
    $splSaiPorta = $f['spl_sai_porta'] ?? '';

    // ── Case B1: normal splitter output → current cable ───────────────────────
    if (!empty($f['spl_ent_id'])) {
        $splResult = traceSplitterForRota(
            $db, (int)$f['spl_ent_id'], $f['spl_ent_porta'] ?? 'o0',
            $elemTipo, $elemCod, $depth+1
        );
        $sigFim = $splResult['sinal'] !== null ? $splResult['sinal'] - $caboLen * FIBER_ATTN_R : null;
        return ['rota'=>array_merge($splResult['rota'], [$caboNode], $passanteNodes),
                'sinal'=>$sigFim !== null ? round($sigFim,3) : null,
                'aviso'=>$splResult['aviso']];
    }

    // ── Case B1b: reversed splitter output (cable drag-source to output port) ─
    // User dragged FROM cable TO splitter output port → stored with spl_sai_id+'o*' porta.
    // Physically splitter output feeds cable, so trace backward through the splitter.
    if ($isReversed && !empty($f['spl_sai_id']) && strpos($splSaiPorta, 'o') === 0) {
        $splResult = traceSplitterForRota(
            $db, (int)$f['spl_sai_id'], $splSaiPorta,
            $elemTipo, $elemCod, $depth+1
        );
        $sigFim = $splResult['sinal'] !== null ? $splResult['sinal'] - $caboLen * FIBER_ATTN_R : null;
        return ['rota'=>array_merge($splResult['rota'], [$caboNode], $passanteNodes),
                'sinal'=>$sigFim !== null ? round($sigFim,3) : null,
                'aviso'=>$splResult['aviso']];
    }

    // ── Case B2: normal cable-to-cable ────────────────────────────────────────
    if (!$isReversed) {
        if (empty($f['cabo_entrada_id'])) {
            return ['rota'=>array_merge([$caboNode], $passanteNodes), 'sinal'=>null, 'aviso'=>null];
        }
        $up   = traceRota($db, (int)$f['cabo_entrada_id'], (int)$f['fibra_entrada'], $depth+1);
        $pdb  = $f['perda_db'];
        $tipo = $f['tipo'];
        if ($tipo === 'emenda')        $loss = $pdb !== null ? (float)$pdb : LOSS_EMENDA_R;
        elseif ($tipo === 'passante')  $loss = $pdb !== null ? (float)$pdb : LOSS_PASS_R;
        else                           $loss = $pdb !== null ? (float)$pdb : 0.0;
        $sigAfterFusao = $up['sinal'] !== null ? $up['sinal'] - $loss : null;
        $sigFim        = $sigAfterFusao !== null ? $sigAfterFusao - $caboLen * FIBER_ATTN_R : null;
        $spliceNode = ['t'=>'splice','tipo'=>$tipo,'elem_tipo'=>$elemTipo,'elem_cod'=>$elemCod,'perda_db'=>$loss];
        return ['rota'  => array_merge($up['rota'], [$spliceNode], [$caboNode], $passanteNodes),
                'sinal' => $sigFim !== null ? round($sigFim,3) : null,
                'aviso' => $up['aviso']];
    }

    // ── Case B3: reversed cable-to-cable ─────────────────────────────────────
    // Current cable stored as cabo_entrada_id (drag source) but physically downstream.
    // cabo_saida_id is the actual upstream cable. Pass skipFusaoId to prevent loop.
    if (!empty($f['cabo_saida_id']) && empty($f['spl_sai_id'])) {
        $up   = traceRota($db, (int)$f['cabo_saida_id'], (int)$f['fibra_saida'], $depth+1, (int)$f['id']);
        $pdb  = $f['perda_db'];
        $tipo = $f['tipo'];
        if ($tipo === 'emenda')        $loss = $pdb !== null ? (float)$pdb : LOSS_EMENDA_R;
        elseif ($tipo === 'passante')  $loss = $pdb !== null ? (float)$pdb : LOSS_PASS_R;
        else                           $loss = $pdb !== null ? (float)$pdb : 0.0;
        $sigAfterFusao = $up['sinal'] !== null ? $up['sinal'] - $loss : null;
        $sigFim        = $sigAfterFusao !== null ? $sigAfterFusao - $caboLen * FIBER_ATTN_R : null;
        $spliceNode = ['t'=>'splice','tipo'=>$tipo,'elem_tipo'=>$elemTipo,'elem_cod'=>$elemCod,'perda_db'=>$loss];
        return ['rota'  => array_merge($up['rota'], [$spliceNode], [$caboNode], $passanteNodes),
                'sinal' => $sigFim !== null ? round($sigFim,3) : null,
                'aviso' => $up['aviso']];
    }

    return ['rota'=>array_merge([$caboNode], $passanteNodes), 'sinal'=>null, 'aviso'=>null];
}

/**
 * Trace FORWARD from cable $caboId / fiber $fibraNum toward CTOs/clients.
 * Returns ['rota'=>array, 'aviso'=>string|null].
 * Bidirectional: handles fusões created in either drag direction.
 */
function traceForward($db, int $caboId, int $fibraNum, int $depth = 0): array {
    if ($depth > 30) return ['rota'=>[],'aviso'=>'Rastreamento muito profundo'];

    $ci         = caboInfoR($db, $caboId);
    $caboCodigo = $ci['codigo'];
    $caboLen    = $ci['comprimento_m'];
    $perdaCabo  = $ci['perda_cabo'];

    $caboNode      = ['t'=>'cabo','id'=>$caboId,'codigo'=>$caboCodigo,'comprimento_m'=>$caboLen,'fibra_num'=>$fibraNum,'perda_cabo'=>$perdaCabo];
    $passanteNodes = getPassanteNodes($db, $caboId, $fibraNum);
    $rota          = array_merge([$caboNode], $passanteNodes);

    // Find where this cable/fiber exits (this cable is the INPUT of a fusão)
    $f = $db->fetch(
        "SELECT f.*, ce.codigo ceo_cod, ct.codigo cto_cod
         FROM fusoes f
         LEFT JOIN ceos ce ON ce.id=f.ceo_id
         LEFT JOIN ctos ct ON ct.id=f.cto_id
         WHERE f.cabo_entrada_id=? AND f.fibra_entrada=?
           AND NOT (f.tipo='passante' AND f.cabo_saida_id=f.cabo_entrada_id)
         ORDER BY f.id ASC LIMIT 1",
        [$caboId, $fibraNum]
    );

    if (!$f) {
        return ['rota'=>$rota, 'aviso'=>null];
    }

    $elemTipo = $f['ceo_id'] ? 'CEO' : ($f['cto_id'] ? 'CTO' : '');
    $elemCod  = $f['ceo_cod'] ?? $f['cto_cod'] ?? '';
    $tipo     = $f['tipo'];
    $loss     = $f['perda_db'] !== null ? (float)$f['perda_db'] : LOSS_EMENDA_R;

    // ── Goes into a splitter ──────────────────────────────────────────────────
    if (!empty($f['spl_sai_id'])) {
        $splId = (int)$f['spl_sai_id'];
        $spl   = $db->fetch(
            "SELECT s.codigo, s.relacao, s.perda_insercao_db
             FROM elemento_splitters es JOIN splitters s ON s.id=es.splitter_id
             WHERE es.id=? LIMIT 1", [$splId]);

        $rota[] = ['t'=>'splice','tipo'=>$tipo,'elem_tipo'=>$elemTipo,'elem_cod'=>$elemCod,'perda_db'=>$loss];
        $rota[] = [
            't'           => 'splitter',
            'codigo'      => ($spl['codigo']  ?? 'SPL'),
            'relacao'     => ($spl['relacao'] ?? '?'),
            'elem_tipo'   => $elemTipo,
            'elem_cod'    => $elemCod,
            'perda_emenda'=> $loss,
            'perda_db'    => $spl['perda_insercao_db'] ?? null,
            'porta'       => 'saída',
        ];

        // Find all downstream cables from this splitter
        $outputs = $db->fetchAll(
            "SELECT cabo_saida_id, fibra_saida, spl_ent_porta FROM fusoes
             WHERE spl_ent_id=? AND cabo_saida_id IS NOT NULL
             ORDER BY spl_ent_porta",
            [$splId]
        );
        $totalSaidas = count($outputs);

        if (!empty($outputs)) {
            $rota[count($rota)-1]['saidas_total'] = $totalSaidas;
            if ($totalSaidas === 1) {
                $next = traceForward($db, (int)$outputs[0]['cabo_saida_id'], (int)$outputs[0]['fibra_saida'], $depth+1);
                $rota = array_merge($rota, $next['rota']);
                return ['rota'=>$rota, 'aviso'=>$next['aviso']];
            } else {
                $branches = [];
                foreach ($outputs as $out) {
                    $portaIdx   = isset($out['spl_ent_porta']) ? (intval(substr($out['spl_ent_porta'], 1)) + 1) : null;
                    $sub        = traceForward($db, (int)$out['cabo_saida_id'], (int)$out['fibra_saida'], $depth+1);
                    $branches[] = ['porta'=>$portaIdx ? 'S'.$portaIdx : '?', 'rota'=>$sub['rota'], 'aviso'=>$sub['aviso']];
                }
                $rota[count($rota)-1]['branches'] = $branches;
                return ['rota'=>$rota, 'aviso'=>null];
            }
        }
        return ['rota'=>$rota, 'aviso'=>null];
    }

    // ── Goes to another cable (emenda/passante) ───────────────────────────────
    if (!empty($f['cabo_saida_id'])) {
        $rota[] = ['t'=>'splice','tipo'=>$tipo,'elem_tipo'=>$elemTipo,'elem_cod'=>$elemCod,'perda_db'=>$loss];
        $next   = traceForward($db, (int)$f['cabo_saida_id'], (int)$f['fibra_saida'], $depth+1);
        $rota   = array_merge($rota, $next['rota']);
        return ['rota'=>$rota, 'aviso'=>$next['aviso']];
    }

    return ['rota'=>$rota, 'aviso'=>null];
}

// ── Endpoint: ?tipo=fibra ─────────────────────────────────────────────────────
if ($tipo === 'fibra') {
    $fibraNum = (int)($_GET['fibra']    ?? 0);
    $elemTipo = in_array($_GET['elem_tipo']??'', ['ceo','cto']) ? $_GET['elem_tipo'] : 'ceo';
    $elemId   = (int)($_GET['elem_id'] ?? 0);
    $sentido  = in_array($_GET['sentido']??'', ['forward']) ? 'forward' : 'backward';

    if (!$id || !$fibraNum) jr(['success'=>false,'error'=>'Parâmetros inválidos']);

    // Determine if this box is at start or end of cable (used in backward mode to adjust signal display)
    // elem_id is optional — if 0 (e.g. called from DIO rack map), skip position detection
    $boxAtStart = false;
    if ($elemId) {
        $pts        = $db->fetch("SELECT MIN(sequencia) mn, MAX(sequencia) mx FROM cabo_pontos WHERE cabo_id=?", [$id]);
        $mid        = $pts ? (((float)$pts['mn'] + (float)$pts['mx']) / 2.0) : 0;
        $anchor     = $db->fetch("SELECT sequencia FROM cabo_pontos WHERE cabo_id=? AND elemento_tipo=? AND elemento_id=? LIMIT 1", [$id, $elemTipo, $elemId]);
        $boxAtStart = $anchor ? ((float)$anchor['sequencia'] <= $mid) : true;
    }

    // ── Forward trace ─────────────────────────────────────────────────────────
    if ($sentido === 'forward') {
        // If box is at END of cable, find the upstream/source cable first so we trace from the right end.
        // Actually traceForward always follows cabo_entrada_id → next, so starting from the current cable
        // is always correct (it traces the path this fiber continues through).
        $result = traceForward($db, $id, $fibraNum);
        jr(['success'=>true,'rota'=>$result['rota'],'sinal'=>null,'aviso'=>$result['aviso'],'sentido'=>'forward']);
    }

    // ── Backward trace (default) ──────────────────────────────────────────────
    $result = traceRota($db, $id, $fibraNum);

    $sinalBox = null;
    if ($result['sinal'] !== null) {
        if ($boxAtStart) {
            $caboLen  = caboLenR($db, $id);
            $sinalBox = round($result['sinal'] + $caboLen * FIBER_ATTN_R, 3);
        } else {
            $sinalBox = $result['sinal'];
        }
    }

    jr(['success'=>true,'rota'=>$result['rota'],'sinal'=>$sinalBox,'aviso'=>$result['aviso'],'sentido'=>'backward']);
}

// ── Endpoint: ?tipo=spl ───────────────────────────────────────────────────────
if ($tipo === 'spl') {
    $porta = $_GET['porta'] ?? '';
    if (!$id) jr(['success'=>false,'error'=>'ID inválido']);

    $isOutput = str_starts_with($porta, 'o');
    if ($isOutput) {
        $fu = $db->fetch("SELECT cabo_saida_id, fibra_saida, spl_sai_id FROM fusoes WHERE spl_ent_id=? AND spl_ent_porta=? LIMIT 1", [$id, $porta]);
        if (!$fu) {
            $splResult = traceSplitterForRota($db, $id, $porta, '', '', 0);
            jr(['success'=>true,'rota'=>$splResult['rota'],'sinal'=>$splResult['sinal'],'aviso'=>$splResult['aviso']]);
        }
        if (!empty($fu['cabo_saida_id'])) {
            $result = traceRota($db, (int)$fu['cabo_saida_id'], (int)$fu['fibra_saida']);
            $sinalPorta = null;
            if ($result['sinal'] !== null) {
                $caboLen    = caboLenR($db, (int)$fu['cabo_saida_id']);
                $sinalPorta = round($result['sinal'] + $caboLen * FIBER_ATTN_R, 3);
            }
            jr(['success'=>true,'rota'=>$result['rota'],'sinal'=>$sinalPorta,'aviso'=>$result['aviso']]);
        } else {
            $splResult = traceSplitterForRota($db, $id, $porta, '', '', 0);
            jr(['success'=>true,'rota'=>$splResult['rota'],'sinal'=>$splResult['sinal'],'aviso'=>$splResult['aviso']]);
        }
    } else {
        $fu = $db->fetch("SELECT cabo_entrada_id, fibra_entrada FROM fusoes WHERE spl_sai_id=? AND spl_sai_porta=? AND cabo_entrada_id IS NOT NULL LIMIT 1", [$id, $porta]);
        if (!$fu) jr(['success'=>true,'rota'=>[],'sinal'=>null,'aviso'=>'Porta sem cabo conectado']);
        $result = traceRota($db, (int)$fu['cabo_entrada_id'], (int)$fu['fibra_entrada']);
        jr(['success'=>true,'rota'=>$result['rota'],'sinal'=>$result['sinal'],'aviso'=>$result['aviso']]);
    }
}

jr(['success'=>false,'error'=>'Tipo não suportado']);
