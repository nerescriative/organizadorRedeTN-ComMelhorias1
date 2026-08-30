<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
Auth::check();
$db = Database::getInstance();

// Inclui o cabeçalho oficial do sistema para trazer os estilos visuais
include __DIR__ . '/../../includes/header.php';

// Busca as OLTs cadastradas no sistema
$olts = $db->fetchAll("SELECT * FROM olts ORDER BY nome ASC");
?>
<div class="page-content">
<?php pageHeader('OLTs / Chassis', 'fa-server', '#ff3333', count($olts), 'OLTs cadastradas', BASE_URL.'/modules/olts/edit.php', 'Nova OLT', '', '<a href="'.BASE_URL.'/dashboard.php" class="btn btn-secondary"><i class="fas fa-map"></i> Ver no Mapa</a>'); 
flashMessages(); ?>

<?php tableOpen() ?>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nome do Chassi / OLT</th>
            <th>Status</th>
            <th style="width:150px; text-align:center">Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($olts)): ?>
        <tr><td colspan="4" class="text-center">Nenhuma OLT cadastrada.</td></tr>
    <?php else: ?>
        <?php foreach ($olts as $o): ?>
        <tr>
            <td><?= $o['id'] ?></td>
            <td><strong><?= e($o['nome']) ?></strong></td>
            <td><?= formatStatus($o['status'] ?? 'ativo') ?></td>
            <td>
                <div style="display:flex; gap:6px; justify-content:center">
                    <a href="<?= BASE_URL ?>/modules/olts/view.php?id=<?= $o['id'] ?>" class="btn btn-icon btn-secondary" title="Ver Portas PON"><i class="fas fa-eye"></i></a>
                    <a href="<?= BASE_URL ?>/modules/olts/edit.php?id=<?= $o['id'] ?>" class="btn btn-icon btn-primary" title="Editar OLT"><i class="fas fa-edit"></i></a>
                    
                    <!-- Lixeira Única e Funcional Corrigida -->
                    <button class="btn btn-icon btn-danger" title="Excluir OLT" onclick="if(confirm('Deseja excluir definitivamente esta OLT e todas as suas portas PON?')) { fetch('../../api/elements.php?type=delete_olt&id=<?php echo $o['id']; ?>', {method:'DELETE'}).then(r=>r.json()).then(res=>{ if(res.success) { alert('OLT removida com sucesso!'); window.location.reload(); } else { alert(res.error || 'Erro ao excluir'); } }); }"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
<?php tableClose() ?>
</div>
<?php 
// Inclui o rodapé oficial do sistema para fechar as tags HTML
include __DIR__ . '/../../includes/footer.php'; 
?>
