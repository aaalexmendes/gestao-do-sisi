<?php
/* =====================================================================
   Painel Unificado SISI · api.php
   Substitui, num servidor interno (Apache/PHP + MySQL), os três serviços
   externos que o painel usava:
     1. jsonbin.io   → rota=estado (GET/POST)            estado do gestor
     2. Apps Script  → POST pacote · rota=pacotes · rota=ciencia · rota=ciencias
     3. "API da FAB" → ?ping · ?om= · ?all=1 · ?roster=1
   Mantém EXATAMENTE os formatos de resposta que o painel já espera.
   ===================================================================== */
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(120);

$CFG_FILE = __DIR__ . '/config.php';
if (!is_file($CFG_FILE)) { http_response_code(500); header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'erro'=>'config.php ausente — copie config.exemplo.php para config.php e preencha']); exit; }
$CFG = require $CFG_FILE;

/* ---------- CORS / cabeçalhos ---------- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: ' . ($CFG['cors_origin'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token, X-Auth, X-Access-Key, X-Master-Key');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- utilitários ---------- */
function out(array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function erro(string $msg, int $code = 400): void { logApi(false, $msg); out(['ok'=>false,'erro'=>$msg], $code); }
function cortar(string $s, int $ini, int $n): string { return function_exists('mb_substr') ? \mb_substr($s, $ini, $n) : substr($s, $ini, $n); }
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function q(string $k, string $def = ''): string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $def; }
function headerVal(string $name): string {
  $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$k]) ? trim((string)$_SERVER[$k]) : '';
}
function rawBody(): string { static $b = null; if ($b === null) $b = file_get_contents('php://input') ?: ''; return $b; }
function bodyJson(): ?stdClass { $t = rawBody(); if ($t === '') return null; $j = json_decode($t, false); return ($j instanceof stdClass) ? $j : null; }
function prop($o, string $k, $def = null) { return (is_object($o) && isset($o->$k)) ? $o->$k : (is_array($o) && isset($o[$k]) ? $o[$k] : $def); }
function siglaOk(string $s): bool { return (bool)preg_match('/^[A-Z0-9][A-Z0-9\-\._ ]{0,39}$/i', $s); }
function pastaOm(string $om): string { return preg_replace('/[^A-Za-z0-9\-_]/', '_', $om); }

/* ---------- banco ---------- */
function db(): PDO {
  static $pdo = null; global $CFG;
  if ($pdo) return $pdo;
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $CFG['db_host'], (int)($CFG['db_port'] ?? 3306), $CFG['db_name']);
  try {
    $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (Throwable $e) { out(['ok'=>false,'erro'=>'banco indisponível: '.$e->getMessage()], 500); }
  return $pdo;
}
function logApi(bool $ok, string $erro = '', string $rota = '', string $om = ''): void {
  try {
    $st = db()->prepare('INSERT INTO api_log (ip, metodo, rota, om, ok, erro, bytes) VALUES (?,?,?,?,?,?,?)');
    $st->execute([ $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['REQUEST_METHOD'] ?? null,
      $rota ?: ($GLOBALS['ROTA'] ?? ''), $om ?: ($GLOBALS['OM'] ?? ''), $ok ? 1 : 0, cortar($erro, 0, 250), strlen(rawBody()) ]);
  } catch (Throwable $e) { /* o log nunca derruba a requisição */ }
}

/* ---------- autenticação ---------- */
function tokenEnviado($body = null): string {
  $t = headerVal('X-Token'); if ($t !== '') return $t;
  $t = headerVal('X-Auth'); if ($t !== '') return $t;
  $t = headerVal('X-Access-Key'); if ($t !== '') return $t;
  $t = headerVal('X-Master-Key'); if ($t !== '') return $t;
  $t = q('token'); if ($t !== '') return $t;
  if ($body && isset($body->token)) return trim((string)$body->token);
  return '';
}
function exigeEscrita($body = null): void { global $CFG;
  $t = tokenEnviado($body);
  if ($CFG['token'] === '' || $CFG['token'] === 'TROQUE-ESTE-TOKEN') erro('token do servidor não configurado em config.php', 500);
  if (!hash_equals((string)$CFG['token'], $t)) erro('token inválido', 401);
}
function exigeLeitura($body = null): void { global $CFG;
  $tl = (string)($CFG['token_leitura'] ?? '');
  if ($tl === '') return;
  $t = tokenEnviado($body);
  if (!hash_equals($tl, $t) && !hash_equals((string)$CFG['token'], $t)) erro('token de leitura inválido', 401);
}

