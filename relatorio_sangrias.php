<?php
require_once __DIR__ . '/config.php';

$mesSelecionado = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$anoSelecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

// Lista de Sangrias do mês
$stmt = $pdo->prepare("
    SELECT data_referencia, valor_sangria 
    FROM tab_caixa_diario 
    WHERE MONTH(data_referencia) = ? 
      AND YEAR(data_referencia) = ? 
      AND valor_sangria > 0
    ORDER BY data_referencia ASC
");
$stmt->execute([$mesSelecionado, $anoSelecionado]);
$sangrias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalSangrias = 0;
foreach ($sangrias as $s) {
    $totalSangrias += (float)$s['valor_sangria'];
}

$mesesNomes = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$nomeMes = $mesesNomes[$mesSelecionado];

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0">

    <!-- Cabeçalho -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px; background: #fff; padding: 10px 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
        
        <!-- Título -->
        <div style="min-width: 200px;">
            <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">Relat&oacute;rio de Sangrias</h2>
            <div style="font-size: 12px; color: #6b7280; font-weight: 500;">
                M&ecirc;s: <?= $nomeMes ?> de <?= $anoSelecionado ?>
            </div>
        </div>

        <!-- Botões Direita -->
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button class="lc-btn-outline" onclick="window.history.back()" style="height: 32px; padding: 0 15px; font-size: 12px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                &larr; Voltar
            </button>
        </div>
    </div>

    <!-- Área Principal -->
    <div style="max-width: 800px; margin: 0 auto;">
        
        <!-- Card Resumo -->
        <div class="lc-card" style="margin-bottom: 15px; background: #fdf0ef; border: 1px solid #fadbd8; border-radius: 6px; padding: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; border-left: 4px solid #e74c3c;">
            <div style="font-size: 12px; font-weight: 700; color: #c0392b; text-transform: uppercase;">Total de Sangrias no M&ecirc;s</div>
            <div style="font-size: 28px; font-weight: 900; color: #c0392b; margin-top: 5px;">R$ <?= number_format($totalSangrias, 2, ',', '.') ?></div>
        </div>

        <!-- Tabela -->
        <div class="lc-section-panel">
            <div class="lc-section-header">
                <span class="lc-section-title saida-color"><i class="bi bi-dash-circle"></i> Sangrias Registradas</span>
                <span class="lc-badge-count"><?= count($sangrias) ?></span>
            </div>
            <div class="lc-table-wrap">
                <?php if (count($sangrias) === 0): ?>
                    <div style="padding: 40px; text-align: center; color: #777; font-size: 14px;">
                        Nenhuma sangria registrada neste m&ecirc;s.
                    </div>
                <?php else: ?>
                    <table class="lc-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th style="text-align: right;">Valor da Sangria</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sangrias as $s): 
                                $dataBr = date('d/m/Y', strtotime($s['data_referencia']));
                                $valorFmt = number_format((float)$s['valor_sangria'], 2, ',', '.');
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: #333;"><?= $dataBr ?></div>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #c0392b;">
                                    R$ <?= $valorFmt ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

</div>

<div style="position: fixed; bottom: 10px; right: 15px; font-size: 11px; color: #9ca3af; z-index: 9999; font-family: 'Inter', sans-serif; pointer-events: none;">
    By Marcelo Silvestro
</div>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js.hhvm"></script>

</body>
</html>
