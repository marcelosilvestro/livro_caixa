<?php
require_once __DIR__ . '/../config.php';

lc_exigir_post();

$data_ref = trim($_POST['data_ref'] ?? '');

if (!valida_data($data_ref)) json_saida(false, 'Data inválida.');

// Carrega o caixa do dia
$stmt = $pdo->prepare("SELECT * FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_ref]);
$caixa = $stmt->fetch();

if (!$caixa)                         json_saida(false, 'Caixa não encontrado para esta data.');
if ($caixa['status'] === 'fechado')  json_saida(false, 'O caixa deste dia já está fechado.');

// Bloqueia se o caixa do dia anterior estiver aberto
$data_anterior = date('Y-m-d', strtotime($data_ref . ' -1 day'));
$stmt = $pdo->prepare("SELECT status FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_anterior]);
$caixa_anterior = $stmt->fetch();

if ($caixa_anterior && $caixa_anterior['status'] !== 'fechado') {
    json_saida(false, 'O caixa do dia ' . date('d/m/Y', strtotime($data_anterior)) . ' ainda está aberto. Feche-o antes de continuar.');
}

// Calcula totais ao vivo no momento do fechamento
$totais      = get_totais_entradas($pdo, $data_ref);
$total_saidas = get_total_saidas($pdo, $data_ref);

$valor_abertura = (float) $caixa['valor_abertura'];
$valor_sangria  = (float) $caixa['valor_sangria'];

// Saldo = abertura + dinheiro físico - saídas - sangria (PIX/boleto/cartão não entram no caixa físico)
$saldo_fechamento = $valor_abertura
                    + $totais['total_dinheiro']
                    - $total_saidas
                    - $valor_sangria;

$stmt = $pdo->prepare("
    UPDATE tab_caixa_diario SET
        total_dinheiro       = ?,
        total_entradas_geral = ?,
        total_saidas         = ?,
        saldo_fechamento     = ?,
        status               = 'fechado'
    WHERE data_referencia = ?
");
$stmt->execute([
    $totais['total_dinheiro'],
    $totais['valor_total_geral'],
    $total_saidas,
    $saldo_fechamento,
    $data_ref,
]);

// Propaga saldo em dinheiro como valor_abertura do dia seguinte
$data_seguinte = date('Y-m-d', strtotime($data_ref . ' +1 day'));

$stmt = $pdo->prepare("SELECT id FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_seguinte]);
$proximo = $stmt->fetch();

if ($proximo) {
    // Registro já existe — só atualiza o valor_abertura se o caixa ainda estiver aberto
    $pdo->prepare("
        UPDATE tab_caixa_diario
        SET valor_abertura = ?
        WHERE data_referencia = ? AND status = 'aberto'
    ")->execute([$saldo_fechamento, $data_seguinte]);
} else {
    // Cria o registro do dia seguinte com o saldo de hoje como abertura
    $pdo->prepare("
        INSERT INTO tab_caixa_diario (data_referencia, valor_abertura, valor_sangria, status)
        VALUES (?, ?, 0.00, 'aberto')
    ")->execute([$data_seguinte, $saldo_fechamento]);
}

json_saida(true, 'Caixa fechado com sucesso.', [
    'saldo_fechamento' => $saldo_fechamento,
    'proximo_dia'      => $data_seguinte,
    'abertura_proximo' => $saldo_fechamento,
]);