/* ---------- roteamento ---------- */
$METHOD = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = ($METHOD === 'POST' || $METHOD === 'PUT') ? bodyJson() : null;
$ROTA   = q('rota') ?: (string)prop($body, 'rota', '');
$OM     = q('om');
$GLOBALS['ROTA'] = $ROTA; $GLOBALS['OM'] = $OM;

/* 1) ping */
if (isset($_GET['ping'])) {
  try { db()->query('SELECT 1'); } catch (Throwable $e) { erro('banco indisponível', 500); }
  logApi(true, '', 'ping');
  out(['ok'=>true,'versao'=>'sisi-api-mysql 1.0','servidor'=>now(),'php'=>PHP_VERSION]);
}

/* 2) estado consolidado (substitui o jsonbin) */
if ($ROTA === 'estado' || $ROTA === 'latest') {
  $chave = q('chave', 'sisi');
  if ($METHOD === 'GET') {
    exigeLeitura();
    $st = db()->prepare('SELECT conteudo, atualizado_em FROM estado WHERE chave = ?'); $st->execute([$chave]);
    $r = $st->fetch();
    logApi(true, '', 'estado');
    if (!$r) out(['record'=>null, 'metadata'=>['chave'=>$chave,'vazio'=>true]]);
    $rec = json_decode($r['conteudo'], false);
    // mesmo formato do jsonbin: { record: {...}, metadata: {...} }
    out(['record'=>$rec, 'metadata'=>['chave'=>$chave,'atualizado_em'=>$r['atualizado_em']]]);
  }
  // POST ou PUT: grava o estado inteiro
  if ($body === null) erro('corpo JSON inválido');
  exigeEscrita($body);
  unset($body->token, $body->rota);
  $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $st = db()->prepare('INSERT INTO estado (chave, conteudo, atualizado_por) VALUES (?,?,?) ON DUPLICATE KEY UPDATE conteudo = VALUES(conteudo), atualizado_por = VALUES(atualizado_por)');
  $st->execute([$chave, $json, $_SERVER['REMOTE_ADDR'] ?? null]);
  logApi(true, '', 'estado');
  out(['ok'=>true, 'metadata'=>['chave'=>$chave,'bytes'=>strlen($json),'atualizado_em'=>now()]]);
}

/* 3) rol de OMs */
if (isset($_GET['roster'])) {
  if ($METHOD === 'GET') {
    $rows = db()->query('SELECT om, nome, ods, guarnicao, extra FROM roster ORDER BY om')->fetchAll();
    $out = [];
    foreach ($rows as $r) { $e = $r['extra'] ? (json_decode($r['extra'], true) ?: []) : [];
      $out[$r['om']] = array_merge($e, ['nome'=>$r['nome'],'ods'=>$r['ods'],'guarnicao'=>$r['guarnicao']]); }
    logApi(true, '', 'roster');
    out(['ok'=>true,'data'=>$out ?: null]);
  }
  if ($body === null) erro('corpo JSON inválido');
  exigeEscrita($body); unset($body->token);
  $rol = get_object_vars($body);
  $pdo = db(); $pdo->beginTransaction();
  try {
    $pdo->exec('DELETE FROM roster');
    $st = $pdo->prepare('INSERT INTO roster (om, nome, ods, guarnicao, extra) VALUES (?,?,?,?,?)');
    foreach ($rol as $om => $r) { if (!is_object($r) || !siglaOk((string)$om)) continue;
      $extra = clone $r; unset($extra->nome, $extra->ods, $extra->guarnicao);
      $st->execute([(string)$om, prop($r,'nome'), prop($r,'ods'), prop($r,'guarnicao'), get_object_vars($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null]); }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); erro('falha ao gravar o rol: '.$e->getMessage(), 500); }
  logApi(true, '', 'roster');
  out(['ok'=>true,'total'=>count($rol)]);
}

