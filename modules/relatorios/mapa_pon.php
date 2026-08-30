<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
Auth::check();
$db = Database::getInstance();

define('MP_FIBER_ATTN', 0.00025);
define('MP_LOSS_CONN',  0.10);

function mpFmt(float $m): string {
    return $m >= 1000 ? number_format($m/1000,2).' km' : number_format($m,0).' m';
}
function mpSigColor(float $d): string {
    if ($d >= -20) return '#00cc66';
    if ($d >= -24) return '#88dd22';
    if ($d >= -27) return '#ffcc00';
    if ($d >= -30) return '#ff8800';
    return '#ff4455';
}
function mpMLabel(string ...$parts): string {
    $parts = array_values(array_filter($parts, fn($p) => $p !== '' && $p !== null));
    $esc   = array_map(fn($p) => str_replace('"', '#quot;', $p), $parts);
    return implode('<br/>', $esc);
}

function mpCliBox($db, int $ctoId, string $nid, array &$nodeDefs, array &$edgeDefs, array &$styleDefs, array &$seen): void {
    if (isset($seen['cli_'.$nid])) return;
    $cli = (int)($db->fetch("SELECT COUNT(*) n FROM clientes WHERE cto_id=?", [$ctoId])['n']??0);
    if ($cli <= 0) return;
    $clnid = 'cli_'.$nid;
    $nodeDefs[]   = $clnid.'["'.$cli.' cliente'.($cli!=1?'s':'').'"]';
    $styleDefs[]  = 'style '.$clnid.' fill:#1a1400,stroke:#ddaa00,color:#ffdd44';
    $edgeDefs[]   = $nid.' -.-> '.$clnid;
    $seen[$clnid] = true;
    // Store client count back into parent CTO seen entry for stats
    if (isset($seen[$nid]) && is_array($seen[$nid])) $seen[$nid]['clients'] = $cli;
}

function mpSplOutputs($db, int $splInstId): array {
    $rows = $db->fetchAll(
        "SELECT cabo_saida_id AS cid, fibra_saida AS fn, spl_ent_porta AS porta
         FROM fusoes WHERE spl_ent_id=? AND cabo_saida_id IS NOT NULL ORDER BY spl_ent_porta",
        [$splInstId]
    );
    $rev = $db->fetchAll(
        "SELECT cabo_entrada_id AS cid, fibra_entrada AS fn, spl_sai_porta AS porta
         FROM fusoes WHERE spl_sai_id=? AND spl_sai_porta LIKE 'o%' AND cabo_entrada_id IS NOT NULL ORDER BY spl_sai_porta",
        [$splInstId]
    );
    $by = [];
    foreach (array_merge($rev, $rows) as $r) $by[$r['porta']] = [(int)$r['cid'],(int)$r['fn'],$r['porta']];
    ksort($by);
    return array_values($by);
}

/**
 * Traverses the fiber tree and populates Mermaid node/edge/style arrays.
 */
