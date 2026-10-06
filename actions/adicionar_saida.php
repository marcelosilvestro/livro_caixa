<?php
require_once __DIR__ . '/../config.php';

lc_exigir_post();

$data_ref   = trim($_POST['data_ref']   ?? '');
$categoria  = trim($_POST['categoria']  ?? '');
$descricao  = trim($_POST['descricao']  ?? '');
$id_saida   = trim($_POST['id_saida']   ?? '');
$valor      = (float) ($_POST['valor']  ?? 0);

if (!valida_data($data_ref))  json_saida(false, 'Data inválida.');
if (empty($categoria))        json_saida(false, 'Categoria obrigatória.');
if (empty($descricao))        json_saida(false, 'Descrição obrigatória.');
if ($valor <= 0)              json_saida(false, 'Valor deve ser maior que zero.');

// Verifica se o caixa do dia existe e está aberto
$stmt = $pdo->prepare("SELECT status FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_ref]);
$caixa = $stmt->fetch();

if (!$caixa)                         json_saida(false, 'Caixa não encontrado para esta data.');
if ($caixa['status'] === 'fechado')  json_saida(false, 'Caixa fechado. Não é possível adicionar ou editar saídas.');

$data_hora = $data_ref . ' ' . date('H:i:s');

if (!empty($id_saida)) {
    // Editar saída existente
    $stmt = $pdo->prepare("
        UPDATE tab_saidas 
        SET categoria = ?, descricao = ?, valor = ?, usuario = ?
        WHERE id = ? AND DATE(data) = ?
    ");
    $stmt->execute([$categoria, $descricao, $valor, $usuario_logado, $id_saida, $data_ref]);
    json_saida(true, 'Saída editada com sucesso.');
} else {
    // Adicionar nova saída
    $stmt = $pdo->prepare("
        INSERT INTO tab_saidas (data, categoria, descricao, observacao, valor, usuario)
        VALUES (?, ?, ?, '', ?, ?)
    ");
    $stmt->execute([$data_hora, $categoria, $descricao, $valor, $usuario_logado]);
    json_saida(true, 'Saída registrada com sucesso.');
}