/* 4) pacote de uma OM — contrato "?om=" (GET lê / POST grava) */
if ($OM !== '' && $ROTA === '') {
  if (!siglaOk($OM)) erro('sigla inválida');
  if ($METHOD === 'GET') {
    $st = db()->prepare('SELECT conteudo FROM pacotes WHERE om = ?'); $st->execute([$OM]); $r = $st->fetch();
    logApi(true, '', 'om', $OM);
    // devolve o omData (campo "dados" do pacote), decodificado como objeto para {} não virar []
    $p = $r ? json_decode($r['conteudo'], false) : null;
    out(['ok'=>true, 'data'=> $p ? prop($p,'dados',$p) : null]);
  }
  if ($body === null) erro('corpo JSON inválido');
  exigeEscrita($body);
  // o painel manda omData diretamente; embrulha-se no formato de pacote
  $pkg = isset($body->dados) ? $body : (object)['v'=>3,'om'=>$OM,'omNome'=>'','ods'=>'','enviadoEm'=>now(),'dados'=>$body,'fotos'=>[]];
  gravarPacote($pkg, $OM);
  out(['ok'=>true]);
}

/* 5) todos os pacotes — contrato "?all=1" */
if (isset($_GET['all'])) {
  exigeLeitura();
  $rows = db()->query('SELECT om, conteudo FROM pacotes')->fetchAll();
  $out = []; foreach ($rows as $r) { $p = json_decode($r['conteudo'], false); $out[$r['om']] = prop($p,'dados',$p); }
  logApi(true, '', 'all');
  out(['ok'=>true,'data'=>$out]);
}

