#!/bin/bash
set -e
mkdir -p /run/mysqld
mariadbd --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --port=3307 --user=root >/tmp/mariadb.log 2>&1 &
for i in $(seq 1 30); do mariadb --socket=/run/mysqld/mysqld.sock -e "SELECT 1" >/dev/null 2>&1 && break; sleep 0.5; done
M="mariadb --socket=/run/mysqld/mysqld.sock"
$M -e "DROP DATABASE IF EXISTS sisi"; $M < schema.sql
$M -e "CREATE USER IF NOT EXISTS 'sisi_app'@'127.0.0.1' IDENTIFIED BY 'senha-teste'; GRANT SELECT,INSERT,UPDATE,DELETE ON sisi.* TO 'sisi_app'@'127.0.0.1'; FLUSH PRIVILEGES;"
echo "tabelas: $($M -N -e 'SHOW TABLES FROM sisi' | tr '\n' ' ')"
sed "s/'db_port' => 3306/'db_port' => 3307/; s/TROQUE-ESTA-SENHA/senha-teste/; s/TROQUE-ESTE-TOKEN/tok-teste-123/; s/'db_host' => 'localhost'/'db_host' => '127.0.0.1'/" config.exemplo.php > config.php
php -S 127.0.0.1:8099 -t . >/tmp/php.log 2>&1 &
sleep 1.5
B="http://127.0.0.1:8099/api.php"
pass=0; fail=0
t(){ if eval "$2"; then pass=$((pass+1)); echo "  ✓ $1"; else fail=$((fail+1)); echo "  ✗ $1  → $3"; fi; }

echo "[1] ping"
R=$(curl -s "$B?ping=1"); t "ping responde ok" "echo '$R' | grep -q '\"ok\":true'" "$R"

echo "[2] estado (substituto do jsonbin)"
R=$(curl -s "$B?rota=estado"); t "estado vazio devolve record:null" "echo '$R' | grep -q '\"record\":null'" "$R"
R=$(curl -s -X POST "$B?rota=estado" -H 'Content-Type: application/json' -d '{"meta":{"schema":4},"exec2026":{"rows":[{"om":"BAPV","nd":"339039","credito_bloqueado":1}],"savedAt":"2026-10-01T10:00:00Z"},"auth":{"x":1}}')
t "gravar sem token é recusado (401)" "echo '$R' | grep -q 'token inválido'" "$R"
R=$(curl -s -X POST "$B?rota=estado" -H 'Content-Type: application/json' -H 'X-Token: tok-teste-123' -d '{"meta":{"schema":4},"exec2026":{"rows":[{"om":"BAPV","nd":"339039","credito_bloqueado":1}],"savedAt":"2026-10-01T10:00:00Z"},"auth":{"x":1}}')
t "gravar com token X-Token" "echo '$R' | grep -q '\"ok\":true'" "$R"
R=$(curl -s -X PUT "$B?rota=estado" -H 'Content-Type: application/json' -H 'X-Master-Key: tok-teste-123' -d '{"meta":{"schema":4},"exec2026":{"rows":[{"om":"BAPV"}],"savedAt":"2026-10-01T11:00:00Z"},"rap":{"rows":[1,2]}}')
t "PUT com X-Master-Key (cabeçalho que o painel já usa)" "echo '$R' | grep -q '\"ok\":true'" "$R"
R=$(curl -s "$B?rota=estado"); t "GET devolve no formato jsonbin {record:{...}}" "echo '$R' | grep -q '\"record\":{\"meta\"' && echo '$R' | grep -q '\"rap\":{\"rows\":\\[1,2\\]}'" "$R"
t "acentos preservados (UTF-8)" "curl -s -X POST \"$B?rota=estado&chave=t\" -H 'X-Token: tok-teste-123' -d '{\"m\":\"ação ü\"}' >/dev/null && curl -s \"$B?rota=estado&chave=t\" | grep -q 'ação ü'" ""

