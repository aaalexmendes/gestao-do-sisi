#!/bin/bash
# =====================================================================
#  Painel Unificado SISI · teste_api.sh · verificações da api.php 2.0
#
#  Sobe um MariaDB TEMPORÁRIO (pasta em /tmp, porta 3307) e o servidor
#  embutido do PHP (porta 8099). NÃO toca no banco nem nos arquivos reais.
#  Requisitos: bash, curl, php (com pdo_mysql), mariadb-server (mariadbd e
#  mariadb-install-db). Uso:  bash teste_api.sh
#
#  Para conferir um servidor JÁ INSTALADO (Apache/Nginx/IIS), sem gravar
#  nada nele, use:  bash testes/teste_servidor.sh http://<servidor>/sisi
# =====================================================================
set -u
AQUI=$(cd "$(dirname "$0")" && pwd)
TMPD=$(mktemp -d /tmp/sisi_teste.XXXXXX)
PDB=${PORTA_DB:-3307}; PWEB=${PORTA_WEB:-8099}
SOCK=$TMPD/mysqld.sock
M="mariadb --no-defaults --socket=$SOCK -uroot"
WWW=$TMPD/www/sisi
B="http://127.0.0.1:$PWEB/sisi/api.php"
TOKEN=token-de-instalacao-para-teste-0123456789
pass=0; fail=0

encerrar(){ [ -n "${PHPPID:-}" ] && kill $PHPPID 2>/dev/null; $M -e "SHUTDOWN" 2>/dev/null; sleep 1; rm -rf "$TMPD"; }
trap encerrar EXIT

# ---------- ambiente temporário ----------
mkdir -p $TMPD/db $WWW
U=$(id -un)
mariadb-install-db --no-defaults --datadir=$TMPD/db --user=$U --auth-root-authentication-method=normal --skip-test-db >$TMPD/install.log 2>&1 \
  || { echo "falha ao criar o MariaDB temporário (ver $TMPD/install.log)"; cat $TMPD/install.log; exit 2; }
mariadbd --no-defaults --datadir=$TMPD/db --socket=$SOCK --port=$PDB --bind-address=127.0.0.1 --user=$U >$TMPD/mariadb.log 2>&1 &
for i in $(seq 1 60); do $M -e "SELECT 1" >/dev/null 2>&1 && break; sleep 0.5; done
$M < "$AQUI/sisi/schema.sql" || { echo "falha ao importar schema.sql"; exit 2; }
$M -e "CREATE USER 'sisi_app'@'127.0.0.1' IDENTIFIED BY 'senha-app-teste'; GRANT SELECT,INSERT,UPDATE,DELETE ON sisi.* TO 'sisi_app'@'127.0.0.1'; FLUSH PRIVILEGES;"
cp -r "$AQUI/sisi/." $WWW/
sed -e "s/'db_port' => 3306/'db_port' => $PDB/" -e "s/'db_host' => 'localhost'/'db_host' => '127.0.0.1'/" \
    -e "s/TROQUE-ESTA-SENHA/senha-app-teste/" -e "s/TROQUE-ESTE-TOKEN/$TOKEN/" "$AQUI/sisi/config.exemplo.php" > $WWW/config.php
php -S 127.0.0.1:$PWEB -t $TMPD/www "$AQUI/testes/roteador_php.php" >$TMPD/php.log 2>&1 &
PHPPID=$!
for i in $(seq 1 30); do curl -s "$B?ping=1" | grep -q '"ok":true' && break; sleep 0.3; done