/* 6) contrato do Apps Script */
if ($ROTA === 'pacotes') {                     // GET: lista / pacotes completos / pacote de uma OM
  exigeLeitura();
  $lista = isset($_GET['lista']);
  if ($OM !== '') { $st = db()->prepare('SELECT * FROM pacotes WHERE om = ?'); $st->execute([$OM]); $rows = $st->fetchAll(); }
  else $rows = db()->query('SELECT * FROM pacotes ORDER BY om')->fetchAll();
  $pac = [];
  foreach ($rows as $r) {
    if ($lista) { $pac[] = ['om'=>$r['om'],'omNome'=>$r['om_nome'],'ods'=>$r['ods'],'savedAt'=>$r['saved_at'],'enviadoEm'=>$r['enviado_em'],'fotos'=>(int)$r['fotos']]; continue; }
    $p = json_decode($r['conteudo'], false); if (!($p instanceof stdClass)) continue;
    // Mesmo formato do Code.gs v4+: os DADOS da OM na raiz (tarefas, registros, governança,
    // auditoria…), com om / omNome / ods / savedAt / enviadoEm por cima. O painel do gestor
    // guarda exatamente esse objeto em DB[om] e lê DB[om].governanca, DB[om].auditoria etc.
    $d = ($p->dados ?? null) instanceof stdClass ? $p->dados : $p;
    $d->om = $r['om']; $d->omNome = $r['om_nome']; $d->ods = $r['ods'];
    if (!isset($d->savedAt) || !$d->savedAt) $d->savedAt = $r['saved_at'];
    $d->enviadoEm = $r['enviado_em']; $d->fotosNoServidor = (int)$r['fotos'];
    $pac[] = $d;
  }
  logApi(true, '', 'pacotes', $OM);
  out(['ok'=>true,'versao'=>'mysql-1.0','pacotes'=>$pac]);
}
if ($ROTA === 'ciencia') {
  if ($body === null) erro('corpo JSON inválido');
  exigeEscrita($body);
  $st = db()->prepare('INSERT INTO ciencias (msg_id, texto, usuario, perfil, escopo, quando) VALUES (?,?,?,?,?,?)');
  $st->execute([ (string)prop($body,'msgId',''), cortar((string)prop($body,'texto',''), 0, 250),
    (string)prop($body,'usuario',''), (string)prop($body,'perfil',''), (string)prop($body,'escopo',''), (string)prop($body,'quando',now()) ]);
  logApi(true, '', 'ciencia'); out(['ok'=>true]);
}
if ($ROTA === 'ciencias') {
  exigeLeitura();
  $rows = db()->query('SELECT msg_id AS msgId, texto, usuario, perfil, escopo, quando FROM ciencias ORDER BY id DESC LIMIT 2000')->fetchAll();
  logApi(true, '', 'ciencias'); out(['ok'=>true,'ciencias'=>$rows]);
}
if ($ROTA === 'auditoria') {                   // consulta direta (extra): ?rota=auditoria&om=BAPV
  exigeLeitura();
  if ($OM !== '') { $st = db()->prepare('SELECT om, ts, evento, acao, detalhe, posto, nome, repeticoes FROM auditoria WHERE om = ? ORDER BY ts DESC LIMIT 1000'); $st->execute([$OM]); }
  else { $st = db()->query('SELECT om, ts, evento, acao, detalhe, posto, nome, repeticoes FROM auditoria ORDER BY ts DESC LIMIT 2000'); }
  logApi(true, '', 'auditoria', $OM); out(['ok'=>true,'auditoria'=>$st->fetchAll()]);
}
if ($ROTA === 'foto') {                        // serve uma foto: ?rota=foto&om=BAPV&nome=xxx.jpg
  $nome = basename(q('nome')); if ($OM === '' || $nome === '') erro('om e nome são obrigatórios');
  $st = db()->prepare('SELECT caminho, mime FROM fotos WHERE om = ? AND nome = ?'); $st->execute([$OM, $nome]); $r = $st->fetch();
  if (!$r || !is_file($r['caminho'])) erro('foto não encontrada', 404);
  header('Content-Type: ' . ($r['mime'] ?: 'application/octet-stream')); header('Cache-Control: private, max-age=86400');
  readfile($r['caminho']); exit;
}

/* 7) POST do pacote da OM (o "Enviar ao servidor" do painel) */
if ($METHOD === 'POST' && $body instanceof stdClass && isset($body->om, $body->dados)) {
  exigeEscrita($body);
  $om = (string)$body->om; if (!siglaOk($om)) erro('sigla inválida');
  $res = gravarPacote($body, $om);
  out(['ok'=>true,'versao'=>'mysql-1.0','fotosNovas'=>$res['fotosNovas'],'fotosTotal'=>$res['fotosTotal'],
       'folderUrl'=>'(servidor interno) uploads/'.pastaOm($om).'/', 'sheetUrl'=>'(banco de dados interno · tabela pacotes)']);
}

erro('rota não reconhecida', 404);