echo "[3] pacote da OM (contrato do Apps Script)"
B64=$(printf 'JFIF-fake-bytes' | base64)
PKG="{\"v\":3,\"om\":\"BAPV\",\"omNome\":\"BASE AEREA DE PORTO VELHO\",\"ods\":\"COMPREP\",\"enviadoEm\":\"2026-10-01T12:00:00Z\",\"token\":\"tok-teste-123\",\"dados\":{\"om\":\"BAPV\",\"savedAt\":\"2026-10-01T12:00:00Z\",\"tarefas\":{\"a\":{\"pct\":40}},\"registros\":[{\"id\":\"r1\",\"fotos\":[{\"ref\":\"r1_1.jpg\"}]}],\"necessidades\":[],\"governanca\":{\"osd\":{\"nome\":\"SILVA\"}},\"auditoria\":[{\"ts\":\"2026-10-01T09:00:00Z\",\"ev\":\"login\",\"acao\":\"Acesso ao painel\",\"det\":\"Cap SILVA\",\"nome\":\"SILVA\",\"posto\":\"Cap\"},{\"ts\":\"2026-10-01T09:05:00Z\",\"ev\":\"edicao\",\"acao\":\"Percentual alterado\",\"det\":\"Muro → 40%\",\"nome\":\"SILVA\",\"posto\":\"Cap\",\"n\":3}]},\"fotos\":[{\"name\":\"r1_1.jpg\",\"mime\":\"image/jpeg\",\"b64\":\"$B64\",\"registroId\":\"r1\",\"data\":\"2026-09-30\"}]}"
R=$(curl -s -X POST "$B" -H 'Content-Type: application/json' -d "$PKG")
t "POST pacote → ok, fotosNovas=1" "echo '$R' | grep -q '\"ok\":true' && echo '$R' | grep -q '\"fotosNovas\":1'" "$R"
t "foto gravada em uploads/BAPV/" "test -f uploads/BAPV/r1_1.jpg && [ \"\$(cat uploads/BAPV/r1_1.jpg)\" = 'JFIF-fake-bytes' ]" "$(ls uploads 2>&1)"
R=$(curl -s -X POST "$B" -H 'Content-Type: application/json' -d "$PKG")
t "reenvio: foto não duplica (fotosNovas=0)" "echo '$R' | grep -q '\"fotosNovas\":0'" "$R"
R=$(curl -s -X POST "$B" -H 'Content-Type: application/json' -d "$(echo "$PKG" | sed 's/tok-teste-123/errado/')")
t "pacote com token errado → 401" "echo '$R' | grep -q 'token inválido'" "$R"
R=$(curl -s "$B?rota=pacotes&lista=1"); t "rota=pacotes&lista=1 lista a OM" "echo '$R' | grep -q '\"om\":\"BAPV\"' && echo '$R' | grep -q '\"pacotes\":\\['" "$R"
R=$(curl -s "$B?rota=pacotes"); t "rota=pacotes devolve o pacote completo com dados" "echo '$R' | grep -q '\"tarefas\":{\"a\":{\"pct\":40}}' && echo '$R' | grep -q '\"governanca\"'" "$(echo $R | cut -c1-200)"
t "fotos não voltam em base64 (ficam em arquivo)" "! echo '$R' | grep -q '\"b64\"'" ""
R=$(curl -s "$B?rota=pacotes&om=BAPV"); t "rota=pacotes&om=BAPV (sincronização da OM)" "echo '$R' | grep -q '\"om\":\"BAPV\"'" "$R"
R=$(curl -s "$B?rota=foto&om=BAPV&nome=r1_1.jpg"); t "rota=foto serve o arquivo" "[ \"$R\" = 'JFIF-fake-bytes' ]" "$R"
N=$($M -N -e "SELECT COUNT(*) FROM sisi.pacotes_hist WHERE om='BAPV'"); t "histórico de envios: 2 registros" "[ \"$N\" = '2' ]" "$N"
N=$($M -N -e "SELECT COUNT(*) FROM sisi.auditoria WHERE om='BAPV'"); t "auditoria linearizada: 2 eventos (sem duplicar no reenvio)" "[ \"$N\" = '2' ]" "$N"
Q=$($M -N -e "SELECT enviado_por FROM sisi.pacotes WHERE om='BAPV'"); t "enviado_por = último autor da auditoria" "[ \"$Q\" = 'Cap SILVA' ]" "$Q"
R=$(curl -s "$B?rota=auditoria&om=BAPV"); t "rota=auditoria consulta direta" "echo '$R' | grep -q 'Percentual alterado' && echo '$R' | grep -q '\"repeticoes\":3'" "$R"

