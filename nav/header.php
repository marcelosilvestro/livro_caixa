<?php require_once(__DIR__ . '/../config.php'); ?>
<!DOCTYPE html>
<?php if (isset($_SESSION['MM_Usuario'])): ?>
<html lang="pt-BR">
<?php else: ?>
<html lang="pt-BR" class="has-navbar-fixed-top">
<?php endif; ?>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <meta name="lc-csrf" content="<?= htmlspecialchars($lc_csrf, ENT_QUOTES) ?>">
    <title>MK - AUTH :: <?php echo isset($Manifest->{'name'}) ? $Manifest->{'name'} . " - V " . $Manifest->{'version'} : 'Livro Caixa'; ?></title>

    <link href="css/vendor/grid-utilities.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

    <script src="../../scripts/jquery.js"></script>
    <script src="../../scripts/mk-auth.js"></script>
    <script>
    // Todo POST das telas leva o token CSRF da sessao (conferido por lc_exigir_post()).
    (function () {
        var token = document.querySelector('meta[name="lc-csrf"]').getAttribute('content');
        var original = window.fetch;
        window.fetch = function (url, opcoes) {
            opcoes = opcoes || {};
            if (String(opcoes.method || 'GET').toUpperCase() === 'POST') {
                var cab = new Headers(opcoes.headers || {});
                cab.set('X-CSRF-Token', token);
                opcoes.headers = cab;
            }
            return original.call(this, url, opcoes);
        };
    })();
    </script>

    <link href="css/css.css?v=<?= filemtime(__DIR__ . '/../css/css.css') ?>" rel="stylesheet" type="text/css" />
</head>