/* ---------- gravação do pacote ---------- */
function gravarPacote(stdClass $pkg, string $om): array {
  global $CFG;
  $max = (int)($CFG['max_mb'] ?? 64) * 1024 * 1024;
  if (strlen(rawBody()) > $max) erro('pacote maior que o limite de '.($CFG['max_mb'] ?? 64).' MB', 413);
  unset($pkg->token);
  $fotos = is_array($pkg->fotos ?? null) ? $pkg->fotos : [];
  $pkg->fotos = [];                                     // as fotos vão para arquivos, não para o banco
  $dir = rtrim((string)$CFG['uploads'], '/\\') . '/' . pastaOm($om);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) erro('não foi possível criar a pasta de fotos (permissão?)', 500);
  $pdo = db(); $novas = 0;
  $stF = $pdo->prepare('INSERT IGNORE INTO fotos (om, nome, mime, registro_id, data_reg, tamanho, caminho) VALUES (?,?,?,?,?,?,?)');
  foreach ($fotos as $f) {
    $name = (string)prop($f,'name',''); $b64 = (string)prop($f,'b64','');
    if ($name === '' || $b64 === '') continue;
    $nome = basename($name); $caminho = $dir . '/' . $nome;
    if (!is_file($caminho)) {
      $bin = base64_decode($b64, true);
      if ($bin === false) continue;
      if (@file_put_contents($caminho, $bin) === false) erro('falha ao gravar a foto '.$nome, 500);
      $novas++;
    }
    $stF->execute([$om, $nome, (string)prop($f,'mime',''), (string)prop($f,'registroId',''), (string)prop($f,'data',''), is_file($caminho) ? filesize($caminho) : null, $caminho]);
  }
  $dados   = ($pkg->dados ?? null) instanceof stdClass ? $pkg->dados : new stdClass;
  $savedAt = (string)prop($dados,'savedAt', prop($pkg,'savedAt', now()));
  $json    = json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $quem    = '';
  $aud     = is_array($dados->auditoria ?? null) ? $dados->auditoria : [];
  if ($aud) { $u = end($aud); $quem = trim((string)prop($u,'posto','').' '.(string)prop($u,'nome','')); }
  $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO pacotes (om, om_nome, ods, saved_at, enviado_em, enviado_por, conteudo, fotos) VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE om_nome=VALUES(om_nome), ods=VALUES(ods), saved_at=VALUES(saved_at), enviado_em=VALUES(enviado_em), enviado_por=VALUES(enviado_por), conteudo=VALUES(conteudo), fotos=VALUES(fotos)')
      ->execute([$om, (string)prop($pkg,'omNome',''), (string)prop($pkg,'ods',''), $savedAt, (string)prop($pkg,'enviadoEm',now()), $quem, $json, count($fotos)]);
    $pdo->prepare('INSERT INTO pacotes_hist (om, saved_at, enviado_em, enviado_por, tamanho_kb, fotos, conteudo) VALUES (?,?,?,?,?,?,?)')
      ->execute([$om, $savedAt, (string)prop($pkg,'enviadoEm',now()), $quem, (int)round(strlen($json)/1024), count($fotos), $json]);
    $hm = (int)($CFG['hist_max'] ?? 30);
    if ($hm > 0) { $st = $pdo->prepare('SELECT id FROM pacotes_hist WHERE om = ? ORDER BY id DESC LIMIT 1000 OFFSET ?'); $st->bindValue(1, $om); $st->bindValue(2, $hm, PDO::PARAM_INT); $st->execute();
      $ids = array_column($st->fetchAll(), 'id'); if ($ids) $pdo->exec('DELETE FROM pacotes_hist WHERE id IN ('.implode(',', array_map('intval', $ids)).')'); }
    if ($aud) {
      $stA = $pdo->prepare('INSERT IGNORE INTO auditoria (om, ts, evento, acao, detalhe, posto, nome, repeticoes, hash) VALUES (?,?,?,?,?,?,?,?,?)');
      foreach ($aud as $a) { $ts = (string)prop($a,'ts',''); if ($ts === '') continue;
        $ev=(string)prop($a,'ev','edicao'); $ac=(string)prop($a,'acao',''); $dt=(string)prop($a,'det',''); $nm=(string)prop($a,'nome','');
        $h = sha1($om.'|'.$ts.'|'.$ev.'|'.$ac.'|'.$dt.'|'.$nm);
        $stA->execute([$om, $ts, $ev, cortar($ac,0,200), $dt, (string)prop($a,'posto',''), $nm, (int)prop($a,'n',1), $h]); }
    }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); erro('falha ao gravar o pacote: '.$e->getMessage(), 500); }
  $tot = (int)$pdo->query('SELECT COUNT(*) c FROM fotos WHERE om = '.$pdo->quote($om))->fetch()['c'];
  logApi(true, '', 'pacote', $om);
  return ['fotosNovas'=>$novas, 'fotosTotal'=>$tot];
}
