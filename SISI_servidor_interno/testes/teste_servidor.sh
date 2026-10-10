#!/bin/bash
# =====================================================================
#  Painel Unificado SISI · testes/teste_servidor.sh
#  Confere um servidor JÁ INSTALADO (Apache, Nginx ou IIS), SEM gravar nada:
#  bloqueios de arquivos, cabeçalhos de segurança, sessão exigida em todas
#  as leituras e bibliotecas locais.  Uso:
#      bash testes/teste_servidor.sh http://<servidor>/sisi
# =====================================================================
set -u
BASE=${1:-}; [ -z "$BASE" ] && { echo "uso: bash testes/teste_servidor.sh http://<servidor>/sisi"; exit 2; }
BASE=${BASE%/}; B="$BASE/api.php"
pass=0; fail=0
t(){ if eval "$2"; then pass=$((pass+1)); echo "  ✓ $1"; else fail=$((fail+1)); echo "  ✗ $1  → ${3:-}" | cut -c1-260; fi; }
st(){ curl -sk -o /dev/null -w '%{http_code}' "$@"; }

echo "[1] API no ar"
R=$(curl -sk "$B?ping=1"); t "api.php?ping=1 responde ok" "echo '$R' | grep -q '\"ok\":true'" "$R"
t "versão 2.0 da API" "echo '$R' | grep -q 'sisi-api-mysql 2'" "$R"
R=$(curl -sk "$B?rota=sessao"); t "rota=sessao responde (instalado: $(echo "$R" | grep -o '"instalado":[a-z]*'))" "echo '$R' | grep -q '\"logado\":false'" "$R"

echo "[2] arquivos que NUNCA podem ser servidos (esperado 403 ou 404)"
for p in config.php config.exemplo.php schema.sql .htaccess web.config uploads/ uploads/.htaccess "uploads/BAPV/" README.md; do
  c=$(st "$BASE/$p"); t "$p → $c" "[ $c = 403 ] || [ $c = 404 ]" "$c"; done
c=$(st "$BASE/vendor/"); t "listagem de pastas desligada (vendor/ → $c)" "[ $c = 403 ] || [ $c = 404 ]" "$c"

echo "[3] leituras exigem sessão (esperado 401)"
for q in "rota=estado" "all=1" "om=BAPV" "rota=pacotes" "rota=pacotes&lista=1" "rota=ciencias" "rota=auditoria" "roster=1" "rota=usuarios" "rota=foto&om=BAPV&nome=x.jpg"; do
  c=$(st "$B?$q"); t "?$q → $c" "[ $c = 401 ]" "$c"; done
c=$(st -X POST -H 'Content-Type: application/json' -d '{"om":"BAPV","dados":{}}' "$B"); t "POST de pacote sem sessão → $c" "[ $c = 401 ]" "$c"
c=$(st -X POST -H 'Content-Type: application/json' -H 'Origin: http://site-externo.example' -H 'Sec-Fetch-Site: cross-site' -d '{"login":"x","senha":"y"}' "$B?rota=login"); t "login disparado por outro site → $c" "[ $c = 403 ]" "$c"

echo "[4] cabeçalhos"
H=$(curl -sk -D - -o /dev/null "$B?ping=1")
t "API: X-Content-Type-Options nosniff" "echo '$H' | grep -qi 'x-content-type-options: *nosniff'" "$H"
t "API: sem CORS aberto para qualquer origem" "! curl -sk -D - -o /dev/null -H 'Origin: http://site-externo.example' '$B?ping=1' | grep -qi 'access-control-allow-origin'"
t "API: não expõe a versão do PHP (X-Powered-By)" "! echo '$H' | grep -qi 'x-powered-by'" "$(echo "$H" | grep -i powered)"
H=$(curl -sk -D - -o /dev/null "$BASE/index.html")
t "index.html: Content-Security-Policy (camada extra; ver README)" "echo '$H' | grep -qi 'content-security-policy'" "ausente — mod_headers/customHeaders não ativo"
t "index.html: X-Frame-Options/frame-ancestors" "echo '$H' | grep -qi 'x-frame-options\\|frame-ancestors'" "ausente"
HC=$(curl -sk -D - -o /dev/null -X POST -H 'Content-Type: application/json' -d '{"login":"verificador.inexistente","senha":"x"}' "$B?rota=login")
t "login inválido responde 401 (sem revelar se o usuário existe)" "echo '$HC' | head -1 | grep -q 401" "$(echo "$HC" | head -1)"

echo "[5] painel e bibliotecas locais (sem internet)"
I=$(curl -sk "$BASE/index.html")
t "index.html é a variante u2.30-srv" "echo \"\$I\" | grep -q 'u2.30-srv'" ""
t "nenhuma biblioteca carregada de CDN" "! echo \"\$I\" | grep -q 'cdn.jsdelivr.net\\|fonts.googleapis.com'" ""
for f in vendor/xlsx-0.18.5/xlsx.full.min.js vendor/papaparse-5.4.1/papaparse.min.js vendor/chart.js-4.4.1/chart.umd.js vendor/html2canvas-1.4.1/html2canvas.min.js vendor/jspdf-2.5.1/jspdf.umd.min.js vendor/fonts/fonts.css; do
  c=$(st "$BASE/$f"); t "$f → $c" "[ $c = 200 ]" "$c"; done
case "$BASE" in https://*) : ;; *) echo "  (aviso) endereço em http:// — a senha trafega sem cifragem na rede; recomenda-se https com certificado interno";; esac

echo; echo "$pass/$((pass+fail)) verificações aprovadas"
[ $fail -eq 0 ]