function mpWalk(
    $db,
    int $caboId, int $fibraNum,
    float $sigIn, float $cumLen,
    string $parentId,
    array &$nodeDefs, array &$edgeDefs, array &$styleDefs,
    array &$seen,
    int $depth = 0
): void {
    if ($depth > 50) return;

    $cabo = $db->fetch("SELECT id, codigo, comprimento_m, comprimento_real, fibras_por_tubo FROM cabos WHERE id=?", [$caboId]);
    if (!$cabo) return;

    $len    = ((float)($cabo['comprimento_real']??0) > 0)
              ? (float)$cabo['comprimento_real'] : (float)($cabo['comprimento_m']??0);
    $sigEnd = $sigIn - $len * MP_FIBER_ATTN;
    $cumLen += $len;

    // Fiber ID: T{tube}-{fiber_in_tube}
    $fpt      = max(1, (int)($cabo['fibras_por_tubo'] ?? 12));
    $tubeNum  = (int)ceil($fibraNum / $fpt);
    $fiberPos = (($fibraNum - 1) % $fpt) + 1;
    $fiberLbl = sprintf('T%d-%02d', $tubeNum, $fiberPos);

    // Edge label: T1-01 │ cable_len │ signal │ cumulative  (│ = U+2502, visually | but safe for Mermaid parser)
    $cableM = (int)round($len);
    $cumM   = (int)round($cumLen);
    $eLbl   = sprintf('%s │ %dm │ %+.3f dBm │ %dm', $fiberLbl, $cableM, $sigEnd, $cumM);

    $f = $db->fetch(
        "SELECT f.*, ce.id ceo_id_v, ce.codigo ceo_cod,
                ct.id cto_id_v, ct.codigo cto_cod
         FROM fusoes f
         LEFT JOIN ceos ce ON ce.id=f.ceo_id
         LEFT JOIN ctos ct ON ct.id=f.cto_id
         WHERE f.cabo_entrada_id=? AND f.fibra_entrada=?
           AND NOT (f.tipo='passante' AND f.cabo_saida_id=f.cabo_entrada_id)
           AND (f.spl_sai_porta IS NULL OR f.spl_sai_porta NOT LIKE 'o%')
         ORDER BY CASE WHEN f.spl_sai_id IS NOT NULL THEN 0 ELSE 1 END, f.id ASC
         LIMIT 1",
        [$caboId, $fibraNum]
    );

    if (!$f) {
        $ep = $db->fetch(
            "SELECT elemento_tipo, elemento_id FROM cabo_pontos
             WHERE cabo_id=? AND elemento_tipo IN ('cto','ceo') ORDER BY sequencia DESC LIMIT 1",
            [$caboId]
        );
        if (!$ep) return;
        $eid = (int)$ep['elemento_id']; $etype = $ep['elemento_tipo'];
        $nid = $etype.'_'.$eid;
        if (!isset($seen[$nid])) {
            if ($etype === 'cto') {
                $ct  = $db->fetch("SELECT codigo, nome FROM ctos WHERE id=?", [$eid]);
                $sc  = mpSigColor($sigEnd);
                $lbl = mpMLabel(
                    $ct['codigo']??'CTO',
                    'Atendimento',
                    sprintf('%+.3f dBm', $sigEnd)
                );
                $nodeDefs[]  = $nid.'(["'.$lbl.'"])';
                $styleDefs[] = 'style '.$nid.' fill:#001f0f,stroke:'.$sc.',color:#44ee88';
                $seen[$nid]  = ['type'=>'cto','clients'=>0,'len'=>$cumLen];
                mpCliBox($db, $eid, $nid, $nodeDefs, $edgeDefs, $styleDefs, $seen);
            } else {
                $ce  = $db->fetch("SELECT codigo FROM ceos WHERE id=?", [$eid]);
                $lbl = mpMLabel(
                    $ce['codigo']??'CEO',
                    'Derivação',
                    sprintf('%+.3f dBm', $sigEnd)
                );
                $nodeDefs[]  = $nid.'["'.$lbl.'"]';
                $styleDefs[] = 'style '.$nid.' fill:#1a0d00,stroke:#cc6600,color:#ffaa44';
                $seen[$nid]  = ['type'=>'ceo','clients'=>0,'len'=>$cumLen];
            }
        }
        $edgeDefs[] = $parentId.' -->|"'.$eLbl.'"| '.$nid;
        return;
    }

    $boxType = !empty($f['ceo_id_v']) ? 'ceo' : (!empty($f['cto_id_v']) ? 'cto' : null);
    $boxId   = !empty($f['ceo_id_v']) ? (int)$f['ceo_id_v'] : (int)($f['cto_id_v']??0);
    $boxCod  = $f['ceo_cod'] ?? $f['cto_cod'] ?? '';
    $nid     = $boxType ? $boxType.'_'.$boxId : null;

    if (!empty($f['spl_sai_id'])) {
        $splId  = (int)$f['spl_sai_id'];
        $spl    = $db->fetch(
            "SELECT s.relacao, s.perda_insercao_db
             FROM elemento_splitters es JOIN splitters s ON s.id=es.splitter_id WHERE es.id=?",[$splId]);
        $connL  = (float)($f['perda_db'] ?? MP_LOSS_CONN);
        $splL   = $spl ? (float)($spl['perda_insercao_db']??3.5) : 3.5;
        $sigOut = $sigEnd - $connL - $splL;
        $relac  = $spl ? ($spl['relacao']??'?') : '?';
        $lossTotal = $connL + $splL;
        if ($nid && !isset($seen[$nid])) {
            $lbl = mpMLabel(
                $boxCod,
                'Splitter '.$relac.' (-'.number_format($lossTotal,2).'dB)',
                'in: '.sprintf('%+.3f', $sigEnd).' dBm',
                'out: '.sprintf('%+.3f', $sigOut).' dBm'
            );
            if ($boxType === 'ceo') {
                $nodeDefs[]  = $nid.'["'.$lbl.'"]';
                $styleDefs[] = 'style '.$nid.' fill:#1a0d00,stroke:#cc6600,color:#ffaa44';
            } else {
                $nodeDefs[]  = $nid.'(["'.$lbl.'"])';
                $styleDefs[] = 'style '.$nid.' fill:#001020,stroke:#0077cc,color:#44aaff';
            }
            $seen[$nid] = ['type'=>$boxType,'spl'=>true,'spl_relac'=>$relac,'clients'=>0,'len'=>$cumLen];
            if ($boxType === 'cto') mpCliBox($db, $boxId, $nid, $nodeDefs, $edgeDefs, $styleDefs, $seen);
        }
        if ($nid) $edgeDefs[] = $parentId.' -->|"'.$eLbl.'"| '.$nid;
        $nxt = $nid ?? $parentId;
        foreach (mpSplOutputs($db, $splId) as $out) {
            mpWalk($db,$out[0],$out[1],$sigOut,$cumLen,$nxt,$nodeDefs,$edgeDefs,$styleDefs,$seen,$depth+1);
        }
        return;
    }

    if (!empty($f['cabo_saida_id'])) {
        $fusL    = (float)($f['perda_db'] ?? MP_LOSS_CONN);
        $fusType = (($f['tipo']??'') === 'passante') ? 'Passagem' : 'Fusão';
        $sigNext = $sigEnd - $fusL;
        if ($nid && !isset($seen[$nid])) {
            $lbl = mpMLabel(
                $boxCod,
                $fusType.' (-'.number_format($fusL,2).'dB)',
                'in: '.sprintf('%+.3f', $sigEnd).' dBm',
                'out: '.sprintf('%+.3f', $sigNext).' dBm'
            );
            if ($boxType === 'ceo') {
                $nodeDefs[]  = $nid.'["'.$lbl.'"]';
                $styleDefs[] = 'style '.$nid.' fill:#1a0d00,stroke:#cc6600,color:#ffaa44';
            } else {
                $nodeDefs[]  = $nid.'(["'.$lbl.'"])';
                $styleDefs[] = 'style '.$nid.' fill:#001020,stroke:#0077cc,color:#44aaff';
            }
            $seen[$nid] = ['type'=>$boxType,'spl'=>false,'clients'=>0,'len'=>$cumLen];
            if ($boxType === 'cto') mpCliBox($db, $boxId, $nid, $nodeDefs, $edgeDefs, $styleDefs, $seen);
        }
        if ($nid) { $edgeDefs[] = $parentId.' -->|"'.$eLbl.'"| '.$nid; $nxt = $nid; }
        else        { $nxt = $parentId; }
        mpWalk($db,(int)$f['cabo_saida_id'],(int)$f['fibra_saida'],$sigNext,$cumLen,
               $nxt,$nodeDefs,$edgeDefs,$styleDefs,$seen,$depth+1);
    }
}

// ── Load OLTs ──────────────────────────────────────────────────────────────
$olts = $db->fetchAll(
    "SELECT o.id, o.codigo, o.nome, r.codigo rack_cod
     FROM olts o JOIN racks r ON r.id=o.rack_id ORDER BY r.codigo, o.codigo"
);

// ── Handle generation ──────────────────────────────────────────────────────
$selectedPon = null;
$mermaidDef  = null;
$mapError    = '';
$mapStats    = ['ceos'=>0,'spls_atend'=>0,'spls_deriv'=>0,'portas_atend'=>0,'ctos'=>0,'clients'=>0,'len'=>0];

$ponId = (int)($_GET['pon_id'] ?? 0);
if ($ponId) {
    $pon = $db->fetch(
        "SELECT op.*, o.id olt_id_val, o.codigo olt_cod, o.nome olt_nome, r.codigo rack_cod
         FROM olt_pons op JOIN olts o ON o.id=op.olt_id JOIN racks r ON r.id=o.rack_id WHERE op.id=?",
        [$ponId]
    );
    if ($pon) {
        $selectedPon = $pon;
        $conn = $db->fetch(
            "SELECT rb.cabo_id, rb.fibra_num
             FROM rack_conexoes ra
             JOIN rack_conexoes rb ON rb.dio_id=ra.dio_id AND rb.dio_porta=ra.dio_porta AND rb.lado='B'
             WHERE ra.olt_pon_id=? AND ra.lado='A' LIMIT 1",
            [$ponId]
        );
        if ($conn) {
            $potencia  = (float)($pon['potencia_dbm'] ?? 5.0);
            $nodeDefs  = [];
            $edgeDefs  = [];
            $styleDefs = [];
            $seen      = [];

            // OLT node
            $oltNid       = 'olt_'.$pon['olt_id_val'];
            $seen[$oltNid]= true;
            $oltLbl       = mpMLabel($pon['olt_cod'], 'PON '.$pon['slot'].'/'.$pon['numero_pon'], 'TX '.sprintf('%+.2f dBm', $potencia));
            $nodeDefs[]   = $oltNid.'(["'.$oltLbl.'"])';
            $styleDefs[]  = 'style '.$oltNid.' fill:#0d2447,stroke:#1a6fff,color:#7bb8ff,font-size:11px';

            mpWalk($db, (int)$conn['cabo_id'], (int)$conn['fibra_num'],
                   $potencia, 0.0, $oltNid,
                   $nodeDefs, $edgeDefs, $styleDefs, $seen, 1);

            // Compute stats from $seen metadata
            foreach ($seen as $nid => $meta) {
                if (!is_array($meta)) continue;
                $t = $meta['type'] ?? '';
                if ($t === 'ceo') $mapStats['ceos']++;
                if ($t === 'cto') { $mapStats['ctos']++; $mapStats['clients'] += (int)($meta['clients']??0); }
                if (!empty($meta['spl'])) {
                    $ports = (int)(explode(':', $meta['spl_relac']??'1:1')[1] ?? 1);
                    if ($t === 'cto') { $mapStats['spls_atend']++; $mapStats['portas_atend'] += $ports; }
                    else              { $mapStats['spls_deriv']++; }
                }
                $mapStats['len'] = max($mapStats['len'], (float)($meta['len']??0));
            }

            $mermaidDef  = "flowchart LR\n";
            foreach ($nodeDefs  as $l) $mermaidDef .= "    {$l}\n";
            foreach ($edgeDefs  as $l) $mermaidDef .= "    {$l}\n";
            foreach ($styleDefs as $l) $mermaidDef .= "    {$l}\n";

        } else {
            $mapError = 'PON sem cabo conectado via DIO. Verifique as conexões no rack.';
        }
    } else {
        $mapError = 'PON não encontrada.';
    }
}

$pageTitle  = 'Mapa de PON';
$activePage = 'relatorios';
$extraHead  = '<style>
.mp-wrap{padding:20px 24px;max-width:1400px}
.mp-form{background:var(--bg-panel);border:1px solid var(--border);border-radius:12px;padding:20px 24px;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-bottom:20px}
.mp-form .form-group{display:flex;flex-direction:column;gap:4px;min-width:200px}
.mp-form label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px}
.mp-form select{background:var(--bg-input,#0d1117);border:1px solid var(--border);border-radius:6px;padding:7px 10px;color:var(--text);font-size:13px}
.mp-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.mp-stat{background:var(--bg-panel);border:1px solid var(--border);border-radius:8px;padding:10px 16px;text-align:center;min-width:90px}
.mp-stat .num{font-size:22px;font-weight:700;color:#e6edf3}
.mp-stat .lbl{font-size:10px;color:var(--text-muted);margin-top:2px;text-transform:uppercase;letter-spacing:.5px}
.mp-olt-hdr{background:rgba(51,153,255,.06);border:1px solid rgba(51,153,255,.2);border-radius:10px;padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.mp-olt-hdr .olt-label{font-size:13px;font-weight:700;color:#3399ff}
.mp-olt-hdr .olt-pon{font-size:12px;color:#666}
.mp-olt-hdr .olt-sig{font-size:13px;font-weight:700;color:#00cc66}
.mp-diagram-wrap{background:#060a0f;border:1px solid var(--border);border-radius:10px;padding:16px;min-height:300px;overflow:auto;display:block}
.mp-diagram-wrap svg{max-width:none!important}
.mp-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:#555;margin-bottom:12px;align-items:center}
.mp-loading{text-align:center;padding:60px;color:#444;font-size:13px}
.mp-diagram-wrap .edgeLabel{font-size:10px!important}
.mp-diagram-wrap .edgeLabel span{font-size:10px!important}
@media print{
  .no-print,.sidebar,.topbar,.mp-form{display:none!important}
  .mp-diagram-wrap{background:#fff!important;border:none}
  body{background:#fff;color:#000}
}
</style>';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-content mp-wrap">

    <div class="page-header no-print">
        <div>
            <h2><i class="fas fa-project-diagram" style="color:#33ccaa"></i> Mapa de PON</h2>
            <p>Topologia visual completa — fibras, caixas e clientes</p>
        </div>
        <?php if ($mermaidDef): ?>
        <div style="display:flex;gap:8px" class="no-print">
            <button onclick="exportPng()" class="btn btn-secondary btn-sm">
                <i class="fas fa-image"></i> Exportar PNG
            </button>
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Seletor -->
    <form method="GET" class="mp-form no-print">
        <div class="form-group">
            <label>OLT</label>
            <select id="sel-olt" name="olt_id" onchange="loadPons(this.value)">
                <option value="">— Selecione a OLT —</option>
                <?php foreach ($olts as $o): ?>
                    <option value="<?= $o['id'] ?>"
                        <?= (isset($_GET['olt_id']) && (int)$_GET['olt_id']==$o['id']) ? 'selected' : '' ?>>
                        <?= e($o['rack_cod'].' / '.$o['codigo'].($o['nome'] ? ' — '.$o['nome'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Porta PON</label>
            <select id="sel-pon" name="pon_id">
                <option value="">— Selecione a OLT primeiro —</option>
                <?php if ($selectedPon): ?>
                    <option value="<?= $selectedPon['id'] ?>" selected>
                        PON <?= e($selectedPon['slot'].'/'.$selectedPon['numero_pon']) ?>
                        <?= $selectedPon['descricao'] ? ' — '.e($selectedPon['descricao']) : '' ?>
                    </option>
                <?php endif; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end">
            <i class="fas fa-project-diagram"></i> Gerar Mapa
        </button>
    </form>

    <?php if ($mapError): ?>
        <div class="alert alert-danger" style="margin-bottom:16px">
            <i class="fas fa-exclamation-triangle"></i> <?= e($mapError) ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedPon && $mermaidDef): ?>

    <!-- Header OLT/PON -->
    <div class="mp-olt-hdr">
        <i class="fas fa-server" style="color:#3399ff;font-size:16px"></i>
        <span class="olt-label"><?= e($selectedPon['rack_cod'].' / '.$selectedPon['olt_cod']) ?></span>
        <span class="olt-pon">PON <?= e($selectedPon['slot'].'/'.$selectedPon['numero_pon']) ?></span>
        <span class="olt-sig">TX <?= number_format((float)($selectedPon['potencia_dbm']??5),2) ?> dBm</span>
        <?php if (!empty($selectedPon['descricao'])): ?>
            <span style="color:#555;font-size:11px"><?= e($selectedPon['descricao']) ?></span>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:10px;color:#444"><?= date('d/m/Y H:i') ?></span>
    </div>

    <!-- Estatísticas -->
    <div class="mp-stats no-print">
        <div class="mp-stat"><div class="num"><?= $mapStats['ceos'] ?></div><div class="lbl">CEOs</div></div>
        <div class="mp-stat" style="border-color:#cc6600">
            <div class="num" style="color:#ff8833"><?= $mapStats['spls_deriv'] ?></div>
            <div class="lbl">SPL Derivação</div>
        </div>
        <div class="mp-stat" style="border-color:#0077cc">
            <div class="num" style="color:#44aaff"><?= $mapStats['spls_atend'] ?></div>
            <div class="lbl">SPL Atendimento</div>
        </div>
        <div class="mp-stat" style="border-color:#0077cc">
            <div class="num" style="color:#44aaff;font-size:18px"><?= $mapStats['portas_atend'] ?></div>
            <div class="lbl">Portas Atend.</div>
        </div>
        <div class="mp-stat"><div class="num"><?= $mapStats['ctos'] ?></div><div class="lbl">CTOs</div></div>
        <div class="mp-stat"><div class="num" style="color:#00cc66"><?= $mapStats['clients'] ?></div><div class="lbl">Clientes</div></div>
        <div class="mp-stat"><div class="num" style="font-size:14px"><?= mpFmt($mapStats['len']) ?></div><div class="lbl">Fibra total</div></div>
    </div>

    <!-- Legenda -->
    <div class="mp-legend no-print">
        <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:28px;height:16px;border:2px solid #1a6fff;border-radius:10px;background:#0d2447"></span> OLT
        </span>
        <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:28px;height:16px;border:2px solid #cc6600;background:#1a0d00"></span> CEO (retângulo)
        </span>
        <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:28px;height:16px;border:2px solid #00aa55;border-radius:10px;background:#001f0f"></span> CTO (oval)
        </span>
        <span style="color:#555">Seta = cabo com distância e sinal</span>
        <span style="color:#00cc66">■ ≥−20</span>
        <span style="color:#88dd22">■ ≥−24</span>
        <span style="color:#ffcc00">■ ≥−27</span>
        <span style="color:#ff8800">■ ≥−30</span>
        <span style="color:#ff4455">■ &lt;−30 dBm</span>
    </div>

    <!-- Diagrama -->
    <div class="mp-diagram-wrap">
        <div id="mp-diagram" class="mp-loading">
            <i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px"></i>
            Renderizando diagrama...
        </div>
        <div class="mermaid" id="mp-mermaid-src" style="display:none"><?= htmlspecialchars($mermaidDef, ENT_NOQUOTES) ?></div>
    </div>

    <?php elseif (!$ponId): ?>
    <div style="text-align:center;padding:80px 20px">
        <i class="fas fa-project-diagram" style="font-size:56px;display:block;margin-bottom:16px;color:#1a2030"></i>
        <div style="font-size:14px;color:#555">Selecione uma OLT e uma porta PON para gerar o mapa</div>
    </div>
    <?php endif; ?>

</div>

<?php if ($mermaidDef): ?>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10.6.1/dist/mermaid.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dom-to-image-more@3.4.0/dist/dom-to-image-more.min.js"></script>
<script>
const MP_DEF = <?= json_encode($mermaidDef) ?>;

mermaid.initialize({
    startOnLoad: false,
    theme: 'dark',
    flowchart: {
        htmlLabels: true,
        curve: 'basis',
        padding: 20,
        nodeSpacing: 50,
        rankSpacing: 90,
        diagramPadding: 16,
        useMaxWidth: false,
    },
    themeVariables: {
        fontFamily: "'Segoe UI', sans-serif",
        fontSize: '12px',
        darkMode: true,
        background: '#060a0f',
        primaryColor: '#1a0d00',
        primaryBorderColor: '#cc6600',
        primaryTextColor: '#ffaa44',
        lineColor: '#2a4a3a',
        edgeLabelBackground: '#0c1018',
        tertiaryColor: '#060a0f',
    },
    securityLevel: 'loose',
});

async function renderDiagram() {
    const el = document.getElementById('mp-diagram');
    try {
        const id = 'mp-svg-' + Date.now();
        const { svg } = await mermaid.render(id, MP_DEF);
        el.innerHTML = svg;
        el.classList.remove('mp-loading');
        const svgEl = el.querySelector('svg');
        if (svgEl) {
            svgEl.style.maxWidth = 'none';
            svgEl.style.height = 'auto';
            svgEl.style.display = 'block';
            svgEl.removeAttribute('width');
            // Ensure full diagram is visible — set explicit viewBox from actual dims
            const bb = svgEl.getBBox ? svgEl.getBBox() : null;
            if (bb && bb.width > 0) {
                const pad = 24;
                svgEl.setAttribute('viewBox', `${bb.x-pad} ${bb.y-pad} ${bb.width+pad*2} ${bb.height+pad*2}`);
                svgEl.setAttribute('width',  bb.width  + pad*2);
                svgEl.setAttribute('height', bb.height + pad*2);
            }
        }
    } catch(err) {
        document.getElementById('mp-diagram').innerHTML =
            '<div style="color:#ff4455;padding:20px;font-size:13px">'
            + '<i class="fas fa-exclamation-triangle"></i> Erro ao renderizar: ' + err.message
            + '<pre style="margin-top:12px;font-size:11px;color:#666;white-space:pre-wrap;max-height:200px;overflow:auto">'
            + MP_DEF.substring(0, 1500) + '</pre></div>';
        console.error('Mermaid error:', err);
    }
}

async function exportPng() {
    const svgEl = document.querySelector('#mp-diagram svg');
    if (!svgEl) { alert('Diagrama ainda não foi renderizado.'); return; }

    const btn = document.querySelector('button[onclick="exportPng()"]');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...'; btn.disabled = true; }

    try {
        // Full SVG dimensions — not the visible viewport
        const vb = svgEl.viewBox.baseVal;
        const w  = parseFloat(svgEl.getAttribute('width'))  || (vb && vb.width)  || svgEl.scrollWidth  || 1200;
        const h  = parseFloat(svgEl.getAttribute('height')) || (vb && vb.height) || svgEl.scrollHeight || 800;

        const filename = 'mapa-<?= urlencode(($selectedPon['olt_cod']??'olt').'-pon'.($selectedPon['slot']??'').'-'.($selectedPon['numero_pon']??'')) ?>.png';

        // Capture the SVG element directly (not the scrollable container)
        // dom-to-image-more handles foreignObject labels correctly
        const blob = await domtoimage.toBlob(svgEl, {
            bgcolor : '#060a0f',
            scale   : 2,
            width   : w,
            height  : h,
            style   : { overflow: 'visible', background: '#060a0f' },
        });

        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.download = filename;
        a.href     = url;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch(err) {
        console.error('exportPng error:', err);
        alert('Erro ao gerar PNG: ' + err.message);
    } finally {
        if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderDiagram);
} else {
    renderDiagram();
}
</script>
<?php endif; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
async function loadPons(oltId) {
    const sel = document.getElementById('sel-pon');
    sel.innerHTML = '<option value="">Carregando...</option>';
    if (!oltId) { sel.innerHTML = '<option value="">— Selecione a OLT primeiro —</option>'; return; }
    try {
        const r = await fetch(`${BASE_URL}/api/olt_pons.php?olt_id=${oltId}`);
        const d = await r.json();
        if (!d.success || !d.pons.length) { sel.innerHTML = '<option value="">Nenhuma PON cadastrada</option>'; return; }
        sel.innerHTML = '<option value="">— Selecione a PON —</option>' +
            d.pons.map(p => `<option value="${p.id}">PON ${p.slot}/${p.numero_pon}${p.descricao?' — '+p.descricao:''}</option>`).join('');
    } catch(e) { sel.innerHTML = '<option value="">Erro ao carregar</option>'; }
}
const oltSel = document.getElementById('sel-olt');
if (oltSel && oltSel.value) loadPons(oltSel.value);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
