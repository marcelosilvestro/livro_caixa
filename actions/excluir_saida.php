<?php
require_once __DIR__ . '/../config.php';

lc_exigir_post();

$id       = (int) ($_POST['id']       ?? 0);
$data_ref = trim($_POST['data_ref']   ?? '');

if ($id <= 0)               json_saida(false, 'ID inválido.');
if (!valida_data($data_ref)) json_saida(false, 'Data inválida.');

// Verifica se o caixa está aberto
$stmt = $pdo->prepare("SELECT status FROM tab_caixa_diario WHERE data_referencia = ?");
$stmt->execute([$data_ref]);
$caixa = $stmt->fetch();

if (!$caixa)                         json_saida(false, 'Caixa não encontrado para esta data.');
if ($caixa['status'] === 'fechado')  json_saida(false, 'Caixa fechado. Não é possível excluir saídas.');

// Confirma que a saída pertence ao dia correto
$stmt = $pdo->prepare("SELECT id FROM tab_saidas WHERE id = ? AND DATE(data) = ?");
$stmt->execute([$id, $data_ref]);
if (!$stmt->fetch()) json_saida(false, 'Saída não encontrada para esta data.');

$stmt = $pdo->prepare("DELETE FROM tab_saidas WHERE id = ?");
$stmt->execute([$id]);

json_saida(true, 'Saída excluída com sucesso.');