echo "[4] contrato ?om= / ?all= / ?roster="
R=$(curl -s "$B?om=BAPV"); t "GET ?om=BAPV devolve o omData (dados)" "echo '$R' | grep -q '\"data\":{\"om\":\"BAPV\"' && ! echo '$R' | grep -q '\"dados\"'" "$(echo $R|cut -c1-120)"
R=$(curl -s -X POST "$B?om=BABE" -H 'Content-Type: application/json' -H 'X-Auth: tok-teste-123' -d '{"om":"BABE","savedAt":"2026-10-01T13:00:00Z","tarefas":{},"registros":[],"necessidades":[]}')
R2=$(curl -s "$B?om=BABE"); t "objeto vazio {} preservado (tarefas:{}, não [])" "echo '$R2' | grep -q '\"tarefas\":{}'" "$R2"
t "POST ?om=BABE (omData puro) grava embrulhado" "echo '$R' | grep -q '\"ok\":true'" "$R"
R=$(curl -s "$B?all=1"); t "?all=1 devolve as duas OMs" "echo '$R' | grep -q '\"BAPV\"' && echo '$R' | grep -q '\"BABE\"'" "$(echo $R|cut -c1-120)"
R=$(curl -s -X POST "$B?roster=1" -H 'Content-Type: application/json' -H 'X-Auth: tok-teste-123' -d '{"BAPV":{"nome":"BASE AEREA DE PORTO VELHO","ods":"COMPREP","guarnicao":"PV","ativo":true},"BABE":{"nome":"BASE AEREA DE BELEM","ods":"COMPREP","guarnicao":"BE"}}')
t "POST roster" "echo '$R' | grep -q '\"total\":2'" "$R"
R=$(curl -s "$B?roster=1"); t "GET roster devolve o rol com campos extras" "echo '$R' | grep -q '\"guarnicao\":\"PV\"' && echo '$R' | grep -q '\"ativo\":true'" "$R"

echo "[5] ciência"
R=$(curl -s -X POST "$B" -H 'Content-Type: application/json' -d '{"rota":"ciencia","token":"tok-teste-123","msgId":"m1","texto":"Aviso X","usuario":"Cap SILVA","perfil":"om","escopo":"BAPV","quando":"2026-10-01T14:00:00Z"}')
t "POST rota=ciencia" "echo '$R' | grep -q '\"ok\":true'" "$R"
R=$(curl -s "$B?rota=ciencias"); t "GET rota=ciencias" "echo '$R' | grep -q '\"ciencias\":\\[{\"msgId\":\"m1\"'" "$R"

echo "[6] diversos"
R=$(curl -s -X OPTIONS -i "$B" | head -1); t "preflight CORS responde 204" "echo '$R' | grep -q '204'" "$R"
R=$(curl -s "$B?rota=inexistente"); t "rota desconhecida → 404 com erro" "echo '$R' | grep -q 'rota não reconhecida'" "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$B?om=../etc"); t "sigla inválida rejeitada" "[ \"$R\" = '400' ]" "$R"
N=$($M -N -e "SELECT COUNT(*) FROM sisi.api_log"); t "api_log registrou as chamadas ($N)" "[ \"$N\" -gt 15 ]" "$N"
echo; echo "$pass/$((pass+fail)) testes aprovados"
kill %2 2>/dev/null; mariadb --socket=/run/mysqld/mysqld.sock -e "SHUTDOWN" 2>/dev/null; sleep 1
[ $fail -eq 0 ]
