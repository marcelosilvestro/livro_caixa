<?php
require_once __DIR__ . '/../config.php';

lc_exigir_post();

$data_ref      = trim($_POST['data_ref']      ?? '');
$valor_sangria = (float) ($_POST['valor_sangria'] ?? 0);

if (!valida_data($data_ref)) json_saida(false, 'Data inválida.');
if ($valor_sangria < 0)      json_saida(false, 'Valor de sangria não pode ser negativo.');

// Verifica se o caixa está aberto
$stmt = $pdo->prepare("SELECT status FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_ref]);
$caixa = $stmt->fetch();

if (!$caixa)                         json_saida(false, 'Caixa não encontrado para esta data.');
if ($caixa['status'] === 'fechado')  json_saida(false, 'Caixa fechado. Não é possível registrar sangria.');

$stmt = $pdo->prepare("UPDATE tab_caixa_diario SET valor_sangria = ? WHERE data_referencia = ?");
$stmt->execute([$valor_sangria, $data_ref]);

json_saida(true, 'Sangria registrada com sucesso.');
