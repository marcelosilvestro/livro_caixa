<?php
require_once __DIR__ . '/config.php';

/* ================================================================
   AJAX — Checar Sessão Simples
   ================================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_sessao') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => true]);
    exit;
}

/* ================================================================
   AJAX — Carrega dados de conferência filtrando pela Data de Compensação
   ================================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dados') {
    ob_clean();
    header('Content-Type: application/json');
    
    $dtInicioStr = $_GET['data_inicio'] ?? date('Y-m-d');
    $dtFimStr    = $_GET['data_fim'] ?? date('Y-m-d');
    
    if (!valida_data($dtInicioStr) || !valida_data($dtFimStr)) {
        echo json_encode(['status' => false, 'msg' => 'Data inválida.']);
        exit;
    }
    
    // Feriados do ano (buscando do ano anterior até o próximo para segurança)
    $anoIni = date('Y', strtotime($dtInicioStr)) - 1;
    $anoFim = date('Y', strtotime($dtFimStr)) + 1;
    
    $stmt = $pdo->prepare("SELECT data FROM tab_feriados WHERE YEAR(data) >= ? AND YEAR(data) <= ? AND tipo = 'feriado'");
    $stmt->execute([$anoIni, $anoFim]);
    $feriadosDb = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $feriadosMap = array_flip($feriadosDb); // Busca ultra-rápida (O(1))
    
    // Função para calcular D+1 Útil (Boleto, Cartão)
    function calcularD1($dataPagamento, $feriadosMap) {
        $timestamp = strtotime(substr($dataPagamento, 0, 10)); // ignora a hora do pagamento
        do {
            $timestamp = strtotime('+1 day', $timestamp);
            $diaSemana = date('w', $timestamp); // 0=Dom, 6=Sáb
            $dataIso = date('Y-m-d', $timestamp);
            $isFimSemana = ($diaSemana == 0 || $diaSemana == 6);
            $isFeriado = isset($feriadosMap[$dataIso]);
        } while ($isFimSemana || $isFeriado);
        return $dataIso;
    }
    
    // Função para calcular D+0 Útil (PIX)
    function calcularD0($dataPagamento, $feriadosMap) {
        $timestamp = strtotime(substr($dataPagamento, 0, 10));
        $diaSemana = date('w', $timestamp);
        $dataIso = date('Y-m-d', $timestamp);
        $isFimSemana = ($diaSemana == 0 || $diaSemana == 6);
        $isFeriado = isset($feriadosMap[$dataIso]);
        
        while ($isFimSemana || $isFeriado) {
            $timestamp = strtotime('+1 day', $timestamp);
            $diaSemana = date('w', $timestamp);
            $dataIso = date('Y-m-d', $timestamp);
            $isFimSemana = ($diaSemana == 0 || $diaSemana == 6);
            $isFeriado = isset($feriadosMap[$dataIso]);
        }
        return $dataIso;
    }

    // Como filtramos pela Data de COMPENSAÇÃO, o pagamento real aconteceu ANTES.
    // Puxamos até 15 dias para trás para garantir que pegamos pagamentos feitos antes de feriados prolongados.
    $dtBuscaInicio = date('Y-m-d', strtotime($dtInicioStr . ' -15 days')); 
    
    // Filtros de Forma de Pagamento
    $chkPix = isset($_GET['pix']) ? (int)$_GET['pix'] : 1;
    $chkBoleto = isset($_GET['boleto']) ? (int)$_GET['boleto'] : 1;
    $chkCartao = isset($_GET['cartao']) ? (int)$_GET['cartao'] : 1;

    $formasPermitidas = [];
    if ($chkPix) $formasPermitidas[] = "'pix'";
    if ($chkBoleto) $formasPermitidas[] = "'boleto'";
    if ($chkCartao) $formasPermitidas[] = "'cartao'";
    
    $resultados = [];
    $totais = ['pix' => 0, 'boleto' => 0, 'cartao' => 0, 'geral' => 0];
    
    if (empty($formasPermitidas)) {
        // Busca dicionário mesmo sem lançamentos
        $stmtMap = $pdo->query("SELECT nome_banco, login_mkauth FROM tab_conciliacao_nomes");
        $dicionarioNomes = [];
        while ($row = $stmtMap->fetch(PDO::FETCH_ASSOC)) {
            $nb = strtoupper($row['nome_banco']);
            if (!isset($dicionarioNomes[$nb])) {
                $dicionarioNomes[$nb] = [];
            }
            $dicionarioNomes[$nb][] = $row['login_mkauth']; // Agora é um array de logins
        }
        echo json_encode(['status' => true, 'totais' => $totais, 'entradas' => [], 'dicionario' => $dicionarioNomes]);
        exit;
    }
    
    $formasSql = implode(',', $formasPermitidas);

    $stmt = $pdo->prepare("
        SELECT l.id AS id_lanc, c.nome, l.login, l.formapag, l.fcartaobandeira, l.valorpag, l.datapag, l.referencia, l.coletor, l.nossonum
        FROM sis_lanc AS l
        JOIN sis_cliente AS c ON c.login = l.login
        WHERE l.datapag >= ? AND l.datapag <= ?
          AND l.formapag IN ($formasSql)
        ORDER BY l.datapag ASC
    ");
    // O limite final de pagamento não pode ser maior que o limite final de compensação.
    $stmt->execute([$dtBuscaInicio . ' 00:00:00', $dtFimStr . ' 23:59:59']);
    $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lancamentos as $l) {
        $forma = strtolower(trim($l['formapag']));
        
        if ($forma === 'pix') {
            $compDate = calcularD0($l['datapag'], $feriadosMap);
        } elseif ($forma === 'boleto') {
            $compDate = calcularD0($l['datapag'], $feriadosMap); // Mudança para D+0 conforme solicitado
        } else {
            $compDate = calcularD1($l['datapag'], $feriadosMap);
        }
        
        // Mantém apenas os que compensaram DENTRO do período selecionado
        if ($compDate >= $dtInicioStr && $compDate <= $dtFimStr) {
            $l['data_compensacao'] = $compDate;
            // Variável de controle para o Frontend saber que ainda não tem match
            $l['conciliado'] = false; 
            
            $resultados[] = $l;
            
            $val = (float) $l['valorpag'];
            $forma = strtolower(trim($l['formapag'])); // Normaliza a forma de pagamento para evitar erros de case/espaço
            
            if ($forma === 'pix') $totais['pix'] += $val;
            elseif ($forma === 'boleto') $totais['boleto'] += $val;
            elseif ($forma === 'cartao') $totais['cartao'] += $val;
        }
    }
    
    // Busca dicionário de aprendizado de nomes
    $stmtMap = $pdo->query("SELECT nome_banco, login_mkauth FROM tab_conciliacao_nomes");
    $dicionarioNomes = [];
    while ($row = $stmtMap->fetch(PDO::FETCH_ASSOC)) {
        $nb = strtoupper($row['nome_banco']);
        if (!isset($dicionarioNomes[$nb])) {
            $dicionarioNomes[$nb] = [];
        }
        $dicionarioNomes[$nb][] = $row['login_mkauth']; // Agora é um array de logins
    }
    
    echo json_encode([
        'status' => true,
        'totais' => $totais,
        'entradas' => $resultados,
        'dicionario' => $dicionarioNomes
    ]);
    exit;
}

/* ================================================================
   AJAX — Salva novo aprendizado de conciliação
   ================================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'aprender_nome') {
    ob_clean();
    lc_exigir_post();
    header('Content-Type: application/json');
    $nome_banco = $_POST['nome_banco'] ?? '';
    $logins_mkauth = json_decode($_POST['logins_mkauth'] ?? '[]', true);
    
    if (empty($nome_banco) || empty($logins_mkauth)) {
        echo json_encode(['status' => false, 'msg' => 'Dados incompletos']);
        exit;
    }
    
    try {
        $nome_fmt = strtoupper(trim($nome_banco));
        
        $stmtCheck = $pdo->prepare("SELECT 1 FROM tab_conciliacao_nomes WHERE nome_banco = ? AND login_mkauth = ?");
        $stmtInsert = $pdo->prepare("INSERT INTO tab_conciliacao_nomes (nome_banco, login_mkauth) VALUES (?, ?)");
        
        foreach ($logins_mkauth as $login) {
            $login_fmt = trim($login);
            $stmtCheck->execute([$nome_fmt, $login_fmt]);
            if (!$stmtCheck->fetch()) {
                $stmtInsert->execute([$nome_fmt, $login_fmt]);
            }
        }
        
        echo json_encode(['status' => true, 'msg' => 'Aprendizado salvo com sucesso!']);
    } catch (Exception $e) {
        error_log('livro_caixa: ' . $e->getMessage());
        echo json_encode(['status' => false, 'msg' => 'Erro ao gravar. Tente de novo.']);
    }
    exit;
}

/* ================================================================
   AJAX — Desligar nome aprendido
   ================================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'desligar_nome') {
    ob_clean();
    lc_exigir_post();
    header('Content-Type: application/json');
    $nome_banco = $_POST['nome_banco'] ?? '';
    
    if (empty($nome_banco)) {
        echo json_encode(['status' => false, 'msg' => 'Nome do banco não informado']);
        exit;
    }
    
    try {
        $nome_fmt = strtoupper(trim($nome_banco));
        $stmt = $pdo->prepare("DELETE FROM tab_conciliacao_nomes WHERE nome_banco = ?");
        $stmt->execute([$nome_fmt]);
        
        echo json_encode(['status' => true, 'msg' => 'Ligação removida com sucesso!']);
    } catch (Exception $e) {
        error_log('livro_caixa: ' . $e->getMessage());
        echo json_encode(['status' => false, 'msg' => 'Erro ao remover. Tente de novo.']);
    }
    exit;
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0">

    <!-- Cabeçalho: Título, Filtros e Botões -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px; background: #fff; padding: 10px 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
        
        <!-- Título -->
        <div style="min-width: 200px;">
            <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">Conferência Bancária</h2>
            <div style="font-size: 12px; color: #6b7280; margin-top: 2px; font-weight: 500;">(Compensação: D+1 Útil)</div>
        </div>

        <!-- Filtros Centralizados -->
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap; flex: 1; justify-content: center;">
            <!-- Formas de Pagamento -->
            <div style="display: flex; gap: 10px; align-items: center; margin-right: 15px;">
                <span style="font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Formas:</span>
                <label class="lc-checkbox-label"><input type="radio" name="forma_pagamento" id="chkPix" value="pix" checked onchange="carregarDados()"> PIX</label>
                <label class="lc-checkbox-label"><input type="radio" name="forma_pagamento" id="chkCartao" value="cartao" onchange="carregarDados()"> Cartão</label>
                <label class="lc-checkbox-label"><input type="radio" name="forma_pagamento" id="chkBoleto" value="boleto" onchange="carregarDados()"> Boleto</label>
            </div>

            <div class="lc-filter-divider" style="height: 24px; margin: 0;"></div>

            <!-- Período -->
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Período:</span>
                <input type="date" id="dataInicio" class="lc-input-date" value="<?= date('Y-m-d') ?>" style="width: 105px;">
                <span style="font-size: 12px; color: #6b7280;">até</span>
                <input type="date" id="dataFim" class="lc-input-date" value="<?= date('Y-m-d') ?>" style="width: 105px;">
                <button class="lc-btn-black" onclick="carregarDados()">
                    <i class="bi bi-funnel"></i> Filtrar
                </button>
            </div>
        </div>

        <!-- Botões Direita e Card Dinâmico -->
        <div style="display: flex; gap: 15px; align-items: center; justify-content: flex-end;">
            <button class="lc-btn-outline" onclick="window.location.href='../calendario/index.php?voltar=../livro_caixa/conferencia.php'" style="height: 32px; padding: 0 10px; font-size: 12px;">
                <i class="bi bi-calendar-event"></i> Calendário
            </button>
            
            <!-- Card Dinâmico -->
            <div id="dinamicoCardContainer" class="lc-card" style="padding: 8px 16px; margin: 0; min-width: 250px; border: 2px solid #3266ad; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); background: #f8fafc;">
                <div style="display:flex; justify-content: space-between; align-items: center; gap: 12px;">
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <div id="dinamicoCardIconBox" style="background:#3266ad; color:#fff; width: 28px; height: 28px; font-size: 14px; display:flex; align-items:center; justify-content:center; border-radius:6px; flex-shrink: 0;">
                            <i id="dinamicoCardIcon" class="bi bi-qr-code-scan"></i>
                        </div>
                        <div id="dinamicoCardTitle" style="font-size:11px; font-weight:800; color:#3266ad; text-transform:uppercase; white-space: nowrap; letter-spacing: 0.5px;">PIX COMPENSADO</div>
                    </div>
                    <div id="dinamicoTotMkauth" style="font-size:18px; font-weight:900; color:#111827; white-space: nowrap;">R$ 0,00</div>
                </div>
                <div id="dinamicoTotExtrato" style="font-size:12px; color:#4b5563; font-weight:600; white-space: nowrap; margin-top: 6px; padding-left: 38px; text-align: left;">Extrato: -</div>
            </div>
        </div>
    </div>

    <!-- Tabela de Conferência (Lado a Lado após importação) -->
    <!-- Alerta de Modo de Ligação (Fica oculto por padrão) -->
    <div id="alertaModoLigacao" style="display: none; background-color: #fff3cd; border: 1px solid #ffe69c; color: #856404; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-info-circle-fill" style="font-size: 18px;"></i>
            <span style="font-size: 13px; font-weight: 600;">Modo Ligação Manual Ativo:</span>
            <span style="font-size: 13px;" id="textoModoLigacao">Selecione um lançamento no Extrato e os correspondentes no sistema para vinculá-los.</span>
        </div>
        <button class="lc-btn-cancel" style="height: 28px; font-size: 11px; padding: 0 10px;" onclick="cancelarModoLigacao()">
            <i class="bi bi-x-circle"></i> Cancelar
        </button>
    </div>

    <div id="painelConferencia" style="display:flex; gap:15px; flex-wrap:wrap; min-height:400px;">
        
        <!-- Lado Esquerdo: Lançamentos do MKAuth -->
        <div class="lc-section-panel flex-fill" style="flex:1; min-width:350px;">
            <div class="lc-section-header">
                <span class="lc-section-title entrada-color"><i class="bi bi-hdd-network"></i> Lançamentos MKAuth (Compensando no Período)</span>
                <span class="lc-badge-count" id="badgeResultados">0</span>
            </div>
            <div class="lc-table-wrap" id="tabelaConferencia" style="min-height: 200px; display: flex; flex-direction: column;">
                <div style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <p style="color:#666; font-size:13px; margin:0;">Nenhum lançamento compensado neste período.</p>
                </div>
            </div>
        </div>

        <!-- Lado Direito: Extrato Bancário (Para PIX/Cartão) -->
        <div class="lc-section-panel flex-fill" id="painelExtrato" style="flex:1; min-width:350px; border-color:#27ae60;">
            <div class="lc-section-header" style="background-color:#e8f5e9;">
                <span class="lc-section-title" style="color:#27ae60;"><i class="bi bi-bank"></i> Extrato do Banco</span>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button id="btnLimparExtrato" class="lc-btn-cancel" style="display:none; padding:4px 8px; font-size:11px; margin-right:10px; height:auto; color: #fff; background: #e74c3c; border:none;" onclick="limparExtrato()">Limpar Extrato</button>
                    <span class="lc-badge-count" id="badgeConciliados" style="background:#27ae60;" title="Conciliados">0</span>
                    <span class="lc-badge-count" id="badgePendentes" style="background:#f39c12;" title="Pendentes">0</span>
                </div>
            </div>
            <div class="lc-table-wrap" id="tabelaExtrato" style="min-height: 200px; display: flex; flex-direction: column;">
                <div id="boxImportacaoCentro" style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <i class="bi bi-file-earmark-excel" style="font-size:40px; color:#27ae60; margin-bottom:10px; display:block;"></i>
                    <p style="color:#666; font-size:13px; margin-bottom:20px;">Nenhum extrato importado para este período.</p>
                    <div style="display:flex; gap:10px; justify-content:center;">
                        <label class="lc-upload-box" for="fileXls" style="width: 140px; justify-content: center; min-width: 140px;">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                            <span id="fileNameDisplay">Selecionar<br>Extrato</span>
                        </label>
                        <input type="file" id="fileXls" accept=".xls,.xlsx" style="display: none;" onchange="document.getElementById('fileNameDisplay').innerHTML = this.files[0] ? this.files[0].name : 'Selecionar<br>Extrato';">
                        
                        <button class="lc-btn-green" onclick="importarXls()" style="width: 140px; justify-content: center; padding: 0 10px;">
                            <i class="bi bi-arrow-repeat"></i> Importar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lado Direito: Títulos do Banco (Para Boleto) -->
        <div class="lc-section-panel flex-fill" id="painelBoletos" style="display:none; flex:1; min-width:350px; border-color:#3498db;">
            <div class="lc-section-header" style="background-color:#eaf8fb;">
                <span class="lc-section-title" style="color:#3498db;"><i class="bi bi-upc-scan"></i> Títulos do Banco (Boletos)</span>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button id="btnLimparBoletos" class="lc-btn-cancel" style="display:none; padding:4px 8px; font-size:11px; margin-right:10px; height:auto; color: #fff; background: #e74c3c; border:none;" onclick="limparBoletos()">Limpar Títulos</button>
                    <span class="lc-badge-count" id="badgeBoletosConciliados" style="background:#3498db;" title="Conciliados">0</span>
                    <span class="lc-badge-count" id="badgeBoletosPendentes" style="background:#f39c12;" title="Pendentes">0</span>
                </div>
            </div>
            <div class="lc-table-wrap" id="tabelaBoletos" style="min-height: 200px; display: flex; flex-direction: column;">
                <div id="boxImportacaoBoletosCentro" style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <i class="bi bi-file-earmark-spreadsheet" style="font-size:40px; color:#3498db; margin-bottom:10px; display:block;"></i>
                    <p style="color:#666; font-size:13px; margin-bottom:20px;">Nenhum relatório de boletos importado.</p>
                    <div style="display:flex; gap:10px; justify-content:center;">
                        <label class="lc-upload-box" for="fileXlsBoletos" style="width: 140px; justify-content: center; min-width: 140px;">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                            <span id="fileNameDisplayBoletos">Selecionar<br>Relatório</span>
                        </label>
                        <input type="file" id="fileXlsBoletos" accept=".xls,.xlsx" style="display: none;" onchange="document.getElementById('fileNameDisplayBoletos').innerHTML = this.files[0] ? this.files[0].name : 'Selecionar<br>Relatório';">
                        
                        <button class="lc-btn-outline" onclick="importarXlsBoletos()" style="width: 140px; justify-content: center; padding: 0 10px; color: #3498db; border-color: #3498db;">
                            <i class="bi bi-arrow-repeat"></i> Importar
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Loading Overlay -->
<div id="lcLoadingOverlay" class="lc-loading-overlay">
    <div class="lc-loading-box">
        <div class="lc-loading-spinner"></div>
        <div class="lc-loading-text">Analisando compensações...</div>
    </div>
</div>

<script src="js/vendor/sweetalert2.all.min.js"></script>
<script src="js/vendor/xlsx.full.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Apenas aguarda o usuário marcar os filtros e clicar
});

function showLoading() { document.getElementById('lcLoadingOverlay').classList.add('ativo'); }
function hideLoading() { document.getElementById('lcLoadingOverlay').classList.remove('ativo'); }

function fmtMoeda(v) {
    return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escHtml(unsafe) {
    if (!unsafe) return '';
    return (unsafe + '').replace(/[&<"']/g, function(m) {
        switch (m) { case '&': return '&amp;'; case '<': return '&lt;'; case '"': return '&quot;'; default: return '&#039;'; }
    });
}

function dataBr(dataIso) {
    if (!dataIso) return '-';
    var p = dataIso.split('-');
    if (p.length !== 3) return dataIso;
    return p[2] + '/' + p[1] + '/' + p[0];
}

function navPeriodo(delta, tipo) {
    var inInicio = document.getElementById('dataInicio');
    var inFim    = document.getElementById('dataFim');
    
    var d1 = new Date(inInicio.value + 'T00:00:00');
    var d2 = new Date(inFim.value + 'T00:00:00');
    
    if (tipo === 'dia') {
        d1.setDate(d1.getDate() + delta);
        d2.setDate(d2.getDate() + delta);
    } else if (tipo === 'mes') {
        d1.setMonth(d1.getMonth() + delta);
        d2.setMonth(d2.getMonth() + delta);
    }
    
    inInicio.value = d1.toISOString().split('T')[0];
    inFim.value = d2.toISOString().split('T')[0];
    
    carregarDados();
}

function desligarLigacao(nome_banco) {
    Swal.fire({
        title: 'Desfazer Ligação?',
        text: `Deseja desligar os lançamentos associados ao extrato "${nome_banco}" e remover do aprendizado?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sim, Desligar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            // Adiciona na lista de ignorados
            dadosAtuais.forEach(mk => {
                if (mk.conciliado && mk.conciliado_nome_banco === nome_banco) {
                    ligacoesIgnoradas.add(nome_banco + '|' + mk.id_lanc);
                }
            });
            
            // Remove do dicionario em memória
            var nomeUpper = nome_banco.toUpperCase();
            if (dicionarioConciliacao[nomeUpper]) {
                delete dicionarioConciliacao[nomeUpper];
            }
            
            // Remove do banco de dados
            var fd = new FormData();
            fd.append('nome_banco', nome_banco);
            fetch('conferencia.php?ajax=desligar_nome', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (j.sessao_expirada) {
                    window.location.href = '/admin/login.php';
                    return;
                }
            });
            
            // Reprocessa
            processarConciliacao(dadosExtratoBruto);
            
            Swal.fire({
                toast: true, position: 'bottom-end', showConfirmButton: false, timer: 3000,
                icon: 'success', title: 'Ligação desfeita e aprendizado removido!'
            });
        }
    });
}

function importarXls() {
    var fileInput = document.getElementById('fileXls');
    if (!fileInput.files.length) {
        Swal.fire('Atenção', 'Selecione um arquivo .xls ou .xlsx primeiro.', 'warning');
        return;
    }
    Swal.fire('Em Desenvolvimento', 'A funcionalidade de leitura e batimento automático de planilhas será implementada em breve.', 'info');
}

var dadosAtuais = null;
var dadosExtratoBruto = []; // Guarda todos os dados do XLS lidos
var dadosExtrato = []; // Array do banco XLS filtrado
var dicionarioConciliacao = {}; // Nomes aprendidos: { "NOME BANCO": "login_mkauth" }
var mkauthPendentes = []; // Lançamentos que ainda não deram match
var sortCol = 'status'; 
var sortDir = 'asc';  // Ascendente coloca false (0) antes de true (1), mantendo pendentes no topo

// Variáveis para conciliação manual
var extratoSelecionadoIdx = null;
var mkauthSelecionadosIds = new Set(); // Agora é um conjunto (Set) para permitir múltiplos
var ligacoesIgnoradas = new Set(); // Guarda "nome_banco|id_lanc" para não religar automático após desligar

var dadosBoletosBruto = []; // Array bruto do banco XLS de boletos
var dadosBoletos = []; // Array processado de boletos

function limparBoletos() {
    dadosBoletosBruto = [];
    dadosBoletos = [];
    
    document.getElementById('dinamicoTotExtrato').innerHTML = 'Extrato: -';
    document.getElementById('dinamicoTotExtrato').style.color = '#666';
    
    if (dadosAtuais) {
        dadosAtuais.forEach(e => {
            if (e.formapag.toLowerCase().includes('boleto')) {
                e.conciliado = false;
                e.conciliado_com = '';
                e.conciliado_nome_banco = '';
            }
        });
        renderTabela(dadosAtuais);
    }
    renderTabelaBoletos();
}

function escapeJsString(str) {
    if (!str) return '';
    return (str + '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r');
}

function podeLigar(exNomeLimpo, mkItens) {
    if (!Array.isArray(mkItens)) mkItens = [mkItens];
    return !mkItens.some(mk => ligacoesIgnoradas.has(exNomeLimpo + '|' + mk.id_lanc));
}

// Função auxiliar para remover prefixos inúteis do Extrato Sicredi
function limparNomeExtrato(descRaw) {
    var txt = (descRaw || '').toUpperCase();
    // Remove "RECEBIMENTO PIX" seguido de números e pontos/traços (CPF/CNPJ)
    txt = txt.replace(/RECEBIMENTO PIX[\s\.\-\d]+/g, '');
    // Remove outras possíveis sujeiras iniciais
    txt = txt.replace(/PAGAMENTO PIX[\s\.\-\d]+/g, '');
    txt = txt.replace(/LIQUIDACAO BOLETO[\s\.\-\d]+/g, '');
    return txt.trim();
}

    // Remove acentos e caracteres especiais para comparação
    function removerAcentos(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
    }

    // Algoritmo de Similaridade de Strings Avançado (Baseado nas palavras do Banco)
    function calcularSimilaridade(strMkauth, strBanco) {
        var sMk = removerAcentos(strMkauth).replace(/\s+/g, '');
        var sBa = removerAcentos(strBanco).replace(/\s+/g, '');
        if (sMk === sBa) return 1.0;
        if (sMk.length < 3 || sBa.length < 3) return 0.0;
        
        // Se a string inteira do banco couber perfeitamente dentro da do sistema
        if (sMk.includes(sBa)) return 0.9;
        
        // Separa as palavras e remove conectores curtos (de, da, do, e, o, a)
        var wordsMk = removerAcentos(strMkauth).split(' ').filter(w => w.length > 2);
        var wordsBa = removerAcentos(strBanco).split(' ').filter(w => w.length > 2);
        
        if (wordsBa.length === 0) return 0.0; // Se sobrou só conectivo, falha.
        
        // Verifica o PRIMEIRO NOME (Peso Máximo)
        // Se o primeiro nome do MKAuth for diferente do primeiro nome do Banco, a chance de ser a mesma pessoa cai drasticamente.
        if (wordsMk[0] !== wordsBa[0]) {
            // Em vez de zerar, reduzimos drasticamente o score final se o primeiro nome não bater.
            // Ex: "Joao Paulo" vs "Josiele Paulo" (mesmo sobrenome, mas nomes principais diferentes)
            var matchesReduzidos = 0;
            wordsBa.forEach(wBa => {
                if (wordsMk.some(wMk => wMk === wBa || wMk.includes(wBa) || wBa.includes(wMk))) {
                    matchesReduzidos++;
                }
            });
            // Penaliza em 50% se o primeiro nome for diferente.
            return (matchesReduzidos / wordsBa.length) * 0.5; 
        }

        var matches = 0;
        
        // Percorre apenas as palavras do BANCO (que costumam ser abreviadas e menores)
        wordsBa.forEach(wBa => {
            // Procura se essa palavra-chave do banco existe em algum lugar no nome do sistema
            if (wordsMk.some(wMk => wMk === wBa || wMk.includes(wBa) || wBa.includes(wMk))) {
                matches++;
            }
        });
        
        // O Score é calculado com base nas palavras do BANCO, não na média geral.
        var matchScore = matches / wordsBa.length;
        return matchScore;
    }

var mkTotais = { pix: 0, boleto: 0, cartao: 0 };

function ordenarTabela(coluna) {
    if (sortCol === coluna) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortCol = coluna;
        sortDir = 'asc';
    }
    if (dadosAtuais) renderTabela(dadosAtuais);
}

function carregarDados() {
    var dtInicio = document.getElementById('dataInicio').value;
    var dtFim    = document.getElementById('dataFim').value;
    var cPix     = document.getElementById('chkPix').checked ? 1 : 0;
    var cBoleto  = document.getElementById('chkBoleto').checked ? 1 : 0;
    var cCartao  = document.getElementById('chkCartao').checked ? 1 : 0;
    
    if (!cPix && !cBoleto && !cCartao) {
        Swal.fire({
            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
            icon: 'warning', title: 'Selecione ao menos uma Forma de Pagamento.'
        });
        return;
    }
    
    showLoading();
    
    fetch('conferencia.php?ajax=dados&data_inicio=' + dtInicio + '&data_fim=' + dtFim + '&pix=' + cPix + '&boleto=' + cBoleto + '&cartao=' + cCartao)
    .then(r => r.json())
    .then(j => {
        hideLoading();
        if (j.sessao_expirada) {
            window.location.href = '/admin/login.php';
            return;
        }
        if(j.status) {
            mkTotais = j.totais;
            
            // Atualiza Card Dinâmico e Alterna Paineis
            var cardContainer = document.getElementById('dinamicoCardContainer');
            var cardBox = document.getElementById('dinamicoCardIconBox');
            var cardIcon = document.getElementById('dinamicoCardIcon');
            var cardTitle = document.getElementById('dinamicoCardTitle');
            var cardMkauth = document.getElementById('dinamicoTotMkauth');
            var cardExtrato = document.getElementById('dinamicoTotExtrato');
            
            var painelExtrato = document.getElementById('painelExtrato');
            var painelBoletos = document.getElementById('painelBoletos');
            
            if (cPix) {
                painelExtrato.style.display = 'flex';
                painelBoletos.style.display = 'none';
                cardContainer.style.borderColor = '#3266ad';
                cardBox.style.background = '#3266ad'; cardBox.style.color = '#fff';
                cardTitle.style.color = '#3266ad';
                cardIcon.className = 'bi bi-qr-code-scan';
                cardTitle.textContent = 'PIX COMPENSADO';
                cardMkauth.textContent = fmtMoeda(mkTotais.pix);
            } else if (cBoleto) {
                painelExtrato.style.display = 'none';
                painelBoletos.style.display = 'flex';
                cardContainer.style.borderColor = '#3498db';
                cardBox.style.background = '#3498db'; cardBox.style.color = '#fff';
                cardTitle.style.color = '#3498db';
                cardIcon.className = 'bi bi-upc-scan';
                cardTitle.textContent = 'BOLETO COMPENSADO';
                cardMkauth.textContent = fmtMoeda(mkTotais.boleto);
            } else if (cCartao) {
                painelExtrato.style.display = 'flex';
                painelBoletos.style.display = 'none';
                cardContainer.style.borderColor = '#8e44ad';
                cardBox.style.background = '#8e44ad'; cardBox.style.color = '#fff';
                cardTitle.style.color = '#8e44ad';
                cardIcon.className = 'bi bi-credit-card-2-front-fill';
                cardTitle.textContent = 'CARTÃO COMPENSADO';
                cardMkauth.textContent = fmtMoeda(mkTotais.cartao);
            }
            
            // Reseta visual do card de extrato
            cardExtrato.innerHTML = 'Extrato: -';
            cardExtrato.style.color = '#666';
            
            dadosAtuais = j.entradas;
            if(j.dicionario) {
                dicionarioConciliacao = j.dicionario;
            }
            
            // Se já tem um extrato importado, re-processa o match com os dados originais brutos
            if (cPix || cCartao) {
                if (dadosExtratoBruto.length > 0) {
                    processarConciliacao(dadosExtratoBruto);
                } else {
                    renderTabela(dadosAtuais);
                }
            } else if (cBoleto) {
                if (dadosBoletosBruto.length > 0) {
                    processarConciliacaoBoletos(dadosBoletosBruto);
                } else {
                    renderTabela(dadosAtuais);
                }
            }
        } else {
            Swal.fire('Erro', j.msg, 'error');
        }
    })
    .catch(e => {
        hideLoading();
        Swal.fire('Erro', 'Falha na comunicação.', 'error');
    });
}

function renderTabela(entradas) {
    var wrap = document.getElementById('tabelaConferencia');
    document.getElementById('badgeResultados').textContent = entradas.length;

    if (!entradas.length) {
        wrap.style.display = 'flex';
        wrap.innerHTML = `
            <div style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                <p style="color:#666; font-size:13px; margin:0;">Nenhum lançamento compensado neste período.</p>
            </div>
        `;
        return;
    }
    
    // Tabela possui dados, restaura o CSS base
    wrap.style.display = 'block';
    
    var entradasOrdenadas = entradas.slice();
    entradasOrdenadas.sort(function(a, b) {
        var valA, valB;
        switch (sortCol) {
            case 'status':
                // Queremos que conciliado=false (Pendente) venha antes de conciliado=true
                // Considerando true=1 e false=0
                valA = a.conciliado ? 1 : 0;
                valB = b.conciliado ? 1 : 0;
                break;
            case 'nome': valA = (a.nome || '').toLowerCase(); valB = (b.nome || '').toLowerCase(); break;
            case 'referencia': valA = (a.referencia || '').toLowerCase(); valB = (b.referencia || '').toLowerCase(); break;
            case 'forma': valA = (a.formapag || '').toLowerCase(); valB = (b.formapag || '').toLowerCase(); break;
            case 'valor': valA = parseFloat(a.valorpag || 0); valB = parseFloat(b.valorpag || 0); break;
            case 'data_pag': valA = (a.datapag || ''); valB = (b.datapag || ''); break;
            case 'data_comp':
            default: valA = (a.data_compensacao || ''); valB = (b.data_compensacao || ''); break;
        }
        
        if (valA < valB) return sortDir === 'asc' ? -1 : 1;
        if (valA > valB) return sortDir === 'asc' ? 1 : -1;
        
        // Desempate por data de compensação se estiver ordenando por status
        if (sortCol === 'status') {
            var dataA = a.data_compensacao || '';
            var dataB = b.data_compensacao || '';
            if (dataA < dataB) return -1;
            if (dataA > dataB) return 1;
        }
        return 0;
    });

    var fpMap   = { pix:'pix', boleto:'bol', cartao:'cart' };
    var fpLabel = { pix:'PIX', boleto:'Boleto', cartao:'Cartao' };

    function getSortIcon(col) {
        if (sortCol !== col) return '';
        return sortDir === 'asc' ? ' &uarr;' : ' &darr;';
    }
    
    // Verifica se estamos no filtro de cartão para mudar o título da coluna
    var isFiltroCartao = document.getElementById('chkCartao') && document.getElementById('chkCartao').checked;
    var tituloColunaCompensacao = isFiltroCartao ? 'Cartão/Bandeira' : 'Compensação';

    var html = '<table class="lc-table"><thead><tr>'
        + '<th onclick="ordenarTabela(\'status\')" style="width:40px; cursor:pointer; user-select:none; text-align:center;" title="Ordenar por Status">' + getSortIcon('status') + '</th>'
        + '<th onclick="ordenarTabela(\'nome\')" style="cursor:pointer; user-select:none;">Nome / Login' + getSortIcon('nome') + '</th>'
        + '<th onclick="ordenarTabela(\'referencia\')" style="cursor:pointer; user-select:none;">Referência' + getSortIcon('referencia') + '</th>'
        + '<th class="prio-6" onclick="ordenarTabela(\'forma\')" style="cursor:pointer; user-select:none;">Forma' + getSortIcon('forma') + '</th>'
        + '<th onclick="ordenarTabela(\'data_pag\')" style="cursor:pointer; user-select:none; color:#888;">Pagamento' + getSortIcon('data_pag') + '</th>'
        + '<th onclick="ordenarTabela(\'data_comp\')" style="cursor:pointer; user-select:none; color:#1d9e75;">' + tituloColunaCompensacao + getSortIcon('data_comp') + '</th>'
        + '<th onclick="ordenarTabela(\'valor\')" style="cursor:pointer; user-select:none;">Valor' + getSortIcon('valor') + '</th>'
        + '</tr></thead><tbody>';

    entradasOrdenadas.forEach(function (e) {
        var cls   = fpMap[e.formapag]   || 'out';
        var label = fpLabel[e.formapag] || e.formapag;
        var horaPag = e.datapag.split(' ')[1] || '';
        var diaPag  = dataBr(e.datapag.split(' ')[0]);
        var diaComp = dataBr(e.data_compensacao);
        
        // Se for cartão, a compensação mostra a bandeira do cartão do banco de dados
        if (e.formapag === 'cartao') {
            diaComp = (e.fcartaobandeira && e.fcartaobandeira.trim() !== '') 
                ? escHtml(e.fcartaobandeira) 
                : '<i class="bi bi-exclamation-triangle" style="color:#f39c12; font-size:16px;" title="Bandeira não informada"></i>';
        }
        
        var iconStatus = '<i class="bi bi-circle" style="color:#ccc; font-size:16px;"></i>';
        var rowStyle = '';
        var onclickAction = '';
        
        if (dadosExtrato.length > 0 || dadosBoletos.length > 0) {
            if (e.conciliado) {
                var tooltipText = 'Ligado com: ' + escHtml(e.conciliado_com || e.nome_ligado_banco || '-');
                
                // Se for boleto, não tem desligamento manual
                if (e.formapag.toLowerCase().includes('boleto')) {
                    iconStatus = '<i class="bi bi-check-circle-fill" style="color:#27ae60; font-size:16px;" title="'+tooltipText+' (Match Automático)"></i>';
                } else {
                    iconStatus = '<i class="bi bi-check-circle-fill" style="color:#27ae60; font-size:16px; cursor:pointer;" title="'+tooltipText+'. Clique para Desligar" onclick="desligarLigacao(\''+escapeJsString(e.conciliado_nome_banco)+'\')"></i>';
                }
            } else {
                iconStatus = '<i class="bi bi-exclamation-circle-fill" style="color:#f39c12; font-size:16px;" title="Pendente"></i>';
                // Não adiciona hover amarelo nem click de ligação se for boleto
                if (!e.formapag.toLowerCase().includes('boleto')) {
                    rowStyle = 'cursor:pointer; transition:background 0.2s;';
                    if (mkauthSelecionadosIds.has(e.id_lanc)) {
                        rowStyle += ' background-color: #fcf3cf; border-left: 3px solid #f39c12;';
                        iconStatus = '<i class="bi bi-check-square-fill" style="color:#f39c12; font-size:16px;"></i>';
                    } else {
                        rowStyle += ' hover: background-color: #f9f9f9;';
                    }
                    onclickAction = 'onclick="selecionarMkauth(\'' + e.id_lanc + '\')"';
                }
            }
        }
        
        html += '<tr style="'+rowStyle+'" ' + onclickAction + '>'
            + '<td style="text-align:center;">' + iconStatus + '</td>'
            + '<td class="td-nome">' + escHtml(e.nome) + '<br><small style="color:#888; font-size: 11px;">' + escHtml(e.login) + '</small></td>'
            + '<td>' + escHtml(e.referencia || '-') + '<br><small style="color:#888; font-size: 11px;" title="Nosso Número do Boleto">' + escHtml(e.nossonum || '') + '</small></td>'
            + '<td class="prio-6"><span class="fp-badge fp-' + cls + '">' + label + '</span></td>'
            + '<td style="color:#888; font-size:11px;">' + diaPag + '</td>'
            + '<td style="color:#1d9e75; font-weight:bold; font-size:11px;">' + diaComp + '</td>'
            + '<td class="td-valor entrada-valor">' + fmtMoeda(e.valorpag) + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
}

function limparExtrato() {
    dadosExtratoBruto = [];
    dadosExtrato = [];
    extratoSelecionadoIdx = null;
    mkauthSelecionadosIds.clear();
    
    document.getElementById('dinamicoTotExtrato').innerHTML = 'Extrato: -';
    document.getElementById('dinamicoTotExtrato').style.color = '#666';
    
    if (dadosAtuais) {
        dadosAtuais.forEach(e => e.conciliado = false);
        renderTabela(dadosAtuais);
    }
    renderTabelaExtrato();
}

function processarConciliacao(extratoBruto) {
    var dtInicio = document.getElementById('dataInicio').value;
    var dtFim    = document.getElementById('dataFim').value;
    
    var extTotais = { pix: 0, boleto: 0, cartao: 0 };
    
    // Filtrar extrato bruto pelas datas selecionadas e classificar tipo
    dadosExtrato = extratoBruto.filter(ex => {
        if (!ex.Data) return false;
        var p = ex.Data.split('/'); // DD/MM/YYYY
        if (p.length !== 3) return false;
        var exIso = p[2] + '-' + p[1] + '-' + p[0];
        if (exIso < dtInicio || exIso > dtFim) return false;
        
        var cPix     = document.getElementById('chkPix').checked;
        var cBoleto  = document.getElementById('chkBoleto').checked;
        var cCartao  = document.getElementById('chkCartao').checked;
        
        var desc = (ex['Descrição'] || '').toUpperCase();
        if (desc.includes('PIX')) {
            ex.tipo_ext = 'pix';
        } else if (desc.includes('SUB')) {
            ex.tipo_ext = 'cartao';
        } else if (desc.includes('BOLETO')) {
            ex.tipo_ext = 'boleto';
        } else {
            ex.tipo_ext = 'outros';
        }
        
        // Aplica o filtro da interface
        if (ex.tipo_ext === 'pix' && !cPix) return false;
        if (ex.tipo_ext === 'cartao' && !cCartao) return false;
        if (ex.tipo_ext === 'boleto' && !cBoleto) return false;
        
        var valFloat = parseFloat(ex['Valor (R$)']);
        if (valFloat > 0) {
            if (ex.tipo_ext === 'pix') extTotais.pix += valFloat;
            if (ex.tipo_ext === 'cartao') extTotais.cartao += valFloat;
            if (ex.tipo_ext === 'boleto') extTotais.boleto += valFloat;
        }
        
        return true;
    });
    
    var cPix     = document.getElementById('chkPix').checked;
    var cCartao  = document.getElementById('chkCartao').checked;
    
    if (dadosExtrato.length === 0) {
        var formaNome = cPix ? 'PIX' : (cCartao ? 'Cartão' : 'registros');
        Swal.fire('Nenhum Registro', 'Não foram encontrados '+formaNome+' dentro do período selecionado ('+dataBr(dtInicio)+' até '+dataBr(dtFim)+') na planilha importada.', 'warning');
    }
    
    // Atualiza comparativo visual no card dinâmico
    function updateCardCompare(idExt, valMk, valExt) {
        var el = document.getElementById(idExt);
        if (valExt === 0 && valMk === 0) {
            el.innerHTML = 'Extrato: -';
            el.style.color = '#666';
            el.style.fontWeight = 'normal';
        } else {
            var diff = Math.abs(valMk - valExt);
            if (diff > 0.01) {
                var txtDiff = valMk > valExt ? 'Faltam ' + fmtMoeda(diff) : 'Sobram ' + fmtMoeda(diff);
                el.innerHTML = 'Extrato: ' + fmtMoeda(valExt) + ' <span style="font-size:10px; color:#e74c3c;">(' + txtDiff + ')</span> <i class="bi bi-exclamation-triangle-fill" title="Diferença de '+fmtMoeda(diff)+'"></i>';
                el.style.color = '#e74c3c';
                el.style.fontWeight = 'bold';
            } else {
                el.innerHTML = 'Extrato: ' + fmtMoeda(valExt) + ' <i class="bi bi-check-circle-fill"></i>';
                el.style.color = '#27ae60';
                el.style.fontWeight = 'bold';
            }
        }
    }
    
    var cPix     = document.getElementById('chkPix').checked;
    var cBoleto  = document.getElementById('chkBoleto').checked;
    var cCartao  = document.getElementById('chkCartao').checked;
    
    if (cPix) {
        updateCardCompare('dinamicoTotExtrato', mkTotais.pix, extTotais.pix);
    } else if (cBoleto) {
        updateCardCompare('dinamicoTotExtrato', mkTotais.boleto, extTotais.boleto);
    } else if (cCartao) {
        updateCardCompare('dinamicoTotExtrato', mkTotais.cartao, extTotais.cartao);
    }

    mkauthPendentes = [];
    
    // Reseta status + limpa qualquer vínculo antigo para não sobrar tooltip stale
    dadosAtuais.forEach(e => {
        e.conciliado = false;
        e.conciliado_com = '';
        e.conciliado_nome_banco = '';
        e.nome_ligado_banco = '';
        e.motivo = '';
    });
    dadosExtrato.forEach(ex => {
        ex.conciliado = false;
        ex.conciliado_com = '';
        ex.conciliado_nome_banco = '';
        ex.nome_ligado_mkauth = '';
        ex.ids_mkauth = [];
        ex.motivo = '';
        ex.bloqueado_dicionario = false;
    });
    
    // Variáveis auxiliares para evitar que o mesmo lançamento do MKAuth seja conciliado duas vezes
    var lancamentosConciliadosIds = new Set();
    
    // FASE 1: Match por Valor + Dicionário de Nomes
    // FASE 1.5: Match de Agrupamento (Vários lançamentos do mesmo cliente somando o valor do extrato)
    
    // Agrupa os lançamentos pendentes do MKAuth por Login E por Nome exato
    var mkauthAgrupadoPorLogin = {};
    var mkauthAgrupadoPorNome = {};
    
    dadosAtuais.forEach(mk => {
        if (!mk.conciliado) {
            // Agrupa por Login
            if (!mkauthAgrupadoPorLogin[mk.login]) mkauthAgrupadoPorLogin[mk.login] = { soma: 0, itens: [] };
            mkauthAgrupadoPorLogin[mk.login].soma += Math.abs(parseFloat(mk.valorpag));
            mkauthAgrupadoPorLogin[mk.login].itens.push(mk);
            
            // Agrupa por Nome exato (Para casos de múltiplos logins do mesmo cliente)
            var nomeUpper = mk.nome.toUpperCase().trim();
            if (!mkauthAgrupadoPorNome[nomeUpper]) mkauthAgrupadoPorNome[nomeUpper] = { soma: 0, itens: [] };
            mkauthAgrupadoPorNome[nomeUpper].soma += Math.abs(parseFloat(mk.valorpag));
            mkauthAgrupadoPorNome[nomeUpper].itens.push(mk);
        }
    });

    // FASE 0: PREENCHER OS DADOS DO BANCO (Dicionário) ANTES DE QUALQUER COISA
    // Varre o extrato inteiro e tenta fazer o match exato APENAS pelo dicionário
    dadosExtrato.forEach(ex => {
        if (ex.conciliado || ex.tipo_ext !== 'pix') return;
        var exValor = Math.abs(parseFloat(ex['Valor (R$)']));
        var exNomeLimpo = limparNomeExtrato(ex['Descrição']);
        ex.nome_limpo_exibicao = exNomeLimpo;
        
        // Verifica se já sabemos de quem é esse PIX (Dicionário do Banco)
        var loginsEsperados = dicionarioConciliacao[exNomeLimpo];
        if (loginsEsperados) {
            var matchDicionarioEncontrado = false;
            
            // 0.A: Tenta a soma combinada de TODOS os logins conhecidos
            var somaCombinadaLogins = 0;
            var itensCombinadosLogins = [];
            
            loginsEsperados.forEach(login => {
                if (mkauthAgrupadoPorLogin[login]) {
                    var itensNaoConciliados = mkauthAgrupadoPorLogin[login].itens.filter(i => !lancamentosConciliadosIds.has(i.id_lanc) && Math.abs(parseFloat(i.valorpag)) > 0 && !ligacoesIgnoradas.has(exNomeLimpo + '|' + i.id_lanc));
                    itensNaoConciliados.forEach(i => {
                        somaCombinadaLogins += Math.abs(parseFloat(i.valorpag));
                        itensCombinadosLogins.push(i);
                    });
                }
            });
            
            if (itensCombinadosLogins.length > 0 && Math.abs(somaCombinadaLogins - exValor) <= 0.101) {
                ex.conciliado = true;
                ex.motivo = 'dicionario_combinado';
                ex.nome_ligado_mkauth = '';
                ex.conciliado_nome_banco = exNomeLimpo;
                
                var nomesUnicos = new Set();
                var idsLigados = [];
                itensCombinadosLogins.forEach(mk => {
                    mk.conciliado = true;
                    mk.motivo = 'dicionario_combinado';
                    mk.nome_ligado_banco = ex.nome_limpo_exibicao;
                    mk.conciliado_com = exNomeLimpo;
                    mk.conciliado_nome_banco = exNomeLimpo;
                    lancamentosConciliadosIds.add(mk.id_lanc);
                    nomesUnicos.add(mk.nome);
                    idsLigados.push(mk.id_lanc);
                });
                
                ex.nome_ligado_mkauth = Array.from(nomesUnicos).join(' / ');
                ex.conciliado_com = ex.nome_ligado_mkauth;
                ex.ids_mkauth = idsLigados;
                matchDicionarioEncontrado = true;
            }

            // 0.B: Tenta Match por cada login individualmente
            if (!matchDicionarioEncontrado) {
                for (var login of loginsEsperados) {
                    if (mkauthAgrupadoPorLogin[login]) {
                        var itensNaoConciliados = mkauthAgrupadoPorLogin[login].itens.filter(i => !lancamentosConciliadosIds.has(i.id_lanc) && Math.abs(parseFloat(i.valorpag)) > 0 && !ligacoesIgnoradas.has(exNomeLimpo + '|' + i.id_lanc));
                        var somaIndividual = itensNaoConciliados.reduce((acc, curr) => acc + Math.abs(parseFloat(curr.valorpag)), 0);
                        
                        if (itensNaoConciliados.length > 0 && Math.abs(somaIndividual - exValor) <= 0.101) {
                            ex.conciliado = true;
                            ex.motivo = 'dicionario_agrupado_login';
                            ex.nome_ligado_mkauth = itensNaoConciliados[0].nome;
                            ex.conciliado_com = ex.nome_ligado_mkauth;
                            ex.conciliado_nome_banco = exNomeLimpo;
                            
                            var idsLigados = [];
                            itensNaoConciliados.forEach(mk => {
                                mk.conciliado = true;
                                mk.motivo = 'dicionario_agrupado_login';
                                mk.nome_ligado_banco = ex.nome_limpo_exibicao;
                                mk.conciliado_com = exNomeLimpo;
                                mk.conciliado_nome_banco = exNomeLimpo;
                                lancamentosConciliadosIds.add(mk.id_lanc);
                                idsLigados.push(mk.id_lanc);
                            });
                            ex.ids_mkauth = idsLigados;
                            matchDicionarioEncontrado = true;
                            break;
                        }
                    }
                }
            }

            // 0.C: Tenta Match 1 pra 1 exato
            if (!matchDicionarioEncontrado) {
                for (var login of loginsEsperados) {
                    if (mkauthAgrupadoPorLogin[login]) {
                        var itensNaoConciliados = mkauthAgrupadoPorLogin[login].itens.filter(i => !lancamentosConciliadosIds.has(i.id_lanc) && Math.abs(parseFloat(i.valorpag)) > 0 && !ligacoesIgnoradas.has(exNomeLimpo + '|' + i.id_lanc));
                        var mkMatch = itensNaoConciliados.find(m => Math.abs(Math.abs(parseFloat(m.valorpag)) - exValor) <= 0.101);
                        
                        if (mkMatch) {
                            ex.conciliado = true;
                            ex.motivo = 'dicionario_exato';
                            ex.nome_ligado_mkauth = mkMatch.nome;
                            ex.conciliado_com = mkMatch.nome;
                            ex.conciliado_nome_banco = exNomeLimpo;
                            ex.ids_mkauth = [mkMatch.id_lanc];
                            
                            mkMatch.conciliado = true;
                            mkMatch.motivo = 'dicionario_exato';
                            mkMatch.nome_ligado_banco = ex.nome_limpo_exibicao;
                            mkMatch.conciliado_com = exNomeLimpo;
                            mkMatch.conciliado_nome_banco = exNomeLimpo;
                            lancamentosConciliadosIds.add(mkMatch.id_lanc);
                            matchDicionarioEncontrado = true;
                            break;
                        }
                    }
                }
            }
            
            if (matchDicionarioEncontrado) return;
            
            // SE O DICIONÁRIO NÃO BATEU VALOR OU FOI IGNORADO MANUALMENTE:
            // Verificamos se há pelo menos um item ignorado manualmente para esse nome.
            // Se houver, a gente libera esse extrato para as próximas fases.
            // Se não houver nenhum ignorado, significa que o valor apenas não bateu,
            // então a gente BLOQUEIA para não roubar o PIX na Fase 2.
            var temAlgumIgnorado = false;
            for (let lig of ligacoesIgnoradas) {
                if (lig.startsWith(exNomeLimpo + '|')) {
                    temAlgumIgnorado = true;
                    break;
                }
            }
            
            if (temAlgumIgnorado) {
                ex.bloqueado_dicionario = false;
            } else {
                ex.bloqueado_dicionario = true;
                return;
            }
        }
    });

    // FASE 1 e 2: O resto das lógicas (Algoritmos e Similaridade) rodam APÓS o dicionário inteiro ser resolvido
    dadosExtrato.forEach(ex => {
        if (ex.conciliado || ex.tipo_ext !== 'pix' || ex.bloqueado_dicionario === true) return; 
        var exValor = Math.abs(parseFloat(ex['Valor (R$)']));
        var exNomeLimpo = ex.nome_limpo_exibicao;
        var exDataStr = ex.Data;
        
        // Verifica se ele pertencia ao dicionário e não bateu valor (Para não roubar da Fase 2)
        // Mesmo liberado pelos ignorados manuais, vamos proibir categoricamente
        // qualquer extrato que já tenha dono no dicionário de ser usado na Similaridade.
        var loginsEsperados = dicionarioConciliacao[exNomeLimpo];
        if (loginsEsperados) return; 

        // --- TENTATIVA 2: Match Agrupado Inteligente (Pelo Algoritmo / Nome similar) ---
        if (!ex.conciliado) {
            var melhorCandidatoAgrupado = null;
            var melhorScoreAgrupado = 0;
            var melhorItensAgrupados = [];
            
            // Procura nos grupos por Nome (Pega casos de logins diferentes mas mesmo nome)
            for (var nomeGrupo in mkauthAgrupadoPorNome) {
                var grupo = mkauthAgrupadoPorNome[nomeGrupo];
                // Só considera itens DISPONIVEIS (não conciliados ainda e não bloqueados manualmente)
                var itensDisponiveis = grupo.itens.filter(mk =>
                    !mk.conciliado &&
                    !lancamentosConciliadosIds.has(mk.id_lanc) &&
                    Math.abs(parseFloat(mk.valorpag)) > 0 &&
                    podeLigar(exNomeLimpo, mk)
                );
                if (itensDisponiveis.length === 0) continue;

                // Soma dinâmica apenas dos itens ainda livres (evita dupla ligação no mesmo lançamento)
                var somaDisponivel = itensDisponiveis.reduce((acc, mk) => acc + Math.abs(parseFloat(mk.valorpag)), 0);

                // Checa se a soma disponível bate com o valor do extrato (Tolerância de 10 centavos)
                if (Math.abs(somaDisponivel - exValor) <= 0.101) {
                    var score = calcularSimilaridade(nomeGrupo, exNomeLimpo);
                    if (score > melhorScoreAgrupado) {
                        melhorScoreAgrupado = score;
                        melhorCandidatoAgrupado = grupo;
                        melhorItensAgrupados = itensDisponiveis;
                    }
                }
            }
            
            if (melhorCandidatoAgrupado && melhorScoreAgrupado >= 0.6) {
                melhorItensAgrupados.forEach(mk => {
                    mk.conciliado = true;
                    mk.conciliado_com = exNomeLimpo;
                    mk.conciliado_nome_banco = exNomeLimpo;
                    lancamentosConciliadosIds.add(mk.id_lanc);
                });
                ex.conciliado = true;
                ex.conciliado_com = melhorItensAgrupados.map(m => m.nome).join(', ');
                ex.conciliado_nome_banco = exNomeLimpo;
                return;
            }
        }
        
        // --- TENTATIVA 3: Match Unitário Simples (Fase Clássica) ---
        // Filtra apenas os candidatos que AINDA NÃO FORAM CONCILIADOS
        if (!ex.conciliado) {
            var candidatos = dadosAtuais.filter(mk => 
                !lancamentosConciliadosIds.has(mk.id_lanc) && 
                !mk.conciliado && 
                Math.abs(parseFloat(mk.valorpag) - exValor) <= 0.101 && 
                mk.data_compensacao.split('-').reverse().join('/') === exDataStr &&
                podeLigar(exNomeLimpo, mk)
            );
            
            if (candidatos.length === 1) {
                // Só tem um lançamento com esse valor nesse dia = MATCH!
                var mkMatch = candidatos[0];
                mkMatch.conciliado = true;
                mkMatch.conciliado_com = exNomeLimpo;
                mkMatch.conciliado_nome_banco = exNomeLimpo;
                ex.conciliado = true;
                ex.conciliado_com = mkMatch.nome;
                ex.conciliado_nome_banco = exNomeLimpo;
                lancamentosConciliadosIds.add(mkMatch.id_lanc);
            } else if (candidatos.length > 1) {
                // Tenta o MATCH por Algoritmo de Similaridade Avançada (Fase 2 inteligente)
                var melhorCandidato = null;
                var melhorScore = 0;
                
                candidatos.forEach(mk => {
                    var nomeMk = mk.nome.toUpperCase();
                    var score = calcularSimilaridade(nomeMk, exNomeLimpo);
                    if (score > melhorScore) {
                        melhorScore = score;
                        melhorCandidato = mk;
                    }
                });
                
                // Se a similaridade for boa o suficiente (> 60%), ele liga automático!
                if (melhorCandidato && melhorScore >= 0.6) {
                    melhorCandidato.conciliado = true;
                    melhorCandidato.conciliado_com = exNomeLimpo;
                    melhorCandidato.conciliado_nome_banco = exNomeLimpo;
                    ex.conciliado = true;
                    ex.conciliado_com = melhorCandidato.nome;
                    ex.conciliado_nome_banco = exNomeLimpo;
                    lancamentosConciliadosIds.add(melhorCandidato.id_lanc);
                }
            }
        }
    });
    
    renderTabela(dadosAtuais);
    renderTabelaExtrato();
}

var sortColExt = 'status';
var sortDirExt = 'asc';

function ordenarTabelaExtrato(coluna) {
    if (sortColExt === coluna) {
        sortDirExt = sortDirExt === 'asc' ? 'desc' : 'asc';
    } else {
        sortColExt = coluna;
        sortDirExt = 'asc';
    }
    renderTabelaExtrato();
}

function renderTabelaExtrato() {
    var wrap = document.getElementById('tabelaExtrato');
    var btnLimpar = document.getElementById('btnLimparExtrato');
    
    if (!dadosExtrato || dadosExtrato.length === 0) {
        btnLimpar.style.display = 'none';
        document.getElementById('badgeConciliados').textContent = '0';
        document.getElementById('badgePendentes').textContent = '0';
        wrap.innerHTML = `
            <div id="boxImportacaoCentro" style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                <i class="bi bi-file-earmark-excel" style="font-size:40px; color:#27ae60; margin-bottom:10px; display:block;"></i>
                <p style="color:#666; font-size:13px; margin-bottom:20px;">Nenhum extrato importado para este período.</p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <label class="lc-upload-box" for="fileXls" style="width: 140px; justify-content: center; min-width: 140px;">
                        <i class="bi bi-file-earmark-arrow-up"></i>
                        <span id="fileNameDisplay">Selecionar<br>Extrato</span>
                    </label>
                    <input type="file" id="fileXls" accept=".xls,.xlsx" style="display: none;" onchange="document.getElementById('fileNameDisplay').innerHTML = this.files[0] ? this.files[0].name : 'Selecionar<br>Extrato';">
                    
                    <button class="lc-btn-green" onclick="importarXls()" style="width: 140px; justify-content: center; padding: 0 10px;">
                        <i class="bi bi-arrow-repeat"></i> Importar
                    </button>
                </div>
            </div>
        `;
        return;
    }
    
    btnLimpar.style.display = 'inline-block';
    
    // Tabela possui dados, restaura o CSS base para tabela
    wrap.style.display = 'block';
    
    var conciliados = dadosExtrato.filter(e => e.conciliado).length;
    var pendentes = dadosExtrato.filter(e => !e.conciliado).length;
    var totalExtrato = 0;
    
    document.getElementById('badgeConciliados').textContent = conciliados;
    document.getElementById('badgePendentes').textContent = pendentes;
    
    // ORDENAÇÃO DO EXTRATO
    var extratoOrdenado = dadosExtrato.slice();
    extratoOrdenado.sort(function(a, b) {
        var valA, valB;
        switch (sortColExt) {
            case 'status':
                valA = a.conciliado ? 1 : 0;
                valB = b.conciliado ? 1 : 0;
                break;
            case 'descricao': valA = (a.nome_limpo_exibicao || '').toLowerCase(); valB = (b.nome_limpo_exibicao || '').toLowerCase(); break;
            case 'data': valA = (a.Data || ''); valB = (b.Data || ''); break;
            case 'valor': valA = Math.abs(parseFloat(a['Valor (R$)']) || 0); valB = Math.abs(parseFloat(b['Valor (R$)']) || 0); break;
            default: valA = a.Data; valB = b.Data; break;
        }
        
        if (valA < valB) return sortDirExt === 'asc' ? -1 : 1;
        if (valA > valB) return sortDirExt === 'asc' ? 1 : -1;
        
        // Desempate por data se ordenando por status
        if (sortColExt === 'status') {
            var dataA = a.Data || '';
            var dataB = b.Data || '';
            if (dataA < dataB) return -1;
            if (dataA > dataB) return 1;
        }
        return 0;
    });

    function getSortIconExt(col) {
        if (sortColExt !== col) return '';
        return sortDirExt === 'asc' ? ' &uarr;' : ' &darr;';
    }

    var html = '<table class="lc-table"><thead><tr>'
        + '<th onclick="ordenarTabelaExtrato(\'status\')" style="width:40px; cursor:pointer; user-select:none; text-align:center;" title="Ordenar por Status">' + getSortIconExt('status') + '</th>'
        + '<th onclick="ordenarTabelaExtrato(\'data\')" style="cursor:pointer; user-select:none; width: 75px;">Data' + getSortIconExt('data') + '</th>'
        + '<th onclick="ordenarTabelaExtrato(\'descricao\')" style="cursor:pointer; user-select:none;">Descrição' + getSortIconExt('descricao') + '</th>'
        + '<th onclick="ordenarTabelaExtrato(\'valor\')" style="cursor:pointer; user-select:none; width: 100px;">Valor' + getSortIconExt('valor') + '</th>'
        + '</tr></thead><tbody>';

    extratoOrdenado.forEach(function (e) {
        var originalIndex = dadosExtrato.indexOf(e);
        var valFloat = parseFloat(e['Valor (R$)']);
        var isCredito = valFloat > 0;
        if (!isCredito) return; // Ignora débitos na tela
        
        totalExtrato += valFloat;
        
        var iconStatus = '';
        var rowStyle = '';
        
        if (e.tipo_ext === 'cartao') {
            iconStatus = '<i class="bi bi-credit-card" style="color:#8e44ad; font-size:16px;" title="Cartão (Agrupado/Não Conciliável)"></i>';
            rowStyle = 'opacity: 0.6;';
        } else {
            if (e.conciliado) {
                var tooltipText = 'Ligado com: ' + escHtml(e.conciliado_com || e.nome_ligado_mkauth || '-');
                iconStatus = '<i class="bi bi-check-circle-fill" style="color:#27ae60; font-size:16px; cursor:pointer;" title="'+tooltipText+'. Clique para Desligar" onclick="desligarLigacao(\''+escapeJsString(e.conciliado_nome_banco)+'\')"></i>';
            } else {
                iconStatus = '<button class="lc-btn-imprimir" style="padding:2px 6px; font-size:10px;" onclick="selecionarExtrato('+originalIndex+')">Ligar</button>';
            }
                
            rowStyle = e.conciliado ? 'opacity: 0.5;' : '';
            if (extratoSelecionadoIdx === originalIndex) rowStyle = 'background-color: #fcf3cf; border-left: 3px solid #f39c12;';
        }

        html += '<tr style="'+rowStyle+'">'
            + '<td style="text-align:center;">' + iconStatus + '</td>'
            + '<td>' + escHtml(e.Data) + '</td>'
            + '<td style="font-size:10px;" title="Original: '+escHtml(e['Descrição'])+'">' + escHtml(e.nome_limpo_exibicao || e['Descrição']) + '</td>'
            + '<td class="td-valor entrada-valor">' + fmtMoeda(valFloat) + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    
    // Adiciona o resumo financeiro do extrato no rodapé
    html += '<div style="margin-top:10px; padding:10px; background:#e8f5e9; border:1px solid #c8e6c9; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">'
          + '<span style="font-weight:bold; color:#27ae60; font-size:13px;">Total Recebido (Extrato):</span>'
          + '<span style="font-weight:bold; color:#27ae60; font-size:15px;">' + fmtMoeda(totalExtrato) + '</span>'
          + '</div>';
          
    wrap.innerHTML = html;
}

function selecionarExtrato(idx) {
    extratoSelecionadoIdx = idx;
    
    // Mostra barra amarela de alerta
    document.getElementById('alertaModoLigacao').style.display = 'flex';
    var nomeExt = dadosExtrato[idx].nome_limpo_exibicao || dadosExtrato[idx]['Descrição'];
    document.getElementById('textoModoLigacao').innerHTML = `Extrato selecionado: <b>${escHtml(nomeExt)}</b>. Agora clique nos lançamentos pendentes à esquerda para vincular.`;
    
    renderTabelaExtrato();
    renderTabela(dadosAtuais); // Re-renderiza a da esquerda para sumir o alerta se precisar
}

function cancelarModoLigacao() {
    extratoSelecionadoIdx = null;
    mkauthSelecionadosIds.clear();
    document.getElementById('alertaModoLigacao').style.display = 'none';
    renderTabela(dadosAtuais);
    renderTabelaExtrato();
}

function selecionarMkauth(id_lanc) {
    // Se o modo ligação não estiver ativo (nenhum extrato selecionado), 
    // ignoramos o clique na tabela da esquerda (sem mostrar a mensagem toast chata)
    if (extratoSelecionadoIdx === null) {
        return;
    }
    
    // Adiciona ou remove da seleção múltipla
    if (mkauthSelecionadosIds.has(id_lanc)) {
        mkauthSelecionadosIds.delete(id_lanc);
    } else {
        mkauthSelecionadosIds.add(id_lanc);
    }
    
    renderTabela(dadosAtuais);
    
    if (mkauthSelecionadosIds.size === 0) return;
    
    var ex = dadosExtrato[extratoSelecionadoIdx];
    var exValor = Math.abs(parseFloat(ex['Valor (R$)']));
    
    // Calcula a soma dos itens selecionados
    var mkSoma = 0;
    var nomesSelecionados = [];
    var loginsParaSalvar = []; 
    
    mkauthSelecionadosIds.forEach(id => {
        var mk = dadosAtuais.find(l => l.id_lanc == id);
        if (mk) {
            mkSoma += Math.abs(parseFloat(mk.valorpag));
            nomesSelecionados.push(mk.nome);
            if (!loginsParaSalvar.includes(mk.login)) loginsParaSalvar.push(mk.login);
        }
    });
    
    // Só exibe a tela de confirmação se a soma bater (com tolerância de 10 centavos)
    if (Math.abs(mkSoma - exValor) <= 0.101) {
        var nomesHtml = nomesSelecionados.map(n => '<b>'+n+'</b>').join('<br>');
        var msgHtml = `Ligar o Extrato:<br><b>${ex['Descrição']}</b> (R$ ${fmtMoeda(exValor)})<br>Aos ${mkauthSelecionadosIds.size} itens selecionados:<br>${nomesHtml}?`;
        
        Swal.fire({
            title: 'Confirmar Conciliação',
            html: msgHtml,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sim, Ligar e Aprender',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                var exNomeLimpo = limparNomeExtrato(ex['Descrição']);
                
                var fd = new FormData();
                fd.append('nome_banco', exNomeLimpo);
                fd.append('logins_mkauth', JSON.stringify(loginsParaSalvar)); // Envia todos os logins únicos
                
                fetch('conferencia.php?ajax=aprender_nome', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.sessao_expirada) {
                        window.location.href = '/admin/login.php';
                        return;
                    }
                    if (j.status) {
                        Swal.fire('Conciliado!', 'O sistema aprendeu e ligou os itens.', 'success');
                        
                        // Remove dos ignorados, caso o usuário tenha desligado antes e agora resolveu ligar de novo
                        var nomeUpper = exNomeLimpo.toUpperCase();
                        loginsParaSalvar.forEach(l => {
                            dadosAtuais.forEach(mk => {
                                if (mk.login === l) {
                                    ligacoesIgnoradas.delete(exNomeLimpo + '|' + mk.id_lanc);
                                }
                            });
                        });
                        
                        if (!dicionarioConciliacao[nomeUpper]) {
                            dicionarioConciliacao[nomeUpper] = [];
                        }
                        loginsParaSalvar.forEach(l => {
                            if (!dicionarioConciliacao[nomeUpper].includes(l)) {
                                dicionarioConciliacao[nomeUpper].push(l);
                            }
                        });
                        cancelarModoLigacao(); // Reseta os selects e esconde a barra
                        processarConciliacao(dadosExtratoBruto); // Reprocessa com os dados brutos para refazer o visual
                    } else {
                        Swal.fire('Erro', j.msg, 'error');
                    }
                });
            } else {
                cancelarModoLigacao();
            }
        });
    } else {
        // Mostra um aviso suave de que a soma ainda não bateu
        Swal.fire({
            toast: true, position: 'bottom-end', showConfirmButton: false, timer: 3000,
            icon: 'info', title: `Selecionado: ${fmtMoeda(mkSoma)} de ${fmtMoeda(exValor)}. Faltam ${fmtMoeda(Math.abs(exValor - mkSoma))}`
        });
    }
}

function importarXls() {
    var fileInput = document.getElementById('fileXls');
    if (!fileInput.files.length) {
        Swal.fire('Atenção', 'Selecione o arquivo extrato.xls do Sicredi.', 'warning');
        return;
    }
    
    // Testa se a sessão não expirou antes de processar um arquivo grande
    fetch('conferencia.php?ajax=check_sessao')
    .then(r => r.json())
    .then(j => {
        if (j.sessao_expirada) {
            window.location.href = '/admin/login.php';
            return;
        }
        
        var file = fileInput.files[0];
        var reader = new FileReader();
        
        reader.onload = function(e) {
            var data = new Uint8Array(e.target.result);
            var workbook = XLSX.read(data, {type: 'array'});
            var firstSheetName = workbook.SheetNames[0];
            var worksheet = workbook.Sheets[firstSheetName];
            
            // Converte pra JSON a partir da linha 9 (onde tem os headers Data, Descricao, Documento...)
            var jsonRows = XLSX.utils.sheet_to_json(worksheet, { range: 8 });
            
            if (!jsonRows || jsonRows.length === 0) {
                Swal.fire('Erro', 'Não foi possível ler os dados da planilha.', 'error');
                return;
            }
            
            // Guarda os dados brutos globais e dispara a conciliação
            dadosExtratoBruto = jsonRows;
            processarConciliacao(dadosExtratoBruto);
        };
        
        reader.readAsArrayBuffer(file);
    }).catch(e => {
        window.location.href = '/admin/login.php';
    });
}

// ============================================================================
// BLOCO DE IMPORTAÇÃO E CONCILIAÇÃO DE BOLETOS
// ============================================================================

function importarXlsBoletos() {
    var fileInput = document.getElementById('fileXlsBoletos');
    if (!fileInput.files.length) {
        Swal.fire('Atenção', 'Selecione o arquivo de Relatório de Boletos.', 'warning');
        return;
    }
    
    fetch('conferencia.php?ajax=check_sessao')
    .then(r => r.json())
    .then(j => {
        if (j.sessao_expirada) {
            window.location.href = '/admin/login.php';
            return;
        }
        
        var file = fileInput.files[0];
        var reader = new FileReader();
        
        reader.onload = function(e) {
            var data = new Uint8Array(e.target.result);
            var workbook = XLSX.read(data, {type: 'array'});
            var firstSheetName = workbook.SheetNames[0];
            var worksheet = workbook.Sheets[firstSheetName];
            
            // O print mostra que a tabela começa na linha 18 do Excel, então o range é 17
            var jsonRows = XLSX.utils.sheet_to_json(worksheet, { range: 17 });
            
            if (!jsonRows || jsonRows.length === 0) {
                Swal.fire('Erro', 'Não foi possível ler os dados do Relatório de Boletos.', 'error');
                return;
            }
            
            dadosBoletosBruto = jsonRows;
            processarConciliacaoBoletos(dadosBoletosBruto);
        };
        
        reader.readAsArrayBuffer(file);
    }).catch(e => {
        window.location.href = '/admin/login.php';
    });
}

// Limpa qualquer caractere que não seja número (ex: barras, traços) do Nosso Numero
function extrairNossoNumero(str) {
    if (!str) return '';
    return (str + '').replace(/[^0-9]/g, '');
}

// Converte valor monetário BR da planilha (ex: "1.823,20" ou "161,89") para Float real
function parseMoedaBR(str) {
    if (!str) return 0;
    if (typeof str === 'number') return str;
    
    // Remove os pontos de milhar, depois troca a vírgula decimal por ponto
    var numStr = str.toString().replace(/\./g, '').replace(',', '.');
    return parseFloat(numStr) || 0;
}

function processarConciliacaoBoletos(boletosBruto) {
    var dtInicio = document.getElementById('dataInicio').value;
    var dtFim    = document.getElementById('dataFim').value;
    
    var totalBoletosLiquidacao = 0;
    
    // Filtrar boletos pelas datas selecionadas
    dadosBoletos = boletosBruto.filter(b => {
        var dataLiq = b['Data Liquidação'];
        if (!dataLiq) return false;
        
        var p = dataLiq.split('/'); // DD/MM/YYYY
        if (p.length !== 3) return false;
        var bIso = p[2] + '-' + p[1] + '-' + p[0];
        
        if (bIso < dtInicio || bIso > dtFim) return false;
        
        // Usa o novo parseador de moeda
        var valLiq = parseMoedaBR(b['Liquidação (R$)']);
        b.valor_float = valLiq; // Salva o valor real na memória
        
        if (valLiq > 0) {
            totalBoletosLiquidacao += valLiq;
        }
        
        return true;
    });
    
    if (dadosBoletos.length === 0) {
        Swal.fire('Nenhum Boleto', 'Não foram encontrados boletos liquidados dentro do período selecionado ('+dataBr(dtInicio)+' até '+dataBr(dtFim)+') na planilha importada.', 'warning');
    }
    
    // Reseta status apenas dos itens do tipo boleto na tabela esquerda
    dadosAtuais.forEach(e => {
        if (e.formapag.toLowerCase().includes('boleto')) {
            e.conciliado = false;
            e.conciliado_com = '';
            e.conciliado_nome_banco = '';
        }
    });
    
    dadosBoletos.forEach(b => {
        b.conciliado = false;
        b.nome_limpo_nosso_numero = extrairNossoNumero(b['Nosso Nº']);
    });
    
    // Match Exato por Nosso Número (Ignorando máscaras)
    dadosBoletos.forEach(b => {
        var nnBoleto = b.nome_limpo_nosso_numero;
        if (!nnBoleto) return;
        
        var match = dadosAtuais.find(mk => {
            if (mk.conciliado || !mk.formapag.toLowerCase().includes('boleto')) return false;
            
            // O Nossonum do BD às vezes é salvo limpo ou completo, então vamos limpar para comparar bananas com bananas.
            // Extrai só os números do nossonum do MKAuth e do Boleto.
            var nnMkauth = extrairNossoNumero(mk.nossonum);
            
            // Verifica se um número está contido dentro do outro (já que um pode ter DV e o outro não)
            if (nnMkauth && nnBoleto && (nnMkauth === nnBoleto || nnMkauth.includes(nnBoleto) || nnBoleto.includes(nnMkauth))) {
                // Checagem de segurança extra: Verifica se o valor bate (com tolerância de 10 centavos)
                var valMkauth = Math.abs(parseFloat(mk.valorpag));
                var valBoleto = b.valor_float;
                if (Math.abs(valMkauth - valBoleto) <= 0.101) {
                    return true; // É O BOLETO!
                }
            }
            return false;
        });
        
        if (match) {
            b.conciliado = true;
            match.conciliado = true;
            match.conciliado_com = b['Pagador'] || 'Boleto Localizado';
            match.conciliado_nome_banco = b['Nosso Nº'];
            
            // A planilha do Sicredi formata números como string com vírgula para milhar
            // Exemplo: 1.823,20 ou 161,89. O parseFloat do JS se confunde se tiver vírgula.
            // O valor limpo já está salvo em b.valor_float.
        }
    });
    
    // ATENÇÃO: Re-renderizar ambas as tabelas após o loop de match
    
    // Atualiza Card Comparativo
    var el = document.getElementById('dinamicoTotExtrato');
    var valMk = mkTotais.boleto;
    var valExt = totalBoletosLiquidacao;
    
    if (valExt === 0 && valMk === 0) {
        el.innerHTML = 'Extrato: -';
        el.style.color = '#666';
        el.style.fontWeight = 'normal';
    } else {
        var diff = Math.abs(valMk - valExt);
        if (diff > 0.01) {
            var txtDiff = valMk > valExt ? 'Faltam ' + fmtMoeda(diff) : 'Sobram ' + fmtMoeda(diff);
            el.innerHTML = 'Relatório: ' + fmtMoeda(valExt) + ' <span style="font-size:10px; color:#e74c3c;">(' + txtDiff + ')</span> <i class="bi bi-exclamation-triangle-fill" title="Diferença de '+fmtMoeda(diff)+'"></i>';
            el.style.color = '#e74c3c';
            el.style.fontWeight = 'bold';
        } else {
            el.innerHTML = 'Relatório: ' + fmtMoeda(valExt) + ' <i class="bi bi-check-circle-fill"></i>';
            el.style.color = '#27ae60';
            el.style.fontWeight = 'bold';
        }
    }
    
    // Atualiza badges
    var conciliadosMK = dadosAtuais.filter(mk => mk.formapag.toLowerCase().includes('boleto') && mk.conciliado).length;
    var pendentesMK = dadosAtuais.filter(mk => mk.formapag.toLowerCase().includes('boleto') && !mk.conciliado).length;
    
    // Não temos badge no topo da tabela esquerda, apenas o Total de Resultados, 
    // mas a renderização da tabela vai pintar as linhas corretamente.
    
    renderTabela(dadosAtuais);
    renderTabelaBoletos();
}

var sortColBol = 'status';
var sortDirBol = 'asc';

function ordenarTabelaBoletos(coluna) {
    if (sortColBol === coluna) {
        sortDirBol = sortDirBol === 'asc' ? 'desc' : 'asc';
    } else {
        sortColBol = coluna;
        sortDirBol = 'asc';
    }
    renderTabelaBoletos();
}

function renderTabelaBoletos() {
    var wrap = document.getElementById('tabelaBoletos');
    var btnLimpar = document.getElementById('btnLimparBoletos');
    
    if (!dadosBoletos || dadosBoletos.length === 0) {
        btnLimpar.style.display = 'none';
        document.getElementById('badgeBoletosConciliados').textContent = '0';
        document.getElementById('badgeBoletosPendentes').textContent = '0';
        wrap.innerHTML = `
            <div id="boxImportacaoBoletosCentro" style="text-align:center; padding:50px 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                <i class="bi bi-file-earmark-spreadsheet" style="font-size:40px; color:#3498db; margin-bottom:10px; display:block;"></i>
                <p style="color:#666; font-size:13px; margin-bottom:20px;">Nenhum relatório de boletos importado.</p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <label class="lc-upload-box" for="fileXlsBoletos" style="width: 140px; justify-content: center; min-width: 140px; border-color: #3498db; color: #3498db;">
                        <i class="bi bi-file-earmark-arrow-up"></i>
                        <span id="fileNameDisplayBoletos">Selecionar<br>Relatório</span>
                    </label>
                    <input type="file" id="fileXlsBoletos" accept=".xls,.xlsx" style="display: none;" onchange="document.getElementById('fileNameDisplayBoletos').innerHTML = this.files[0] ? this.files[0].name : 'Selecionar<br>Relatório';">
                    
                    <button class="lc-btn-outline" onclick="importarXlsBoletos()" style="width: 140px; justify-content: center; padding: 0 10px; color: #3498db; border-color: #3498db;">
                        <i class="bi bi-arrow-repeat"></i> Importar
                    </button>
                </div>
            </div>
        `;
        return;
    }
    
    btnLimpar.style.display = 'inline-block';
    wrap.style.display = 'block';
    
    var conciliados = dadosBoletos.filter(b => b.conciliado).length;
    var pendentes = dadosBoletos.filter(b => !b.conciliado).length;
    var totalBoletos = 0;
    
    document.getElementById('badgeBoletosConciliados').textContent = conciliados;
    document.getElementById('badgeBoletosPendentes').textContent = pendentes;
    
    var boletosOrdenados = dadosBoletos.slice();
    boletosOrdenados.sort(function(a, b) {
        var valA, valB;
        switch (sortColBol) {
            case 'status':
                valA = a.conciliado ? 1 : 0;
                valB = b.conciliado ? 1 : 0;
                break;
            case 'nossonumero': valA = (a['Nosso Nº'] || '').toLowerCase(); valB = (b['Nosso Nº'] || '').toLowerCase(); break;
            case 'pagador': valA = (a['Pagador'] || '').toLowerCase(); valB = (b['Pagador'] || '').toLowerCase(); break;
            case 'data': valA = (a['Data Liquidação'] || ''); valB = (b['Data Liquidação'] || ''); break;
            case 'valor': valA = a.valor_float || 0; valB = b.valor_float || 0; break;
            default: valA = a['Data Liquidação']; valB = b['Data Liquidação']; break;
        }
        
        if (valA < valB) return sortDirBol === 'asc' ? -1 : 1;
        if (valA > valB) return sortDirBol === 'asc' ? 1 : -1;
        
        if (sortColBol === 'status') {
            var dataA = a['Data Liquidação'] || '';
            var dataB = b['Data Liquidação'] || '';
            if (dataA < dataB) return -1;
            if (dataA > dataB) return 1;
        }
        return 0;
    });

    function getSortIconBol(col) {
        if (sortColBol !== col) return '';
        return sortDirBol === 'asc' ? ' &uarr;' : ' &darr;';
    }

    var html = '<table class="lc-table"><thead><tr>'
        + '<th onclick="ordenarTabelaBoletos(\'status\')" style="width:40px; cursor:pointer; user-select:none; text-align:center;">' + getSortIconBol('status') + '</th>'
        + '<th onclick="ordenarTabelaBoletos(\'data\')" style="cursor:pointer; user-select:none; width: 75px;">Liquidação' + getSortIconBol('data') + '</th>'
        + '<th onclick="ordenarTabelaBoletos(\'nossonumero\')" style="cursor:pointer; user-select:none;">Nosso Nº' + getSortIconBol('nossonumero') + '</th>'
        + '<th onclick="ordenarTabelaBoletos(\'pagador\')" style="cursor:pointer; user-select:none;">Pagador' + getSortIconBol('pagador') + '</th>'
        + '<th onclick="ordenarTabelaBoletos(\'valor\')" style="cursor:pointer; user-select:none; width: 100px;">Valor' + getSortIconBol('valor') + '</th>'
        + '</tr></thead><tbody>';

    boletosOrdenados.forEach(function (b) {
        var valFloat = b.valor_float || 0;
        totalBoletos += valFloat;
        
        var iconStatus = b.conciliado 
            ? '<i class="bi bi-check-circle-fill" style="color:#27ae60; font-size:16px;" title="Conciliado Automaticamente"></i>' 
            : '<i class="bi bi-exclamation-circle-fill" style="color:#f39c12; font-size:16px;" title="Boleto não localizado no sistema"></i>';
            
        var rowStyle = b.conciliado ? 'opacity: 0.5;' : '';

        html += '<tr style="'+rowStyle+'">'
            + '<td style="text-align:center;">' + iconStatus + '</td>'
            + '<td>' + escHtml(b['Data Liquidação']) + '</td>'
            + '<td style="font-size:11px; font-weight:bold;">' + escHtml(b['Nosso Nº']) + '</td>'
            + '<td style="font-size:10px;">' + escHtml(b['Pagador']) + '</td>'
            + '<td class="td-valor entrada-valor">' + fmtMoeda(valFloat) + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    
    html += '<div style="margin-top:10px; padding:10px; background:#eaf8fb; border:1px solid #bce8f1; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">'
          + '<span style="font-weight:bold; color:#3498db; font-size:13px;">Total Recebido (Boletos):</span>'
          + '<span style="font-weight:bold; color:#3498db; font-size:15px;">' + fmtMoeda(totalBoletos) + '</span>'
          + '</div>';
          
    wrap.innerHTML = html;
}
</script>

<div style="position: fixed; bottom: 10px; right: 15px; font-size: 11px; color: #9ca3af; z-index: 9999; font-family: 'Inter', sans-serif; pointer-events: none;">
    By Marcelo Silvestro
</div>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js.hhvm"></script>
</body>
</html>