# ---------- utilitários ----------
t(){ if eval "$2"; then pass=$((pass+1)); echo "  ✓ $1"; else fail=$((fail+1)); echo "  ✗ $1"; echo "      → ${3:-}" | cut -c1-300; fi; }
# req JAR MÉTODO "query" [corpo-json] [cabeçalho extra...]  → corpo em $R, status em $C
req(){
  local jar=$1 met=$2 qs=$3 corpo=${4:-}; shift 4 2>/dev/null || shift $#
  local h=(-H 'Content-Type: application/json')
  if [ "$met" != GET ] && [ -z "${SEMCSRF:-}" ] && [ -f "$jar.csrf" ]; then h+=(-H "X-CSRF-Token: $(cat $jar.csrf)"); fi
  for x in "$@"; do h+=(-H "$x"); done
  if [ -n "$corpo" ]; then R=$(curl -s -b $jar -c $jar -X $met "${h[@]}" --data-binary "$corpo" -w '\n%{http_code}' "$B$qs")
  else R=$(curl -s -b $jar -c $jar -X $met "${h[@]}" -w '\n%{http_code}' "$B$qs"); fi
  C=$(echo "$R" | tail -1); R=$(echo "$R" | sed '$d')
}
jget(){ echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){ if(!is_array($j)||!array_key_exists($k,$j)){ exit; } $j=$j[$k]; } echo is_bool($j)?($j?"true":"false"):(is_array($j)?json_encode($j):$j);' "$1"; }
guarda_csrf(){ local c; c=$(jget csrf); [ -n "$c" ] && echo -n "$c" > "$1.csrf"; }
login(){ req $1 POST "?rota=login" "{\"login\":\"$2\",\"senha\":\"$3\"}"; guarda_csrf $1; }
legado(){ printf '%s' "$1$2" | sha256sum | cut -d' ' -f1; }   # sha256(salt+senha), como o painel antigo
JPG='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAQABADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDkKKKK8U/TD//Z'
PNG='iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGUlEQVR4nGOUsznBQApgIkn1qIZRDUNKAwCpbwFC9fgXLgAAAABJRU5ErkJggg=='
FALSA=$(printf '<?php echo 1; ?>' | base64)
pacote(){ # pacote OM nome-da-foto base64 mime
  echo "{\"v\":3,\"om\":\"$1\",\"omNome\":\"(informado pelo navegador)\",\"ods\":\"FALSO\",\"enviadoEm\":\"2026-10-10T12:00:00Z\",\"dados\":{\"om\":\"$1\",\"savedAt\":\"2026-10-10T12:00:00Z\",\"tarefas\":{},\"extras\":{},\"necessidades\":[],\"registros\":[{\"id\":\"r1\",\"titulo\":\"Muro\",\"fotos\":[{\"ref\":\"$2\"}]}],\"auditoria\":[{\"ts\":\"2026-10-10T11:00:00Z\",\"ev\":\"login\",\"acao\":\"Acesso ao painel\",\"det\":\"teste\",\"nome\":\"SILVA\",\"posto\":\"Cap\"}]},\"fotos\":[{\"name\":\"$2\",\"mime\":\"$4\",\"b64\":\"$3\",\"registroId\":\"r1\",\"data\":\"2026-10-09\"}]}"
}
G=$TMPD/gestor; A=$TMPD/om_a; A2=$TMPD/om_a2; BB=$TMPD/om_b; O=$TMPD/ods; X=$TMPD/anon; CC=$TMPD/om_c
touch $X

echo "[1] infraestrutura"
req $X GET "?ping=1"; t "ping responde ok" "[ \"$(jget ok)\" = true ]" "$R"
t "ping não revela versão do PHP" "! echo '$R' | grep -qi php" "$R"
req $X GET "?rota=sessao"; t "sessão: não logado e servidor não instalado" "[ \"$(jget logado)\" = false ] && [ \"$(jget instalado)\" = false ]" "$R"
t "config.php não é servido (403)" "[ \"$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PWEB/sisi/config.php)\" = 403 ]"
t "schema.sql não é servido (403)" "[ \"$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PWEB/sisi/schema.sql)\" = 403 ]"
req $X GET "?rota=estado"; t "estado sem sessão → 401" "[ $C = 401 ]" "$C $R"
req $X OPTIONS "" ; t "preflight OPTIONS → 204" "[ $C = 204 ]" "$C"
t "sem CORS aberto ('*') por padrão" "! curl -s -i -H 'Origin: http://evil.example' \"$B?ping=1\" | grep -qi 'access-control-allow-origin'"

echo "[2] instalação (gestor inicial)"
req $X POST "?rota=instalar" "{\"token\":\"errado\",\"login\":\"gestor\",\"nome\":\"Gestor Teste\",\"senha\":\"uma frase longa de teste\"}"
t "token de instalação errado → 401" "[ $C = 401 ]" "$C $R"
req $X POST "?rota=instalar" "{\"token\":\"$TOKEN\",\"login\":\"gestor\",\"nome\":\"Gestor Teste\",\"senha\":\"curta\"}"
t "senha curta recusada (política)" "[ $C = 400 ] && [ \"$(jget codigo)\" = politica ]" "$C $R"
req $G POST "?rota=instalar" "{\"token\":\"$TOKEN\",\"login\":\"gestor\",\"posto\":\"Cel\",\"nome\":\"Gestor Teste\",\"senha\":\"cavalo bateria grampo correto\"}"; guarda_csrf $G
t "instalação cria o gestor e abre sessão" "[ $C = 200 ] && [ \"$(jget usuario.perfil)\" = gestor ]" "$C $R"
H=$(curl -s -i -X POST -H 'Content-Type: application/json' -d "{\"login\":\"gestor\",\"senha\":\"cavalo bateria grampo correto\"}" "$B?rota=login" | grep -i '^set-cookie')
t "Set-Cookie traz HttpOnly e SameSite=Strict" "echo '$H' | grep -qi httponly && echo '$H' | grep -qi 'samesite=strict'" "$H"
req $X POST "?rota=instalar" "{\"token\":\"$TOKEN\",\"login\":\"outro\",\"nome\":\"X\",\"senha\":\"outra frase longa qualquer\"}"
t "segunda instalação é recusada (409)" "[ $C = 409 ]" "$C $R"
N=$($M -N -e "SELECT senha_hash FROM sisi.usuarios WHERE login='gestor'"); t "senha do gestor em Argon2id/bcrypt (password_hash)" "echo '$N' | grep -Eq '^\\\$(argon2id|2y)\\\$'" "$N"

