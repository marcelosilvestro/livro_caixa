<?php
require_once __DIR__ . '/config.php';

/* ================================================================
   AJAX — Carrega todos os dados de uma data
   ================================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dados') {
    $data_ref = trim($_GET['data'] ?? date('Y-m-d'));

    if (!valida_data($data_ref)) {
        json_saida(false, 'Data inválida.');
    }

    $caixa       = get_ou_criar_caixa($pdo, $data_ref);
    $totais_live = get_totais_entradas($pdo, $data_ref);
    $saidas_live = get_total_saidas($pdo, $data_ref);

    // Caixa fechado: exibe totais gravados para dinheiro, e calcula ao vivo os demais
    $discrepancia_dinheiro = false;
    $diferenca_dinheiro_valor = 0;
    
    if ($caixa['status'] === 'fechado') {
        $totais = [
            'total_pix'         => (float) $totais_live['total_pix'],
            'total_dinheiro'    => (float) $caixa['total_dinheiro'],
            'total_boleto'      => (float) $totais_live['total_boleto'],
            'total_cartao'      => (float) $totais_live['total_cartao'],
            'total_outros'      => (float) $totais_live['total_outros'],
            // total geral soma o dinheiro gravado no caixa fechado + as outras entradas atuais
            'valor_total_geral' => (float) $caixa['total_dinheiro'] + (float) $totais_live['total_pix'] + (float) $totais_live['total_boleto'] + (float) $totais_live['total_cartao'] + (float) $totais_live['total_outros'],
        ];
        
        $total_saidas = (float) $caixa['total_saidas'];
        $saldo        = (float) $caixa['saldo_fechamento'];
        
        // Verifica se houve lançamentos em dinheiro após o fechamento
        $dinheiro_atual = (float) $totais_live['total_dinheiro'];
        $dinheiro_fechado = (float) $caixa['total_dinheiro'];
        
        // Compara com uma margem de tolerância pequena para erros de float
        if (abs($dinheiro_atual - $dinheiro_fechado) > 0.01) {
            $discrepancia_dinheiro = true;
            $diferenca_dinheiro_valor = $dinheiro_atual - $dinheiro_fechado;
        }
    } else {
        $totais       = $totais_live;
        $total_saidas = $saidas_live;
        $saldo        = (float) $caixa['valor_abertura']
                        + $totais['total_dinheiro']
                        - $total_saidas
                        - (float) $caixa['valor_sangria'];
    }

    // Lista de entradas (sis_lanc — somente leitura)
    $stmt = $pdo->prepare("
        SELECT l.id AS id_lanc, c.nome, l.login, l.formapag, l.fcartaobandeira, l.valorpag, l.datapag, l.datavenc, l.coletor
        FROM sis_lanc AS l
        JOIN sis_cliente AS c ON c.login = l.login
        WHERE l.datapag >= ? AND l.datapag <= ?
        ORDER BY l.datapag ASC
    ");
    $stmt->execute([$data_ref . ' 00:00:00', $data_ref . ' 23:59:59']);
    $entradas = $stmt->fetchAll();

    // Lista de saídas
    $stmt = $pdo->prepare("
        SELECT id, data, categoria, descricao, observacao, valor, usuario
        FROM tab_saidas
        WHERE DATE(data) = ?
        ORDER BY data
    ");
    $stmt->execute([$data_ref]);
    $saidas = $stmt->fetchAll();

    json_saida(true, '', [
        'caixa'                 => $caixa,
        'totais'                => $totais,
        'total_saidas'          => $total_saidas,
        'saldo'                 => $saldo,
        'entradas'              => $entradas,
        'saidas'                => $saidas,
        'discrepancia_dinheiro' => $discrepancia_dinheiro,
        'diferenca_dinheiro'    => $diferenca_dinheiro_valor,
    ]);
}

// =========================================================================
// Carga inicial da página (não-AJAX)
// nav/header.php já inclui config.php e gera o HTML head completo
// =========================================================================
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0">

    <!-- Cabeçalho: Título e Filtros -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px; background: #fff; padding: 10px 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
        
        <!-- Título -->
        <div style="min-width: 200px;">
            <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">Livro Caixa</h2>
            <div style="display:flex; align-items:center; gap: 8px; margin-top: 2px;">
                <div style="font-size: 12px; color: #6b7280; font-weight: 500;" id="displaySemanaAno"></div>
                <span class="lc-status-badge aberto" id="statusBadge" style="margin:0; padding: 4px 10px; font-size: 11px;">ABERTO</span>
            </div>
        </div>

        <!-- Filtros Centralizados (Navegador de Data) -->
        <div style="display: flex; gap: 8px; align-items: center; flex: 1; justify-content: center;">
            <span style="font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Data:</span>
            <button class="lc-btn-outline" onclick="navegar(-1, 'dia')" style="height: 32px; padding: 0 10px; font-size: 12px;">&#10094;</button>
            <input type="date" id="dataSelecionada" class="lc-input-date" value="<?= date('Y-m-d') ?>" style="width: 120px; text-align:center;">
            <button class="lc-btn-outline" onclick="navegar(1, 'dia')" style="height: 32px; padding: 0 10px; font-size: 12px;">&#10095;</button>
            <button class="lc-btn-black" onclick="atualizarDisplayData(); carregarDados()" style="margin-left: 5px;">
                <i class="bi bi-arrow-repeat"></i> Atualizar
            </button>
        </div>

        <!-- Botões Direita -->
        <div style="display: flex; gap: 10px; min-width: 200px; justify-content: flex-end;">
            <!-- Mantido vazio para equilibrar o flex-between -->
        </div>
    </div>

    <!-- Cards de Totais (Horizontais e Ocupando 100% da largura) -->
    <div style="display: flex; gap: 10px; margin-bottom: 15px; width: 100%; box-sizing: border-box;">
        
        <!-- Total no Banco -->
        <div style="flex: 1; min-width: 0; display:flex; flex-direction:column; justify-content:center; padding: 6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-sizing: border-box;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px; overflow: hidden;">
                    <div style="background:#eaf8fb; color:#2980b9; width: 20px; height: 20px; font-size: 11px; display:flex; align-items:center; justify-content:center; border-radius:4px; flex-shrink: 0;"><i class="bi bi-bank2"></i></div>
                    <div style="font-size:10px; font-weight:700; color:#555; text-transform:uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">TOTAL NO BANCO</div>
                </div>
                <div id="totalBanco" style="font-size:14px; font-weight:800; color:#2980b9; white-space: nowrap; flex-shrink: 0;">R$ 0,00</div>
            </div>
            <div style="font-size:10px; color:#6b7280; font-weight:500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 4px; padding-left: 28px;">PIX + Boleto + Cartão</div>
        </div>
        
        <!-- Total em Dinheiro -->
        <div style="flex: 1; min-width: 0; display:flex; flex-direction:column; justify-content:center; padding: 6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-sizing: border-box;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px; overflow: hidden;">
                    <div style="background:#eaf4f4; color:#1d9e75; width: 20px; height: 20px; font-size: 11px; display:flex; align-items:center; justify-content:center; border-radius:4px; flex-shrink: 0;"><i class="bi bi-cash-stack"></i></div>
                    <div style="font-size:10px; font-weight:700; color:#555; text-transform:uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">TOTAL EM DINHEIRO</div>
                </div>
                <div id="saldoDinheiro" style="font-size:14px; font-weight:800; color:#1d9e75; white-space: nowrap; flex-shrink: 0;">R$ 0,00</div>
            </div>
            <div style="font-size:10px; color:#6b7280; font-weight:500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 4px; padding-left: 28px;">Somente dinheiro</div>
        </div>
        
        <!-- Entradas -->
        <div style="flex: 1; min-width: 0; display:flex; flex-direction:column; justify-content:center; padding: 6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-sizing: border-box;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px; overflow: hidden;">
                    <div style="background:#f5eef8; color:#1d9e75; width: 20px; height: 20px; font-size: 11px; display:flex; align-items:center; justify-content:center; border-radius:4px; flex-shrink: 0;"><i class="bi bi-arrow-up-circle"></i></div>
                    <div style="font-size:10px; font-weight:700; color:#555; text-transform:uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">ENTRADAS</div>
                </div>
                <div id="totalEntradas" style="font-size:14px; font-weight:800; color:#1a1a1a; white-space: nowrap; flex-shrink: 0;">R$ 0,00</div>
            </div>
            <div id="subEntradas" style="font-size:10px; color:#666; font-weight:500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 4px; padding-left: 28px;">0 lançamentos</div>
        </div>
        
        <!-- Saídas -->
        <div style="flex: 1; min-width: 0; display:flex; flex-direction:column; justify-content:center; padding: 6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-sizing: border-box;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px; overflow: hidden;">
                    <div style="background:#fdf0ef; color:#e74c3c; width: 20px; height: 20px; font-size: 11px; display:flex; align-items:center; justify-content:center; border-radius:4px; flex-shrink: 0;"><i class="bi bi-arrow-down-circle"></i></div>
                    <div style="font-size:10px; font-weight:700; color:#555; text-transform:uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">SAÍDAS</div>
                </div>
                <div id="totalSaidas" style="font-size:14px; font-weight:800; color:#1a1a1a; white-space: nowrap; flex-shrink: 0;">R$ 0,00</div>
            </div>
            <div id="subSaidas" style="font-size:10px; color:#6b7280; font-weight:500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 4px; padding-left: 28px;">0 registros</div>
        </div>
    </div>

    <!-- Notificação de Discrepância em Dinheiro (Oculto por padrão) -->
    <div id="alertaDiscrepancia" class="lc-alert-discrepancia" style="display: none;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>ATEN&Ccedil;&Atilde;O!</strong> Foram detectados novos lan&ccedil;amentos em DINHEIRO (R$ <span id="valorDiferencaDinheiro">0,00</span>) nesta data AP&Oacute;S o fechamento do caixa.
    </div>

    <!-- Centro: Entradas e Saídas -->
    <div class="row g-3 mb-3" style="min-height: 380px;">
        <!-- Entradas -->
        <div class="col-lg-9 d-flex flex-column">
            <div class="lc-section-panel flex-fill">
                <div class="lc-section-header">
                    <span class="lc-section-title entrada-color">&#8595; Entradas do Dia</span>
                    <span class="lc-badge-count" id="badgeEntradas">0</span>
                </div>
                <div class="lc-table-wrap" id="tabelaEntradas">
                    <div class="lc-loading">Carregando...</div>
                </div>
            </div>
        </div>
        <!-- Saídas -->
        <div class="col-lg-3 d-flex flex-column">
            <div class="lc-section-panel flex-fill">
                <div class="lc-section-header">
                    <span class="lc-section-title saida-color">&#8593; Sa&iacute;das do Dia</span>
                    <button class="lc-btn-nova-saida" id="btnNovaSaida" onclick="abrirModalSaida()">
                        + Nova Sa&iacute;da
                    </button>
                </div>
                <div class="lc-table-wrap" id="tabelaSaidas">
                    <div class="lc-loading">Carregando...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Painel Inferior: Info do Caixa -->
    <div class="lc-caixa-bottom" id="caixaBottom">
        <div class="lc-caixa-info-grid">
            <div class="lc-caixa-info-item">
                <div class="lc-caixa-info-label">Caixa Inicial</div>
                <div class="lc-caixa-info-valor" id="valorAbertura">R$ 0,00</div>
            </div>
            <div class="lc-caixa-info-item" onclick="abrirRelatorioSangrias()" style="cursor: pointer;" title="Clique para ver o relatório de sangrias do mês">
                <div class="lc-caixa-info-label">Sangria</div>
                <div class="lc-caixa-info-valor sangria" id="valorSangria">R$ 0,00</div>
            </div>
            <div class="lc-caixa-info-item">
                <div class="lc-caixa-info-label">Saldo em Dinheiro</div>
                <div class="lc-caixa-info-valor saldo positivo" id="valorSaldo">R$ 0,00</div>
            </div>
        </div>
        <div class="lc-caixa-acoes" id="caixaAcoes">
            <button class="lc-btn-outline" id="btnSangria" onclick="abrirModalSangria()" style="height: 38px;">
                <i class="bi bi-dash-circle"></i> Registrar Sangria
            </button>
            <button class="lc-btn-black" id="btnFecharCaixa" onclick="confirmarFecharCaixa()" style="height: 38px; padding: 0 16px;">
                <i class="bi bi-lock-fill"></i> Fechar Caixa
            </button>
            <button class="lc-btn-black" id="btnGerarPdf" style="display: none; height: 38px; padding: 0 16px;" onclick="gerarPdfCaixa()">
                <i class="bi bi-file-earmark-pdf-fill"></i> Gerar PDF
            </button>
        </div>
    </div>

</div><!-- /container -->

<!-- ================================================================
     MODAL: Nova Saída
     ================================================================ -->
<div class="lc-overlay" id="overlayNovaSaida" onclick="fecharSeForaModal(event, 'overlayNovaSaida')">
    <div class="lc-modal-box">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="tituloModalSaida">Nova Sa&iacute;da</span>
            <button class="lc-modal-close" onclick="fecharModal('overlayNovaSaida')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <form id="formNovaSaida" onsubmit="return false;">
                <input type="hidden" id="sIdSaida" name="id_saida" value="">
                <div class="mb-3">
                    <label class="lc-label">Categoria</label>
                    <select name="categoria" id="sCategoria" class="lc-input" required>
                        <option value="">Selecione...</option>
                        <option value="Energia Eletrica">Energia El&eacute;trica</option>
                        <option value="Internet Uplink">Internet (Uplink)</option>
                        <option value="Salarios">Sal&aacute;rios</option>
                        <option value="Combustivel">Combust&iacute;vel</option>
                        <option value="Manutencao">Manuten&ccedil;&atilde;o</option>
                        <option value="Material Escritorio">Material de Escrit&oacute;rio</option>
                        <option value="Alimentacao">Alimenta&ccedil;&atilde;o</option>
                        <option value="Impostos">Impostos</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="lc-label">Descri&ccedil;&atilde;o</label>
                    <input type="text" name="descricao" id="sDescricao" class="lc-input"
                           placeholder="Descreva a saida..." required maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="lc-label">Valor (R$)</label>
                    <input type="number" name="valor" id="sValor" class="lc-input"
                           step="0.01" min="0.01" placeholder="0,00" required>
                </div>
            </form>
        </div>
        <div class="lc-modal-footer">
            <button class="lc-btn-cancel" onclick="fecharModal('overlayNovaSaida')">Cancelar</button>
            <button class="lc-btn-confirm danger" id="btnSalvarSaida" onclick="salvarNovaSaida()">Registrar Sa&iacute;da</button>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: Sangria
     ================================================================ -->
<div class="lc-overlay" id="overlaySangria" onclick="fecharSeForaModal(event, 'overlaySangria')">
    <div class="lc-modal-box sm">
        <div class="lc-modal-header">
            <span class="lc-modal-title">Registrar Sangria</span>
            <button class="lc-modal-close" onclick="fecharModal('overlaySangria')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <p style="font-size:13px;color:#666;margin-bottom:12px;">
                Informe o valor retirado do caixa neste dia.
            </p>
            <label class="lc-label">Valor da Sangria (R$)</label>
            <input type="number" id="inputSangria" class="lc-input lg"
                   step="0.01" min="0" placeholder="0,00">
            <div class="lc-input-hint">Deixe 0 para zerar a sangria registrada.</div>
        </div>
        <div class="lc-modal-footer">
            <button class="lc-btn-cancel" onclick="fecharModal('overlaySangria')">Cancelar</button>
            <button class="lc-btn-confirm" onclick="salvarSangria()">Confirmar</button>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: Confirmar Fechar Caixa
     ================================================================ -->
<div class="lc-overlay" id="overlayConfirmFechar" onclick="fecharSeForaModal(event,'overlayConfirmFechar')">
    <div class="lc-modal-box sm">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title"><i class="bi bi-lock-fill"></i> Fechar Caixa</span>
            <button class="lc-modal-close" onclick="fecharModal('overlayConfirmFechar')">&times;</button>
        </div>
        <div class="lc-modal-body" style="text-align:center; padding: 28px 24px 20px;">
            <div class="lc-confirm-icon fechar"><i class="bi bi-lock-fill"></i></div>
            <p class="lc-confirm-msg">Fechar o caixa do dia<br><strong id="confirmDataFechar"></strong>?</p>
            <p class="lc-confirm-sub">Esta a&ccedil;&atilde;o n&atilde;o pode ser desfeita.<br>Nenhuma altera&ccedil;&atilde;o ser&aacute; permitida ap&oacute;s o fechamento.</p>
        </div>
        <div class="lc-modal-footer">
            <button class="lc-btn-cancel" onclick="fecharModal('overlayConfirmFechar')">Cancelar</button>
            <button class="lc-btn-confirm" onclick="executarFecharCaixa()">
                <i class="bi bi-lock-fill"></i> Sim, Fechar Caixa
            </button>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: Confirmar Excluir Saída
     ================================================================ -->
<div class="lc-overlay" id="overlayConfirmExcluir" onclick="fecharSeForaModal(event,'overlayConfirmExcluir')">
    <div class="lc-modal-box sm">
        <div class="lc-modal-header lc-modal-header-danger">
            <span class="lc-modal-title"><i class="bi bi-trash3-fill"></i> Excluir Sa&iacute;da</span>
            <button class="lc-modal-close" onclick="fecharModal('overlayConfirmExcluir')">&times;</button>
        </div>
        <div class="lc-modal-body" style="text-align:center; padding: 28px 24px 20px;">
            <div class="lc-confirm-icon excluir"><i class="bi bi-trash3-fill"></i></div>
            <p class="lc-confirm-msg">Excluir esta sa&iacute;da?</p>
            <p class="lc-confirm-sub">Esta a&ccedil;&atilde;o &eacute; permanente e<br>n&atilde;o pode ser desfeita.</p>
        </div>
        <div class="lc-modal-footer">
            <button class="lc-btn-cancel" onclick="fecharModal('overlayConfirmExcluir')">Cancelar</button>
            <button class="lc-btn-confirm danger" onclick="executarExcluirSaida()">
                <i class="bi bi-trash3-fill"></i> Sim, Excluir
            </button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="lcLoadingOverlay" class="lc-loading-overlay">
    <div class="lc-loading-box">
        <div class="lc-loading-spinner"></div>
        <div class="lc-loading-text">Consultando banco de dados...</div>
    </div>
</div>

<script>
/* ================================================================
   Livro Caixa — JavaScript
   ================================================================ */

