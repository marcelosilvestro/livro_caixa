<?php
require_once __DIR__ . '/config.php';

$data_ref = trim($_GET['data'] ?? date('Y-m-d'));
if (!valida_data($data_ref)) die('Data inválida.');

$caixa       = get_ou_criar_caixa($pdo, $data_ref);
$totais_live = get_totais_entradas($pdo, $data_ref);
$saidas_live = get_total_saidas($pdo, $data_ref);

if ($caixa['status'] === 'fechado') {
    $totais = [
        'total_pix'         => (float) $totais_live['total_pix'],
        'total_dinheiro'    => (float) $caixa['total_dinheiro'],
        'total_boleto'      => (float) $totais_live['total_boleto'],
        'total_cartao'      => (float) $totais_live['total_cartao'],
        'total_outros'      => (float) $totais_live['total_outros'],
        'valor_total_geral' => (float) $caixa['total_dinheiro'] + (float) $totais_live['total_pix'] + (float) $totais_live['total_boleto'] + (float) $totais_live['total_cartao'] + (float) $totais_live['total_outros'],
    ];
    $total_saidas = (float) $caixa['total_saidas'];
    $saldo        = (float) $caixa['saldo_fechamento'];
} else {
    $totais       = $totais_live;
    $total_saidas = $saidas_live;
    $saldo        = (float) $caixa['valor_abertura'] + $totais['total_dinheiro'] - $total_saidas - (float) $caixa['valor_sangria'];
}