echo "[3] estado do gestor"
SEMCSRF=1 req $G POST "?rota=estado" '{"meta":{"schema":4}}'
t "gravar estado sem token anti-CSRF → 403" "[ $C = 403 ] && [ \"$(jget codigo)\" = csrf ]" "$C $R"
req $G POST "?rota=estado" '{"meta":{"schema":4},"exec2026":{"rows":[{"om":"BAPV"}],"savedAt":"2026-10-10T10:00:00Z"},"msgs":{"avisos":[{"id":"m1","texto":"Aviso ação"}],"diretas":[]},"auth":{"credenciais":[{"hash":"abc","salt":"x"}]},"gas":{"url":"x","token":"segredo"}}'
t "gestor grava o estado" "[ $C = 200 ]" "$C $R"
N=$($M -N -e "SELECT conteudo FROM sisi.estado WHERE chave='sisi'"); t "estado gravado SEM credenciais nem token" "! echo '$N' | grep -q '\"auth\"' && ! echo '$N' | grep -q segredo" "$N"
req $G POST "?rota=estado" '{"x":1}' 'Origin: http://evil.example' 'Sec-Fetch-Site: cross-site'
t "gravação vinda de outro site (Origin/Sec-Fetch-Site) → 403" "[ $C = 403 ]" "$C $R"