var dadosAtuais = null;
var sortColEntradas = 'hora'; // Padrão: ordenar por hora
var sortDirEntradas = 'asc';  // Padrão: crescente

var LC_MESES  = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                 'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
var LC_SEMANA = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira',
                 'Quinta-feira','Sexta-feira','Sábado'];

document.addEventListener('DOMContentLoaded', function () {
    atualizarDisplayData();
    carregarDados();
});

function showLoading() {
    document.getElementById('lcLoadingOverlay').classList.add('ativo');
}

function hideLoading() {
    document.getElementById('lcLoadingOverlay').classList.remove('ativo');
}

function getDataSelecionada() {
    return document.getElementById('dataSelecionada').value;
}

function atualizarDisplayData() {
    var val   = document.getElementById('dataSelecionada').value;
    var parts = val.split('-');
    if (parts.length !== 3) return;
    var ano = parseInt(parts[0]);
    var mes = parseInt(parts[1]) - 1;
    var dia = parseInt(parts[2]);
    var d   = new Date(ano, mes, dia);

    document.getElementById('displaySemanaAno').textContent = LC_SEMANA[d.getDay()] + ', ' + dia + ' de ' + LC_MESES[mes] + ' de ' + ano;
}

function navegar(delta, unidade) {
    var input = document.getElementById('dataSelecionada');
    var d = new Date(input.value + 'T00:00:00');
    if (unidade === 'dia')  d.setDate(d.getDate() + delta);
    if (unidade === 'mes')  d.setMonth(d.getMonth() + delta);
    if (unidade === 'ano')  d.setFullYear(d.getFullYear() + delta);
    input.value = d.toISOString().split('T')[0];
    atualizarDisplayData();
    carregarDados();
}