// Captura parâmetros de ordenação da URL, com fallback para padrão
$sortCol = $_GET['sortCol'] ?? 'hora';
$sortDir = strtolower($_GET['sortDir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// Mapeia colunas do frontend para colunas do banco de dados
$mapCols = [
    'nome'       => 'c.nome',
    'datavenc'   => 'l.datavenc',
    'coletor'    => 'l.coletor',
    'forma'      => 'l.formapag',
    'valor'      => 'l.valorpag',
    'hora'       => 'l.datapag'
];
$orderByCol = $mapCols[$sortCol] ?? 'l.datapag';

// Lista de Entradas
// Removi a ordenação dinâmica vinda do frontend. Agora sempre ordena por Forma (formapag) primeiro, depois pela hora (datapag).
$stmt = $pdo->prepare("
    SELECT l.id AS id_lanc, c.nome, l.login, l.formapag, l.fcartaobandeira, l.valorpag, l.datapag, l.datavenc, l.coletor
    FROM sis_lanc AS l
    JOIN sis_cliente AS c ON c.login = l.login 
    WHERE l.datapag >= ? AND l.datapag <= ? 
    ORDER BY l.formapag ASC, l.datapag ASC
");
$stmt->execute([$data_ref . ' 00:00:00', $data_ref . ' 23:59:59']);
$entradas = $stmt->fetchAll();

// Lista de Saídas
$stmt = $pdo->prepare("
    SELECT id, data, categoria, descricao, observacao, valor, usuario 
    FROM tab_saidas 
    WHERE DATE(data) = ? 
    ORDER BY data
");
$stmt->execute([$data_ref]);
$saidas = $stmt->fetchAll();

$total_banco = $totais['total_pix'] + $totais['total_boleto'] + $totais['total_cartao'];

function fmtMoeda($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório do Caixa - <?= date('d/m/Y', strtotime($data_ref)) ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 12px; 
            color: #333; 
            margin: 0; 
            padding: 20px; 
            background: #f4f5f7;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1, h2, h3 { margin: 0 0 10px 0; color: #111; }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #000; 
            padding-bottom: 15px; 
        }
        .header h1 { font-size: 24px; text-transform: uppercase; letter-spacing: 1px; }
        .header h2 { font-size: 14px; color: #555; font-weight: normal; }
        
        .resumo-grid { 
            display: flex; 
            flex-wrap: nowrap; /* Não quebrar a linha */
            gap: 10px; 
            margin-bottom: 30px; 
        }
        .resumo-box { 
            border: 1px solid #e2e4e9; 
            padding: 10px 5px; 
            flex: 1 1 0; /* Divide o espaço de forma igual para todos */
            text-align: center; 
            border-radius: 8px; 
            background: #fafafa;
        }
        .resumo-box strong { 
            display: block; 
            font-size: 9px; 
            text-transform: uppercase; 
            color: #777; 
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .resumo-box span { 
            font-size: 15px; 
            font-weight: bold; 
            color: #222;
            white-space: nowrap;
        }
        .resumo-box.highlight {
            background: #eef5f9;
            border-color: #bcd0e0;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 30px; 
            font-size: 11px; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 4px 6px; 
            text-align: left; 
        }
        th { 
            background-color: #f4f4f4; 
            font-weight: bold; 
            text-transform: uppercase; 
            font-size: 10px; 
            color: #555;
        }
        tr:nth-child(even) { background-color: #fcfcfc; }
        .val { text-align: right; white-space: nowrap; font-weight: bold;}
        .td-sub { color: #777; font-size: 10px; display: block; margin-top: 2px;}

        .totais-bottom { 
            display: flex; 
            justify-content: flex-end; 
            gap: 30px; 
            margin-top: 30px; 
            border-top: 2px solid #000; 
            padding-top: 20px; 
        }
        .totais-bottom .box { text-align: right; }
        .totais-bottom .box strong { 
            font-size: 11px; 
            color: #666; 
            text-transform: uppercase; 
            display: block; 
            margin-bottom: 5px;
        }
        .totais-bottom .box span { font-size: 22px; font-weight: bold; color: #222; }
        
        .no-print { 
            text-align: center; 
            margin-top: 30px; 
            padding-top: 20px;
            border-top: 1px dashed #ccc;
        }
        .btn-print {
            background: #3266ad;
            color: #fff;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            text-transform: uppercase;
        }
        
        @media print {
            body { background: #fff; padding: 0; }
            .container { box-shadow: none; padding: 0; max-width: 100%; }
            .no-print { display: none; }
            .resumo-box { border: 1px solid #000; background: #fff !important; }
            th { background-color: #eee !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="container">
        <div class="header">
            <h1>Relatório de Caixa Diário</h1>
            <h2>
                <strong>Data:</strong> <?= date('d/m/Y', strtotime($data_ref)) ?> &nbsp;|&nbsp; 
                <strong>Status do Caixa:</strong> <?= strtoupper($caixa['status']) ?>
            </h2>
        </div>

        <!-- Cards do Topo -->
        <div class="resumo-grid">
            <div class="resumo-box highlight">
                <strong>Total no Banco</strong>
                <span><?= fmtMoeda($total_banco) ?></span>
            </div>
            <div class="resumo-box highlight">
                <strong>Total em Dinheiro</strong>
                <span style="color:#1d9e75;"><?= fmtMoeda($totais['total_dinheiro']) ?></span>
            </div>
            <div class="resumo-box">
                <strong>PIX</strong>
                <span><?= fmtMoeda($totais['total_pix']) ?></span>
            </div>
            <div class="resumo-box">
                <strong>Boleto</strong>
                <span><?= fmtMoeda($totais['total_boleto']) ?></span>
            </div>
            <div class="resumo-box">
                <strong>Cartão</strong>
                <span><?= fmtMoeda($totais['total_cartao']) ?></span>
            </div>
            <?php if ($totais['total_outros'] > 0): ?>
            <div class="resumo-box">
                <strong>Outros</strong>
                <span><?= fmtMoeda($totais['total_outros']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Lista de Entradas -->
        <h3>Entradas do Dia (<?= count($entradas) ?>) - <?= fmtMoeda($totais['valor_total_geral']) ?></h3>
        <table>
            <thead>
                <tr>
                    <th>Nome / Login</th>
                    <th>Vencimento</th>
                    <th>Coletor</th>
                    <th>Forma</th>
                    <th>Hora</th>
                    <th class="val">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entradas)): ?>
                <tr><td colspan="6" style="text-align:center; padding: 15px;">Nenhuma entrada registrada neste dia.</td></tr>
                <?php else: ?>
                    <?php foreach ($entradas as $e): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($e['nome']) ?></strong>
                            <span class="td-sub"><?= htmlspecialchars($e['login']) ?></span>
                        </td>
                        <td><?= $e['datavenc'] ? htmlspecialchars(date('d/m/Y', strtotime($e['datavenc']))) : '-' ?></td>
                        <td><?= htmlspecialchars($e['coletor'] ?: '-') ?></td>
                        <td>
                            <?= htmlspecialchars(strtoupper($e['formapag'])) ?>
                            <?php if (strtolower($e['formapag']) === 'cartao' && !empty($e['fcartaobandeira'])): ?>
                                <br><span class="td-sub" style="margin-top:0;"><?= htmlspecialchars($e['fcartaobandeira']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('H:i', strtotime($e['datapag'])) ?></td>
                        <td class="val"><?= fmtMoeda($e['valorpag']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Lista de Saídas -->
        <h3>Saídas do Dia (<?= count($saidas) ?>) - <?= fmtMoeda($total_saidas) ?></h3>
        <table>
            <thead>
                <tr>
                    <th>Categoria</th>
                    <th>Descrição</th>
                    <th>Usuário</th>
                    <th class="val">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($saidas)): ?>
                <tr><td colspan="4" style="text-align:center; padding: 15px;">Nenhuma saída registrada neste dia.</td></tr>
                <?php else: ?>
                    <?php foreach ($saidas as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['categoria']) ?></td>
                        <td><?= htmlspecialchars($s['descricao']) ?></td>
                        <td><?= htmlspecialchars($s['usuario']) ?></td>
                        <td class="val"><?= fmtMoeda($s['valor']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totais do Bottom -->
        <div class="totais-bottom">
            <div class="box">
                <strong>Caixa Inicial</strong>
                <span><?= fmtMoeda($caixa['valor_abertura']) ?></span>
            </div>
            <div class="box">
                <strong>Sangria</strong>
                <span style="color:#c0392b;"><?= fmtMoeda($caixa['valor_sangria']) ?></span>
            </div>
            <div class="box">
                <strong>Saldo em Dinheiro</strong>
                <span style="color:#0f7a50;"><?= fmtMoeda($saldo) ?></span>
            </div>
        </div>
        
        <!-- Botão para reimprimir -->
        <div class="no-print">
            <button class="btn-print" onclick="window.print()">
                Imprimir Documento / Salvar PDF
            </button>
        </div>
    </div>
</body>
</html>