echo "[4] usuários (cadastro pelo gestor, migração do painel antigo)"
req $G GET "?rota=usuarios"; V=$(jget versao)
t "gestor lista usuários (sem hashes)" "[ $C = 200 ] && ! echo '$R' | grep -qi 'hash\\|salt'" "$R"
S1=a1b2c3d4e5f60708; SA=$(legado $S1 'provisoria-om-a-123'); SB=$(legado $S1 'provisoria-om-b-123'); SO=$(legado $S1 'provisoria-ods-123'); SC=$(legado $S1 'provisoria-om-c-123')
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":[{\"id\":1,\"login\":\"gestor\",\"nome\":\"Gestor Teste\",\"posto\":\"Cel\",\"perfil\":\"gestor\"},
  {\"nome\":\"Ana Silva\",\"posto\":\"Cap\",\"perfil\":\"om\",\"escopo\":\"BAPV\",\"login\":\"om.bapv.silva\",\"salt\":\"$S1\",\"hash\":\"$SA\"},
  {\"nome\":\"Bruno Souza\",\"posto\":\"Ten\",\"perfil\":\"om\",\"escopo\":\"BABE\",\"email\":\"bruno.souza@fab.mil.br\",\"salt\":\"$S1\",\"hash\":\"$SB\"},
  {\"nome\":\"Carla Lima\",\"posto\":\"Maj\",\"perfil\":\"ods\",\"escopo\":\"COMPREP\",\"login\":\"ods.comprep.lima\",\"salt\":\"$S1\",\"hash\":\"$SO\"},
  {\"nome\":\"Davi Rocha\",\"posto\":\"Cap\",\"perfil\":\"om\",\"escopo\":\"AFA\",\"login\":\"om.afa.rocha\",\"salt\":\"$S1\",\"hash\":\"$SC\"}]}"
t "gestor cadastra 2 OMs do COMPREP, 1 ODS e 1 OM de outro ODS" "[ $C = 200 ] && [ \"$(jget resumo.criados)\" = 4 ]" "$C $R"
t "login gerado a partir do e-mail" "echo '$R' | grep -q 'bruno.souza@fab.mil.br\",\"nome'" "$R"
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":[]}"
NU=$($M -N -e "SELECT COUNT(*) FROM sisi.usuarios"); t "lista desatualizada é recusada (409) — nada é apagado" "[ $C = 409 ] && [ $NU = 5 ]" "$C $R"
SL=0011223344556677; HL=$(legado $SL 'senha-antiga-jsonbin')
req $G POST "?rota=usuarios" "{\"acao\":\"importar\",\"credenciais\":[{\"nome\":\"Eva Prado\",\"posto\":\"1S\",\"perfil\":\"om\",\"escopo\":\"BAPV\",\"salt\":\"$SL\",\"hash\":\"$HL\"},{\"nome\":\"Eva Prado\",\"posto\":\"1S\",\"perfil\":\"om\",\"escopo\":\"BAPV\",\"salt\":\"$SL\",\"hash\":\"$HL\"},{\"nome\":\"Sem Escopo\",\"perfil\":\"om\",\"escopo\":\"../x\",\"salt\":\"$SL\",\"hash\":\"$HL\"}]}"
NI=$(echo "$R" | grep -o '"situacao":"importado"' | wc -l); t "migração importa usuário do painel antigo e não duplica" "[ $C = 200 ] && [ $NI = 1 ] && echo '$R' | grep -q 'om.bapv.prado'" "$R"
login $TMPD/eva "om.bapv.prado" "senha-antiga-jsonbin"
t "usuário migrado entra com a senha antiga (hash herdado)…" "[ $C = 200 ] && [ \"$(jget usuario.trocaObrigatoria)\" = true ]" "$C $R"

echo "[5] login e troca obrigatória de senha"
login $A "om.bapv.silva" "senha-errada"; t "senha errada → 401 com mensagem genérica" "[ $C = 401 ] && echo '$R' | grep -q 'usuário ou senha inválidos'" "$C $R"
login $X "nao.existe" "qualquer"; t "usuário inexistente → mesma mensagem" "[ $C = 401 ] && echo '$R' | grep -q 'usuário ou senha inválidos'" "$C $R"
login $A "OM.BAPV.SILVA" "provisoria-om-a-123"; t "login (sem diferenciar maiúsculas) com senha provisória" "[ $C = 200 ] && [ \"$(jget usuario.trocaObrigatoria)\" = true ]" "$C $R"
req $A GET "?rota=estado"; t "com troca pendente, nada além da troca → 403" "[ $C = 403 ] && [ \"$(jget codigo)\" = troca_senha ]" "$C $R"
req $A POST "?rota=senha" '{"atual":"provisoria-om-a-123","nova":"curta"}'; t "nova senha curta → 400" "[ $C = 400 ]" "$C $R"
req $A POST "?rota=senha" '{"atual":"provisoria-om-a-123","nova":"sisisisisisisi"}'; t "nova senha óbvia ('sisi…') → 400" "[ $C = 400 ]" "$C $R"
req $A POST "?rota=senha" '{"atual":"provisoria-om-a-123","nova":"minha silva senha longa"}'; t "nova senha com o nome do usuário → 400" "[ $C = 400 ]" "$C $R"
req $A POST "?rota=senha" '{"atual":"outra","nova":"girassol azul na varanda"}'; t "senha atual errada → 400" "[ $C = 400 ] && [ \"$(jget codigo)\" = senha_atual ]" "$C $R"
req $A POST "?rota=senha" '{"atual":"provisoria-om-a-123","nova":"girassol azul na varanda"}'; t "troca obrigatória concluída" "[ $C = 200 ] && [ \"$(jget usuario.trocaObrigatoria)\" = false ]" "$C $R"
N=$($M -N -e "SELECT CONCAT(IFNULL(legado_hash,'NULO'),' ',LEFT(senha_hash,9)) FROM sisi.usuarios WHERE login='om.bapv.silva'"); t "hash herdado apagado; nova senha com password_hash" "echo '$N' | grep -Eq '^NULO \\\$(argon2id|2y)'" "$N"
login $X "om.bapv.silva" "provisoria-om-a-123"; t "senha provisória deixa de valer" "[ $C = 401 ]" "$C"
for u in "om.babe:$BB:provisoria-om-b-123:pinheiro verde da serra" "ods.comprep.lima:$O:provisoria-ods-123:caneca de barro amarela" "om.afa.rocha:$CC:provisoria-om-c-123:janela aberta de manha"; do
  IFS=: read lg jar sp sn <<<"$u"; [ "$lg" = om.babe ] && lg="bruno.souza@fab.mil.br"
  login $jar "$lg" "$sp"; req $jar POST "?rota=senha" "{\"atual\":\"$sp\",\"nova\":\"$sn\"}"
done
NT=$($M -N -e "SELECT COUNT(*) FROM sisi.usuarios WHERE login IN ('bruno.souza@fab.mil.br','ods.comprep.lima','om.afa.rocha') AND troca_obrigatoria=0 AND senha_hash IS NOT NULL"); t "OM B, ODS e OM C criaram as próprias senhas" "[ $NT = 3 ]" "$NT"
login $A "om.bapv.silva" "girassol azul na varanda"; t "OM A entra com a nova senha" "[ $C = 200 ] && [ \"$(jget usuario.escopo)\" = BAPV ]" "$C $R"

CK=$(grep SISI_SESS $A | awk '{print $7}'); N=$($M -N -e "SELECT COUNT(*) FROM sisi.sessoes WHERE token_hash='$CK'")
N2=$($M -N -e "SELECT COUNT(*) FROM sisi.sessoes WHERE token_hash=SHA2('$CK',256)"); t "o banco guarda só o SHA-256 do cookie (nunca o valor)" "[ $N = 0 ] && [ $N2 = 1 ]" "$N $N2"

echo "[6] limite de tentativas"
for i in 1 2 3 4 5; do login $X "om.afa.rocha" "errada-$i"; done
login $X "om.afa.rocha" "janela aberta de manha"; t "após 5 falhas o login fica bloqueado (429), mesmo com a senha certa" "[ $C = 429 ]" "$C $R"
login $X "om.bapv.silva" "girassol azul na varanda"; t "o bloqueio é por usuário: os demais continuam entrando" "[ $C = 200 ]" "$C"

echo "[7] envio de pacotes com foto"
req $A POST "" "$(pacote BAPV Muro_BAPV_1.jpg "$JPG" image/jpeg)"; t "OM A envia pacote com foto JPEG" "[ $C = 200 ] && [ \"$(jget fotosNovas)\" = 1 ]" "$C $R"
req $BB POST "" "$(pacote BABE Muro_BABE_1.png "$PNG" image/png)"; t "OM B envia pacote com foto PNG" "[ $C = 200 ] && [ \"$(jget fotosNovas)\" = 1 ]" "$C $R"
req $A POST "" "$(pacote BAPV Falsa_1.jpg "$FALSA" image/jpeg)"; t "arquivo que não é imagem é recusado (PHP disfarçado de .jpg)" "[ $C = 200 ] && echo '$R' | grep -q 'Falsa_1.jpg (não é imagem'" "$R"
t "nada executável gravado em uploads" "[ ! -f $WWW/uploads/BAPV/Falsa_1.jpg ]"
req $A POST "" "$(pacote BAPV Muro_BAPV_1.jpg "$JPG" image/jpeg)"; t "reenvio não duplica a foto" "[ \"$(jget fotosNovas)\" = 0 ]" "$R"
N=$($M -N -e "SELECT CONCAT(ods,'|',om_nome,'|',enviado_por) FROM sisi.pacotes WHERE om='BAPV'"); t "ODS e nome da OM vêm do rol; 'enviado por' vem da sessão" "echo '$N' | grep -q '^COMPREP|BASE AEREA.*|Cap Ana Silva$'" "$N"
N=$($M -N -e "SELECT COUNT(*) FROM sisi.auditoria WHERE om='BAPV'"); t "auditoria linearizada sem duplicar" "[ \"$N\" = 1 ]" "$N"
N=$($M -N -e "SELECT mime FROM sisi.fotos WHERE nome='Muro_BABE_1.png'"); t "tipo da foto detectado pelo conteúdo" "[ \"$N\" = image/png ]" "$N"

echo "[8] recuperação em outro navegador (contexto limpo)"
login $A2 "om.bapv.silva" "girassol azul na varanda"
req $A2 GET "?om=BAPV"; t "OM A recupera os próprios dados (?om=)" "[ $C = 200 ] && echo '$R' | grep -q '\"titulo\":\"Muro\"'" "$C $R"
req $A2 GET "?rota=pacotes&om=BAPV"; t "OM A recupera o próprio pacote (rota=pacotes&om=)" "[ $C = 200 ] && echo '$R' | grep -q '\"om\":\"BAPV\"'" "$C"
C=$(curl -s -b $A2 -o $TMPD/f.jpg -w '%{http_code}' "$B?rota=foto&om=BAPV&nome=Muro_BAPV_1.jpg"); t "OM A baixa a própria foto" "[ $C = 200 ] && [ \"$(base64 -w0 $TMPD/f.jpg)\" = '$JPG' ]" "$C"

echo "[9] gestor vê tudo"
req $G GET "?all=1"; t "?all=1 traz BAPV e BABE" "echo '$R' | grep -q '\"BAPV\"' && echo '$R' | grep -q '\"BABE\"'" "$R"
req $G GET "?rota=pacotes&lista=1"; NP=$(echo "$R" | grep -o '"om":"BA[A-Z]*"' | wc -l); t "rota=pacotes lista as duas OMs" "[ $NP = 2 ]" "$R"
for f in "BAPV Muro_BAPV_1.jpg image/jpeg" "BABE Muro_BABE_1.png image/png"; do set -- $f
  HD=$(curl -s -b $G -D - -o /dev/null "$B?rota=foto&om=$1&nome=$2"); t "gestor vê a foto de $1 ($3, nosniff, privada)" "echo '$HD' | grep -qi 'content-type: $3' && echo '$HD' | grep -qi 'nosniff' && echo '$HD' | grep -qi 'cache-control: private'" "$HD"; done
req $G GET "?rota=auditoria"; t "gestor consulta a auditoria de todas as OMs" "echo '$R' | grep -q BAPV && echo '$R' | grep -q BABE" "$R"

echo "[10] ataques entre OMs (todos devem falhar)"
req $A GET "?om=BABE";                        t "OM A lê dados da OM B (?om=) → 403" "[ $C = 403 ]" "$C $R"
req $A GET "?rota=pacotes&om=BABE";           t "OM A lê pacote da OM B → 403" "[ $C = 403 ]" "$C $R"
req $A GET "?rota=pacotes";                   t "OM A pede todos os pacotes → só a própria" "[ $C = 200 ] && echo '$R' | grep -q BAPV && ! echo '$R' | grep -q BABE" "$R"
req $A GET "?all=1";                          t "OM A pede ?all=1 → só a própria" "[ $C = 200 ] && ! echo '$R' | grep -q BABE" "$R"
C=$(curl -s -b $A -o /dev/null -w '%{http_code}' "$B?rota=foto&om=BABE&nome=Muro_BABE_1.png"); t "OM A baixa foto da OM B → 403" "[ $C = 403 ]" "$C"
req $A POST "" "$(pacote BABE Invasao.png "$PNG" image/png)"; t "OM A envia pacote como OM B → 403" "[ $C = 403 ]" "$C $R"
req $A POST "?om=BABE" '{"om":"BABE","tarefas":{}}'; t "OM A grava ?om=BABE → 403" "[ $C = 403 ]" "$C $R"
N=$($M -N -e "SELECT COUNT(*) FROM sisi.fotos WHERE nome='Invasao.png'"); t "nenhuma foto da tentativa foi gravada" "[ \"$N\" = 0 ]" "$N"
req $A POST "?rota=estado" '{"meta":{}}';     t "OM A grava o estado → 403" "[ $C = 403 ]" "$C $R"
req $A GET "?rota=usuarios";                  t "OM A lista usuários → 403" "[ $C = 403 ]" "$C $R"
req $A POST "?roster=1" '{"BAPV":{"ods":"COMGEP"}}'; t "OM A altera o rol (escopo) → 403" "[ $C = 403 ]" "$C $R"
req $A GET "?rota=auditoria&om=BABE";         t "OM A lê auditoria da OM B → 403" "[ $C = 403 ]" "$C $R"
req $A GET "?rota=auditoria";                 t "OM A pede toda a auditoria → só a própria" "[ $C = 200 ] && ! echo '$R' | grep -q BABE" "$R"
C=$(curl -s -o /dev/null -w '%{http_code}' "$B?rota=foto&om=BABE&nome=Muro_BABE_1.png"); t "foto sem sessão → 401" "[ $C = 401 ]" "$C"
for nm in "../config.php" "..%2Fconfig.php" "Muro_BABE_1.png%00.jpg" "x.php"; do
  C=$(curl -s -b $G -o /dev/null -w '%{http_code}' "$B?rota=foto&om=BABE&nome=$nm"); t "nome de foto malicioso ($nm) → 400" "[ $C = 400 ]" "$C"; done
t "acesso direto a uploads/BABE/ → 403" "[ \"$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PWEB/sisi/uploads/BABE/Muro_BABE_1.png)\" = 403 ]"
SEMCSRF=1 req $A POST "" "$(pacote BAPV Muro_BAPV_1.jpg "$JPG" image/jpeg)"; t "envio sem token anti-CSRF → 403" "[ $C = 403 ]" "$C $R"
C=$(curl -s -H "Cookie: SISI_SESS=$(printf 'a%.0s' $(seq 1 64))" -o /dev/null -w '%{http_code}' "$B?rota=estado"); t "cookie forjado → 401" "[ $C = 401 ]" "$C"
req $A2 POST "?rota=logout" '{}'; req $A2 GET "?om=BAPV"; t "após logout a sessão não vale mais → 401" "[ $C = 401 ]" "$C"

$M -e "UPDATE sisi.sessoes s JOIN sisi.usuarios u ON u.id=s.usuario_id SET s.expira_em = NOW() - INTERVAL 1 MINUTE WHERE u.login='bruno.souza@fab.mil.br'"
req $BB GET "?om=BABE"; t "sessão expirada (8 h) → 401" "[ $C = 401 ] && [ \"$(jget codigo)\" = sessao ]" "$C $R"
N=$($M -N -e "SELECT TIMESTAMPDIFF(MINUTE, criada_em, expira_em) FROM sisi.sessoes ORDER BY criada_em DESC LIMIT 1"); t "validade da sessão = 8 h" "[ \"$N\" = 480 ]" "$N"
login $BB "bruno.souza@fab.mil.br" "pinheiro verde da serra"

echo "[11] perfil ODS (somente consulta das OMs do seu ODS)"
req $O GET "?om=BAPV";                    t "ODS COMPREP lê OM A" "[ $C = 200 ]" "$C $R"
req $O GET "?rota=pacotes";               t "ODS vê as duas OMs do COMPREP" "echo '$R' | grep -q BAPV && echo '$R' | grep -q BABE" "$R"
C=$(curl -s -b $O -o /dev/null -w '%{http_code}' "$B?rota=foto&om=BABE&nome=Muro_BABE_1.png"); t "ODS vê foto de OM do seu ODS" "[ $C = 200 ]" "$C"
req $CC POST "" "$(pacote AFA Muro_AFA_1.png "$PNG" image/png)"
req $O GET "?om=AFA";                     t "ODS COMPREP lê OM de outro ODS (AFA/COMGEP) → 403" "[ $C = 403 ]" "$C $R"
req $O GET "?all=1";                      t "ODS ?all=1 não traz OM de outro ODS" "! echo '$R' | grep -q '\"AFA\"'" "$R"
req $O POST "" "$(pacote BAPV Ods.png "$PNG" image/png)"; t "ODS envia pacote de OM → 403" "[ $C = 403 ]" "$C $R"
req $O POST "?om=BAPV" '{"om":"BAPV"}';   t "ODS grava ?om= → 403" "[ $C = 403 ]" "$C $R"
req $O POST "?rota=estado" '{"x":1}';     t "ODS grava o estado → 403" "[ $C = 403 ]" "$C $R"
req $O POST "?roster=1" '{}';             t "ODS altera o rol → 403" "[ $C = 403 ]" "$C $R"
req $O POST "" '{"rota":"ciencia","msgId":"m1","texto":"Aviso","usuario":"FORJADO","perfil":"gestor","escopo":"BAPV"}'
t "ODS registra a própria ciência" "[ $C = 200 ]" "$C $R"
N=$($M -N -e "SELECT CONCAT(usuario,'|',perfil,'|',escopo) FROM sisi.ciencias ORDER BY id DESC LIMIT 1"); t "ciência gravada com usuário/perfil/escopo da sessão (não do navegador)" "[ \"$N\" = 'Maj Carla Lima|ods|COMPREP' ]" "$N"
req $A POST "" '{"rota":"ciencia","msgId":"m1","texto":"Aviso"}'; req $CC POST "" '{"rota":"ciencia","msgId":"m1","texto":"Aviso"}'
req $O GET "?rota=ciencias"; t "ODS vê ciências do seu ODS, não as de outro" "echo '$R' | grep -q BAPV && ! echo '$R' | grep -q AFA" "$R"
req $A GET "?rota=ciencias"; t "OM vê só as próprias ciências" "echo '$R' | grep -q BAPV && ! echo '$R' | grep -q COMPREP" "$R"

echo "[12] o que cada perfil lê do estado"
req $A GET "?rota=estado"; t "OM lê o estado (avisos, execução)…" "[ $C = 200 ] && echo '$R' | grep -q 'Aviso ação'" "$C $R"
t "…sem credenciais, hashes ou tokens" "! echo '$R' | grep -qi '\"auth\"\\|hash\\|salt\\|segredo'" "$R"

echo "[13] gestão de acessos"
req $G GET "?rota=usuarios"; V=$(jget versao); ID=$(echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach($j["usuarios"] as $u) if($u["login"]=="om.bapv.silva") echo $u["id"];')
LISTA=$(echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $o=[]; foreach($j["usuarios"] as $u){ if($u["login"]=="om.afa.rocha") continue; if($u["login"]=="om.bapv.silva"){ $u["salt"]="ffee"; $u["hash"]=hash("sha256","ffee"."nova-provisoria-123"); } $o[]=$u; } echo json_encode($o);')
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$LISTA}"
t "gestor redefine a senha da OM A e remove a OM C" "[ $C = 200 ] && [ \"$(jget resumo.senhas)\" = 1 ] && [ \"$(jget resumo.removidos)\" = 1 ]" "$C $R"
req $A GET "?om=BAPV"; t "sessão da OM A cai com a redefinição" "[ $C = 401 ]" "$C"
req $CC GET "?om=AFA"; t "usuário removido perde a sessão na hora" "[ $C = 401 ]" "$C"
login $A "om.bapv.silva" "nova-provisoria-123"; t "OM A entra com a provisória e é obrigada a trocar" "[ \"$(jget usuario.trocaObrigatoria)\" = true ]" "$R"
req $G GET "?rota=usuarios"; V=$(jget versao)
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":[]}"
t "gestor não pode remover a si mesmo (nem o último gestor)" "[ $C = 400 ]" "$C $R"

echo "[14] endurecimento (achados da revisão independente)"
N=$(seq 1 20 | xargs -P20 -I{} curl -s -o /dev/null -w '%{http_code}\n' -H 'Content-Type: application/json' -d '{"login":"ods.comprep.lima","senha":"rajada-{}"}' "$B?rota=login" | grep -c 401)
t "rajada de 20 logins paralelos: no máximo 5 senhas testadas (resto 429)" "[ $N -le 5 ]" "$N testadas"
$M -e "DELETE FROM sisi.login_tentativas WHERE login='ods.comprep.lima'"
N=$($M -N -e "SELECT LEFT(legado_hash,9) FROM sisi.usuarios WHERE login='om.bapv.silva'"); t "senha provisória guardada embrulhada em password_hash (não sha256 puro)" "echo '$N' | grep -Eq '^\\\$(argon2id|2y)'" "$N"
T1=$(curl -s -o /dev/null -w '%{time_total}' -H 'Content-Type: application/json' -d '{"login":"om.bapv.silva","senha":"errada-x"}' "$B?rota=login")
T2=$(curl -s -o /dev/null -w '%{time_total}' -H 'Content-Type: application/json' -d '{"login":"ninguem.aqui","senha":"errada-x"}' "$B?rota=login")
t "tempo do login igual para senha provisória e usuário inexistente ($T1 s × $T2 s)" "php -r 'exit(abs($T1-$T2) < max(0.05, 0.5*max($T1,$T2)) && $T1 > 0.02 ? 0 : 1);'"
req $BB POST "" "$(pacote BABE Muro_BABE_1.png "$(echo $JPG)" image/jpeg)"
t "foto com mesmo nome e conteúdo diferente: não sobrescreve, recebe nome novo" "[ $C = 200 ] && echo '$R' | grep -q 'Muro_BABE_1_[0-9a-f]\{8\}.png'" "$R"
req $BB GET "?om=BABE"; t "a referência no registro passa a apontar para o arquivo novo" "echo '$R' | grep -q 'Muro_BABE_1_[0-9a-f]\{8\}.png'" "$R"
C=$(curl -s -b $G -o $TMPD/f2 -w '%{http_code}' "$B?rota=foto&om=BABE&nome=Muro_BABE_1.png"); t "a foto antiga continua intacta" "[ $C = 200 ] && [ \"\$(base64 -w0 $TMPD/f2)\" = '$PNG' ]" "$C"
N=$($M -N -e "SELECT COUNT(DISTINCT enviado_por_login) FROM sisi.auditoria WHERE om='BABE' AND enviado_por_login='bruno.souza@fab.mil.br'"); t "auditoria grava o usuário da sessão que enviou" "[ \"$N\" = 1 ]" "$N"
req $G GET "?rota=usuarios"; V=$(jget versao); L0=$(echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j["usuarios"]);')
L1=$(echo "$L0" | php -r '$u=json_decode(stream_get_contents(STDIN),true); $u[]=["nome"=>"Joao Mais","perfil"=>"om","escopo"=>"BAPV","email"=>"joao+sisi@fab.mil.br","salt"=>"ab","hash"=>hash("sha256","ab"."provisoria-joao-1")]; echo json_encode($u);')
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$L1}"
t "e-mail com '+' não vira login inválido (login gerado)" "[ $C = 200 ] && echo '$R' | grep -q '\"login\":\"om.bapv.mais\"'" "$R"
V=$(jget versao); L2=$(echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j["usuarios"]);')
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$L2}"; t "reenviar a lista sem mudanças é aceito" "[ $C = 200 ]" "$C $R"
V=$(jget versao); L3=$(echo "$L2" | php -r '$u=json_decode(stream_get_contents(STDIN),true); $u[]=["nome"=>"<img src=x onerror=alert(1)>","perfil"=>"om","escopo"=>"BAPV","salt"=>"ab","hash"=>hash("sha256","ab"."x-provisoria-1")]; echo json_encode($u);')
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$L3}"; t "nome com HTML (<img onerror>) é recusado" "[ $C = 400 ]" "$C $R"
( req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$L1}" ) & PJ=$!
req $G POST "?rota=usuarios" "{\"acao\":\"sincronizar\",\"versao\":\"$V\",\"credenciais\":$L1}"; wait $PJ
NJ=$($M -N -e "SELECT COUNT(*) FROM sisi.usuarios WHERE nome='Joao Mais'"); t "duas gravações simultâneas não duplicam usuário" "[ $NJ = 1 ]" "$NJ"
C=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Origin: http://127.0.0.1:9999" -d '{"login":"x","senha":"y"}' "$B?rota=login"); t "Origin com porta diferente é recusada (403)" "[ $C = 403 ]" "$C"
C=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Origin: http://127.0.0.1:$PWEB" -d '{"login":"x","senha":"y"}' "$B?rota=login"); t "Origin exata do próprio servidor é aceita" "[ $C = 401 ]" "$C"

echo "[15] registros de segurança"
N=$($M -N -e "SELECT GROUP_CONCAT(DISTINCT evento ORDER BY evento) FROM sisi.seguranca_log")
for ev in login login_falha login_bloqueado negado senha_trocada usuario_criado usuario_removido senha_redefinida usuarios_importados logout instalado; do
  t "seguranca_log registra '$ev'" "echo ',$N,' | grep -q ',$ev,'" "$N"; done
N=$($M -N -e "SELECT COUNT(*) FROM sisi.api_log"); t "api_log registrou as chamadas ($N)" "[ \"$N\" -gt 40 ]" "$N"

echo; echo "$pass/$((pass+fail)) verificações aprovadas"
[ $fail -eq 0 ]
