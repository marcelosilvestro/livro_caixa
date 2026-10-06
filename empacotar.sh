#!/usr/bin/env bash
#
# livro_caixa :: monta o pacote que as empresas instalam.
#
#   ./empacotar.sh              usa a versao do manifest.json
#   ./empacotar.sh 1.0.1        forca outra versao (nao altera o manifest)
#
# Gera pacote/livro_caixa-<versao>.tar.gz e pacote/SHA256SUMS. O que NAO entra:
#
#   addons.class.php  arquivo do MK-AUTH; o instalador copia o do proprio servidor
#   .git* empacotar.sh  coisa de desenvolvimento
#
# Antes de fechar, varre o pacote atras de infraestrutura fixa (IP, host, caminho do ambiente
# de desenvolvimento): o addon vai para outras empresas e nada disso pode estar no codigo.
set -euo pipefail

cd "$(dirname "$0")"

VERSAO="${1:-$(php -r '$m = json_decode(file_get_contents("manifest.json"), true); echo $m["version"] ?? "";' 2>/dev/null || true)}"
if [ -z "$VERSAO" ]; then
    VERSAO=$(grep -o '"version"[^,]*' manifest.json | head -1 | sed 's/.*: *"\{0,1\}//; s/"\{0,1\}$//')
fi
[ -n "$VERSAO" ] || { echo "Nao consegui descobrir a versao (manifest.json)"; exit 1; }

DESTINO="pacote"
NOME="livro_caixa-$VERSAO.tar.gz"

rm -rf "$DESTINO/staging"
mkdir -p "$DESTINO/staging"

tar cf - \
    --exclude='./pacote' \
    --exclude='./.git*' \
    --exclude='./addons.class.php' \
    --exclude='./empacotar.sh' \
    --exclude='*.bak' \
    . | (cd "$DESTINO/staging" && tar xf -)

if [ -e "$DESTINO/staging/addons.class.php" ]; then
    echo "ERRO: addons.class.php entrou no pacote"; exit 1
fi
for obrigatorio in manifest.json config.php index.php conferencia.php relatorio_sangrias.php gerar_pdf.php \
                   instalar.sh nav/header.php css/css.css sql/baseline.sql actions/fechar_caixa.php \
                   js/vendor/sweetalert2.all.min.js js/vendor/xlsx.full.min.js; do
    if [ ! -e "$DESTINO/staging/$obrigatorio" ]; then
        echo "ERRO: falta $obrigatorio no pacote"; exit 1
    fi
done

# Varredura de infraestrutura fixa. IPs de exemplo de documentacao (RFC 5737) e loopback
# sao permitidos; qualquer outro IPv4 no codigo e erro. Bibliotecas de terceiros (vendor/)
# ficam fora da varredura: o codigo minificado tem sequencias numericas que parecem IP.
SUSPEITOS=$(grep -rEn --exclude-dir=vendor --include='*.php' --include='*.js' --include='*.sh' \
    '([0-9]{1,3}\.){3}[0-9]{1,3}' "$DESTINO/staging" \
    | grep -vE '127\.0\.0\.1|192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|0\.0\.0\.0' \
    | grep -vE 'versao|version|V[0-9]+\.[0-9]+\.[0-9]+' || true)
DEV=$(grep -rEn --exclude-dir=vendor 'silvestro\.app|172\.31\.|192\.168\.88\.|D:\\|/root/dev' "$DESTINO/staging" \
    --include='*.php' --include='*.js' --include='*.sh' --include='*.css' || true)
if [ -n "$SUSPEITOS$DEV" ]; then
    echo "ERRO: infraestrutura fixa no pacote:"
    printf '%s\n%s\n' "$SUSPEITOS" "$DEV" | sed '/^$/d; s/^/  /'
    exit 1
fi

rm -rf "$DESTINO/livro_caixa"
mv "$DESTINO/staging" "$DESTINO/livro_caixa"
(cd "$DESTINO" && tar czf "$NOME" livro_caixa && rm -rf livro_caixa)
(cd "$DESTINO" && sha256sum "$NOME" > SHA256SUMS)

echo "pacote/$NOME"
echo "$(tar tzf "$DESTINO/$NOME" | wc -l) arquivos, $(du -h "$DESTINO/$NOME" | cut -f1)"
cat "$DESTINO/SHA256SUMS"
