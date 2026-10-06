<?php
include('addons.class.php');

// Garante que o OPcache não sirva bytecode antigo deste arquivo
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

if (!file_exists(__DIR__ . '/../../login.hhvm')) {
    $ext_mk = '.php';
    session_name('mka');
    if (!isset($_SESSION)) session_start();
    if (!isset($_SESSION['mka_logado'])) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['sessao_expirada' => true, 'status' => false, 'msg' => 'Sessão expirada. Redirecionando...']);
            exit;
        }
        exit('Acesso negado. <a href="/admin/login.php">Fazer Login</a>');
    }
} else {
    $ext_mk = '.hhvm';
    // Sessão já gerenciada pelo MK-AUTH em modo HHVM
}

// Acesso ao banco fora do webroot. Primeiro o arquivo do addon (escrito pelo instalador);
// se nao existir, o bloco 'db' do secrets.php compartilhado (lido, nunca escrito).
$lc_db = null;
foreach (['/opt/mk-auth/conf/livro_caixa.php', '/opt/mk-auth/conf/secrets.php'] as $lc_conf) {
    if (is_readable($lc_conf)) {
        $lc_c = require $lc_conf;
        if (is_array($lc_c) && isset($lc_c['db']) && is_array($lc_c['db'])) {
            $lc_db = $lc_c['db'];
            break;
        }
    }
}
unset($lc_c, $lc_conf);
if ($lc_db === null) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de configuracao: acesso ao banco indisponivel.']);
        exit;
    }
    exit('Erro de configuracao: rode o instalador do addon (ele cria /opt/mk-auth/conf/livro_caixa.php).');
}

$servername = (string) ($lc_db['host'] ?? '127.0.0.1');
$username   = (string) ($lc_db['user'] ?? 'root');
$password   = (string) ($lc_db['pass'] ?? '');
$dbname     = (string) ($lc_db['name'] ?? 'mkradius');
unset($lc_db);

// Conexão MySQLi requerida pelo topo.php do MK-Auth
$link = mysqli_connect($servername, $username, $password, $dbname);
if (!$link) {
    die("Falha na conexao com o banco de dados MySQL: " . mysqli_connect_error());
}

try {
    $pdo = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    json_saida(false, 'Erro de conexão com banco de dados.');
}

$usuario_logado = $_SESSION['MKA_Usuario'] ?? $_SESSION['MM_Usuario'] ?? 'sistema';

// Token CSRF da sessao: vai no <meta name="lc-csrf"> (nav/header.php) e todo POST o devolve
// no cabecalho X-CSRF-Token. Sem ele, outra pagina aberta no navegador do operador poderia
// disparar escrita no caixa usando a sessao do MK-AUTH.
if (empty($_SESSION['lc_csrf'])) {
    $_SESSION['lc_csrf'] = bin2hex(random_bytes(16));
}
$lc_csrf = (string) $_SESSION['lc_csrf'];

/**
 * Para toda escrita: exige POST e o token CSRF da sessao. A recusa sai nos dois formatos de
 * resposta do addon ({sucesso, mensagem} das actions e {status, msg} da conferencia).
 */
function lc_exigir_post(): void
{
    global $lc_csrf;
    $msg = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $msg = 'Método inválido.';
    } elseif (!hash_equals($lc_csrf, (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? ''))) {
        $msg = 'Sessão desatualizada. Recarregue a página e tente de novo.';
    }
    if ($msg !== null) {
        json_saida(false, $msg, ['status' => false, 'msg' => $msg]);
    }
}

function json_saida(bool $sucesso, string $mensagem, array $extras = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode(array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $extras));
    exit;
}

function valida_data(string $data): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $data);
}

function get_ou_criar_caixa(PDO $pdo, string $data_ref): array
{
    $stmt = $pdo->prepare("SELECT * FROM tab_caixa_diario WHERE data_referencia = ?");
    $stmt->execute([$data_ref]);
    $caixa = $stmt->fetch();

    if ($caixa) {
        return $caixa;
    }

    // Busca saldo do último dia fechado anterior
    $stmt = $pdo->prepare(
        "SELECT saldo_fechamento FROM tab_caixa_diario
         WHERE data_referencia < ? AND status = 'fechado'
         ORDER BY data_referencia DESC LIMIT 1"
    );
    $stmt->execute([$data_ref]);
    $anterior       = $stmt->fetch();
    $valor_abertura = $anterior ? (float) $anterior['saldo_fechamento'] : 0.00;

    $stmt = $pdo->prepare(
        "INSERT INTO tab_caixa_diario (data_referencia, valor_abertura, valor_sangria, status)
         VALUES (?, ?, 0.00, 'aberto')
         ON DUPLICATE KEY UPDATE id = id"
    );
    $stmt->execute([$data_ref, $valor_abertura]);

    $stmt = $pdo->prepare("SELECT * FROM tab_caixa_diario WHERE data_referencia = ?");
    $stmt->execute([$data_ref]);
    return $stmt->fetch();
}

function get_totais_entradas(PDO $pdo, string $data_ref): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN formapag = 'pix'      THEN valorpag ELSE 0 END), 0) AS total_pix,
            COALESCE(SUM(CASE WHEN formapag = 'dinheiro' THEN valorpag ELSE 0 END), 0) AS total_dinheiro,
            COALESCE(SUM(CASE WHEN formapag = 'boleto'   THEN valorpag ELSE 0 END), 0) AS total_boleto,
            COALESCE(SUM(CASE WHEN formapag = 'cartao'   THEN valorpag ELSE 0 END), 0) AS total_cartao,
            COALESCE(SUM(CASE WHEN formapag = 'outros'   THEN valorpag ELSE 0 END), 0) AS total_outros,
            COALESCE(SUM(valorpag), 0)                                                  AS valor_total_geral
        FROM sis_lanc
        WHERE datapag >= ? AND datapag <= ?
    ");
    $stmt->execute([$data_ref . ' 00:00:00', $data_ref . ' 23:59:59']);
    return array_map('floatval', $stmt->fetch());
}

function get_total_saidas(PDO $pdo, string $data_ref): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(valor), 0) AS total FROM tab_saidas WHERE DATE(data) = ?"
    );
    $stmt->execute([$data_ref]);
    return (float) $stmt->fetch()['total'];
}