function fmtMoeda(v) {
    return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}

function fmtHora(str) {
    if (!str) return '-';
    var p = str.split(' ');
    return p.length > 1 ? p[1].substring(0, 5) : str;
}

function fmtData(str) {
    if (!str) return '-';
    var p = str.split(' ')[0].split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : str;
}

function escHtml(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* --- Carregamento de Dados (AJAX) --- */
function carregarDados() {
    showLoading();
    var data = getDataSelecionada();

    fetch('index.php?ajax=dados&data=' + data + '&_=' + Date.now())
        .then(function (r) { return r.json(); })
        .then(function (json) {
            hideLoading();
            if (json.sessao_expirada) {
                window.location.href = '/admin/login.php';
                return;
            }
            if (!json.sucesso) { alert(json.mensagem); return; }
            dadosAtuais = json;
            atualizarUI(json);
        })
        .catch(function (e) { hideLoading(); console.error(e); alert('Erro ao carregar dados do caixa.'); });
}

function atualizarUI(dados) {
    var caixa   = dados.caixa;
    var totais  = dados.totais;
    var fechado = caixa.status === 'fechado';

    var badge = document.getElementById('statusBadge');
    badge.textContent = fechado ? 'FECHADO' : 'ABERTO';
    badge.className   = 'lc-status-badge ' + (fechado ? 'fechado' : 'aberto');

    document.getElementById('totalEntradas').textContent = fmtMoeda(totais.valor_total_geral);
    document.getElementById('totalSaidas').textContent   = fmtMoeda(dados.total_saidas);

    var totalBanco = (parseFloat(totais.total_pix) || 0)
                   + (parseFloat(totais.total_boleto) || 0)
                   + (parseFloat(totais.total_cartao) || 0);
    document.getElementById('totalBanco').textContent    = fmtMoeda(totalBanco);
    document.getElementById('saldoDinheiro').textContent = fmtMoeda(totais.total_dinheiro);
    document.getElementById('subEntradas').textContent   = dados.entradas.length + ' lancamento(s)';
    document.getElementById('subSaidas').textContent     = dados.saidas.length  + ' registro(s)';

    document.getElementById('valorAbertura').textContent = fmtMoeda(caixa.valor_abertura);
    document.getElementById('valorSangria').textContent  = fmtMoeda(caixa.valor_sangria);

    var saldoEl = document.getElementById('valorSaldo');
    saldoEl.textContent = fmtMoeda(dados.saldo);
    saldoEl.className   = 'lc-caixa-info-valor saldo ' + (dados.saldo >= 0 ? 'positivo' : 'negativo');

    document.getElementById('caixaAcoes').style.display     = 'flex'; // Sempre mostra a barra
    document.getElementById('btnSangria').style.display     = fechado ? 'none' : 'inline-block';
    document.getElementById('btnFecharCaixa').style.display = fechado ? 'none' : 'inline-block';
    document.getElementById('btnGerarPdf').style.display    = fechado ? 'inline-block' : 'none';
    
    document.getElementById('btnNovaSaida').style.display   = fechado ? 'none' : 'inline-block';
    
    // Notificação de discrepância de dinheiro
    var alertaDisc = document.getElementById('alertaDiscrepancia');
    if (dados.discrepancia_dinheiro) {
        document.getElementById('valorDiferencaDinheiro').textContent = parseFloat(dados.diferenca_dinheiro || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        alertaDisc.style.display = 'block';
    } else {
        alertaDisc.style.display = 'none';
    }

    renderEntradas(dados.entradas);
    renderSaidas(dados.saidas, fechado);
}

function ordenarEntradas(coluna) {
    if (sortColEntradas === coluna) {
        // Inverte a direção
        sortDirEntradas = sortDirEntradas === 'asc' ? 'desc' : 'asc';
    } else {
        // Nova coluna, padrão crescente
        sortColEntradas = coluna;
        sortDirEntradas = 'asc';
    }
    // Re-renderiza usando os dados globais
    if (dadosAtuais && dadosAtuais.entradas) {
        renderEntradas(dadosAtuais.entradas);
    }
}

function renderEntradas(entradas) {
    var wrap = document.getElementById('tabelaEntradas');
    document.getElementById('badgeEntradas').textContent = entradas.length;

    if (!entradas.length) {
        wrap.innerHTML = '<div class="lc-empty">Nenhuma entrada registrada neste dia.</div>';
        return;
    }
    
    // Clonar e Ordenar a array de entradas
    var entradasOrdenadas = entradas.slice();
    entradasOrdenadas.sort(function(a, b) {
        var valA, valB;
        switch (sortColEntradas) {
            case 'nome':
                valA = (a.nome || '').toLowerCase();
                valB = (b.nome || '').toLowerCase();
                break;
            case 'datavenc':
                valA = (a.datavenc || '');
                valB = (b.datavenc || '');
                break;
            case 'coletor':
                valA = (a.coletor || '').toLowerCase();
                valB = (b.coletor || '').toLowerCase();
                break;
            case 'forma':
                valA = (a.formapag || '').toLowerCase();
                valB = (b.formapag || '').toLowerCase();
                break;
            case 'valor':
                valA = parseFloat(a.valorpag || 0);
                valB = parseFloat(b.valorpag || 0);
                break;
            case 'hora':
            default:
                valA = (a.datapag || '');
                valB = (b.datapag || '');
                break;
        }
        
        if (valA < valB) return sortDirEntradas === 'asc' ? -1 : 1;
        if (valA > valB) return sortDirEntradas === 'asc' ? 1 : -1;
        
        // Critério de desempate: sempre hora (datapag)
        if (sortColEntradas !== 'hora') {
            if (a.datapag < b.datapag) return -1;
            if (a.datapag > b.datapag) return 1;
        }
        return 0;
    });

    var fpMap   = { pix:'pix', dinheiro:'din', boleto:'bol', cartao:'cart', outros:'out' };
    var fpLabel = { pix:'PIX', dinheiro:'Dinheiro', boleto:'Boleto', cartao:'Cartao', outros:'Outros' };

    function getSortIcon(col) {
        if (sortColEntradas !== col) return '';
        return sortDirEntradas === 'asc' ? ' &uarr;' : ' &darr;';
    }

    var html = '<table class="lc-table"><thead><tr>'
        + '<th onclick="ordenarEntradas(\'nome\')" style="cursor:pointer; user-select:none;">Nome' + getSortIcon('nome') + '</th>'
        + '<th onclick="ordenarEntradas(\'datavenc\')" style="cursor:pointer; user-select:none;">Vencimento' + getSortIcon('datavenc') + '</th>'
        + '<th onclick="ordenarEntradas(\'coletor\')" style="cursor:pointer; user-select:none;">Coletor' + getSortIcon('coletor') + '</th>'
        + '<th class="prio-6" onclick="ordenarEntradas(\'forma\')" style="cursor:pointer; user-select:none;">Forma' + getSortIcon('forma') + '</th>'
        + '<th onclick="ordenarEntradas(\'valor\')" style="cursor:pointer; user-select:none;">Valor' + getSortIcon('valor') + '</th>'
        + '<th class="prio-8" onclick="ordenarEntradas(\'hora\')" style="cursor:pointer; user-select:none;">Hora' + getSortIcon('hora') + '</th>'
        + '</tr></thead><tbody>';

    entradasOrdenadas.forEach(function (e) {
        var cls   = fpMap[e.formapag]   || 'out';
        var label = fpLabel[e.formapag] || e.formapag;
        
        var badgeHtml = '<div style="display: inline-flex; flex-direction: column; align-items: flex-start;"><span class="fp-badge fp-' + cls + '">' + label + '</span>';
        if (e.formapag === 'cartao' && e.fcartaobandeira) {
            badgeHtml += '<small style="color:#888; font-size: 11px; margin-top: 3px; padding-left: 2px;">' + escHtml(e.fcartaobandeira) + '</small>';
        }
        badgeHtml += '</div>';
        
        html += '<tr>'
            + '<td class="td-nome">' + escHtml(e.nome) + '<br><small style="color:#888; font-size: 11px;">' + escHtml(e.login) + '</small></td>'
            + '<td>' + fmtData(e.datavenc) + '</td>'
            + '<td>' + escHtml(e.coletor || '-') + '</td>'
            + '<td class="prio-6">' + badgeHtml + '</td>'
            + '<td class="td-valor entrada-valor">' + fmtMoeda(e.valorpag) + '</td>'
            + '<td class="td-hora prio-8">' + fmtHora(e.datapag) + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
}

function renderSaidas(saidas, fechado) {
    var wrap = document.getElementById('tabelaSaidas');

    if (!saidas.length) {
        wrap.innerHTML = '<div class="lc-empty">Nenhuma saida registrada neste dia.</div>';
        return;
    }

    var html = '<table class="lc-table"><thead><tr>'
        + '<th>Categoria</th><th class="prio-6">Descricao</th>'
        + '<th>Valor</th>'
        + (fechado ? '' : '<th></th>')
        + '</tr></thead><tbody>';

    saidas.forEach(function (s) {
        html += '<tr ' + (!fechado ? 'style="cursor:pointer;" onclick="abrirModalSaida(' + s.id + ', \'' + escHtml(s.categoria) + '\', \'' + escHtml(s.descricao).replace(/'/g, "\\'") + '\', ' + s.valor + ')"' : '') + '>'
            + '<td><span class="lc-cat-badge">' + escHtml(s.categoria || '-') + '</span></td>'
            + '<td class="td-desc prio-6" title="' + escHtml(s.descricao) + '">' + escHtml(s.descricao) + '</td>'
            + '<td class="td-valor saida-valor">' + fmtMoeda(s.valor) + '</td>';
        if (!fechado) {
            html += '<td><button class="lc-ab r" title="Excluir" onclick="event.stopPropagation(); excluirSaida(' + s.id + ')">&times;</button></td>';
        }
        html += '</tr>';
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
}

/* --- Modais --- */
function abrirModal(id) {
    document.getElementById(id).classList.add('ativo');
    document.body.style.overflow = 'hidden';
}

function fecharModal(id) {
    document.getElementById(id).classList.remove('ativo');
    document.body.style.overflow = '';
}

function fecharSeForaModal(event, id) {
    if (event.target.id === id) fecharModal(id);
}

function abrirModalSaida(id, categoria, descricao, valor) {
    document.getElementById('formNovaSaida').reset();
    
    var titulo = document.getElementById('tituloModalSaida');
    var btn = document.getElementById('btnSalvarSaida');
    var inputId = document.getElementById('sIdSaida');
    
    if (id) {
        titulo.innerHTML = 'Editar Sa&iacute;da';
        btn.innerHTML = 'Salvar Altera&ccedil;&otilde;es';
        inputId.value = id;
        document.getElementById('sCategoria').value = categoria;
        document.getElementById('sDescricao').value = descricao;
        document.getElementById('sValor').value = parseFloat(valor).toFixed(2);
    } else {
        titulo.innerHTML = 'Nova Sa&iacute;da';
        btn.innerHTML = 'Registrar Sa&iacute;da';
        inputId.value = '';
    }
    
    abrirModal('overlayNovaSaida');
}

function salvarNovaSaida() {
    var id_saida   = document.getElementById('sIdSaida').value;
    var categoria  = document.getElementById('sCategoria').value.trim();
    var descricao  = document.getElementById('sDescricao').value.trim();
    var valor      = parseFloat(document.getElementById('sValor').value);

    if (!categoria) { alert('Selecione uma categoria.'); return; }
    if (!descricao) { alert('Informe a descricao da saida.'); return; }
    if (!valor || valor <= 0) { alert('Informe um valor valido maior que zero.'); return; }

    var fd = new FormData();
    fd.append('data_ref',   getDataSelecionada());
    fd.append('id_saida',   id_saida);
    fd.append('categoria',  categoria);
    fd.append('descricao',  descricao);
    fd.append('valor',      valor.toFixed(2));

    showLoading();
    fetch('actions/adicionar_saida.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            hideLoading();
            if (json.sessao_expirada) {
                window.location.href = '/admin/login.php';
                return;
            }
            if (json.sucesso) { fecharModal('overlayNovaSaida'); carregarDados(); }
            else { alert(json.mensagem); }
        })
        .catch(function () { hideLoading(); alert('Erro ao registrar/editar saida.'); });
}

function abrirModalSangria() {
    var s = dadosAtuais && dadosAtuais.caixa ? parseFloat(dadosAtuais.caixa.valor_sangria) : 0;
    document.getElementById('inputSangria').value = s > 0 ? s.toFixed(2) : '';
    abrirModal('overlaySangria');
}

function salvarSangria() {
    var valor = parseFloat(document.getElementById('inputSangria').value || 0);
    if (isNaN(valor) || valor < 0) { alert('Informe um valor valido.'); return; }

    var fd = new FormData();
    fd.append('data_ref',      getDataSelecionada());
    fd.append('valor_sangria', valor.toFixed(2));

    showLoading();
    fetch('actions/registrar_sangria.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            hideLoading();
            if (json.sessao_expirada) {
                window.location.href = '/admin/login.php';
                return;
            }
            if (json.sucesso) { fecharModal('overlaySangria'); carregarDados(); }
            else { alert(json.mensagem); }
        })
        .catch(function () { hideLoading(); alert('Erro ao registrar sangria.'); });
}

var _excluirSaidaId = null;

function excluirSaida(id) {
    _excluirSaidaId = id;
    abrirModal('overlayConfirmExcluir');
}

function executarExcluirSaida() {
    fecharModal('overlayConfirmExcluir');
    var fd = new FormData();
    fd.append('id',       _excluirSaidaId);
    fd.append('data_ref', getDataSelecionada());

    showLoading();
    fetch('actions/excluir_saida.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            hideLoading();
            if (json.sessao_expirada) {
                window.location.href = '/admin/login.php';
                return;
            }
            if (json.sucesso) { carregarDados(); }
            else { alert(json.mensagem); }
        })
        .catch(function () { hideLoading(); alert('Erro ao excluir saida.'); });
}

function confirmarFecharCaixa() {
    var parts = getDataSelecionada().split('-');
    document.getElementById('confirmDataFechar').textContent =
        parts[2] + '/' + parts[1] + '/' + parts[0];
    abrirModal('overlayConfirmFechar');
}

function gerarPdfCaixa() {
    var data = getDataSelecionada();
    // Abre o relatorio PDF em nova aba
    window.open('gerar_pdf.php?data=' + data + '&sortCol=' + sortColEntradas + '&sortDir=' + sortDirEntradas, '_blank');
}

function abrirRelatorioSangrias() {
    var val = document.getElementById('dataSelecionada').value;
    var parts = val.split('-');
    if (parts.length === 3) {
        var ano = parts[0];
        var mes = parts[1];
        window.location.href = 'relatorio_sangrias.php?mes=' + mes + '&ano=' + ano;
    }
}

function executarFecharCaixa() {
    fecharModal('overlayConfirmFechar');
    var fd = new FormData();
    fd.append('data_ref', getDataSelecionada());

    showLoading();
    fetch('actions/fechar_caixa.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            hideLoading();
            if (json.sessao_expirada) {
                window.location.href = '/admin/login.php';
                return;
            }
            if (json.sucesso) { carregarDados(); }
            else { alert(json.mensagem); }
        })
        .catch(function () { hideLoading(); alert('Erro ao fechar caixa.'); });
}
</script>

<div style="position: fixed; bottom: 10px; right: 15px; font-size: 11px; color: #9ca3af; z-index: 9999; font-family: 'Inter', sans-serif; pointer-events: none;">
    By Marcelo Silvestro
</div>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js.hhvm"></script>

</body>
</html>