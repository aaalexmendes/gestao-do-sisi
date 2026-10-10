<?php
/* =====================================================================
   Painel Unificado SISI · api.php · sisi-api-mysql 2.0
   Substitui, num servidor interno (Apache/Nginx/IIS + PHP + MySQL), os três
   serviços externos que o painel usava:
     1. jsonbin.io   → rota=estado (GET/POST)            estado do gestor
     2. Apps Script  → POST pacote · rota=pacotes · rota=ciencia · rota=ciencias
     3. "API da FAB" → ?ping · ?om= · ?all=1 · ?roster=1
   Mantém os formatos de resposta que o painel já espera.

   2.0 — controle de acesso no servidor (pedidos 1, 2 e 3 da TI):
     · login por usuário e senha conferido AQUI (rota=login); a senha nunca é
       conferida no navegador e os hashes nunca saem do banco;
     · sessão por cookie HttpOnly + SameSite=Strict (8 h), com token anti-CSRF
       exigido em toda gravação (cabeçalho X-CSRF-Token);
     · matriz de autorização por perfil e escopo:
         gestor → tudo · ODS → só LEITURA das OMs do seu ODS · OM → só a própria OM;
     · fotos só pela rota=foto, com sessão, escopo, nome e tipo conferidos.
   Referências: OWASP ASVS 4.0 (V2 autenticação, V3 sessão, V4 controle de
   acesso, V12 arquivos), OWASP Cheat Sheets (Password Storage, Session
   Management, CSRF), NIST SP 800-63B; Guia de Requisitos Mínimos de
   Privacidade e Segurança da Informação para Aplicações Web (PPSI/SGD) e
   LGPD art. 46.
   ===================================================================== */
declare(strict_types=1);
const API_VERSAO = 'sisi-api-mysql 2.0';
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(120);

/* ---------- cabeçalhos comuns ---------- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cross-Origin-Resource-Policy: same-origin');
header_remove('X-Powered-By');                       // não revela a versão do PHP

/* ---------- configuração ---------- */
$CFG_FILE = getenv('SISI_CONFIG') ?: (__DIR__ . '/config.php');
if (!is_file($CFG_FILE)) { http_response_code(500);
  echo json_encode(['ok'=>false,'erro'=>'config.php ausente — copie config.exemplo.php para config.php e preencha'], JSON_UNESCAPED_UNICODE); exit; }
$CFG = array_merge([
  'db_host'=>'localhost','db_port'=>3306,'db_name'=>'sisi','db_user'=>'','db_pass'=>'',
  'token'=>'','sessao_horas'=>8,'cookie_nome'=>'SISI_SESS','cookie_seguro'=>false,
  'senha_min'=>12,'login_max_falhas'=>5,'ip_max_falhas'=>30,'login_janela_min'=>15,
  'cors_origin'=>'','proxies_confiaveis'=>[],
  'uploads'=>__DIR__.'/uploads','max_mb'=>64,'foto_max_mb'=>10,'hist_max'=>30,
], (array)(require $CFG_FILE));

/* ---------- CORS (só quando o painel estiver em OUTRO host) ---------- */
$corsCfg = trim((string)$CFG['cors_origin']);
$origemReq = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
if ($corsCfg !== '' && $corsCfg !== '*' && $origemReq !== '' && rtrim($origemReq, '/') === rtrim($corsCfg, '/')) {
  header('Access-Control-Allow-Origin: ' . $origemReq);
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
  header('Access-Control-Max-Age: 600');
  header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- utilitários ---------- */
class CadInvalido extends InvalidArgumentException {}   // dado de cadastro inválido (vira HTTP 400)
function out(array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function erro(string $msg, int $code = 400, array $extra = []): void { logApi(false, $msg); out(array_merge(['ok'=>false,'erro'=>$msg], $extra), $code); }
function cortar(string $s, int $ini, int $n): string { return function_exists('mb_substr') ? \mb_substr($s, $ini, $n) : substr($s, $ini, $n); }
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function q(string $k, string $def = ''): string { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $def; }
function headerVal(string $name): string {
  $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$k]) ? trim((string)$_SERVER[$k]) : '';
}
function rawBody(): string { static $b = null; if ($b === null) $b = file_get_contents('php://input') ?: ''; return $b; }
function bodyJson(): ?stdClass { $t = rawBody(); if ($t === '') return null; $j = json_decode($t, false); return ($j instanceof stdClass) ? $j : null; }
function prop($o, string $k, $def = null) { return (is_object($o) && isset($o->$k)) ? $o->$k : (is_array($o) && isset($o[$k]) ? $o[$k] : $def); }
function str($v, int $max = 255): string { return is_scalar($v) ? cortar(trim((string)$v), 0, $max) : ''; }
function siglaOk(string $s): bool { return (bool)preg_match('/^[A-Z0-9][A-Z0-9\-\._ ]{0,39}$/i', $s); }
function sig(string $s): string { return strtoupper(trim($s)); }
function pastaOm(string $om): string { return preg_replace('/[^A-Za-z0-9\-_]/', '_', $om); }
function ipCliente(): string {
  global $CFG;
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $conf = (array)$CFG['proxies_confiaveis'];
  if ($conf && in_array($ip, $conf, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $cadeia = array_reverse(array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])));
    foreach ($cadeia as $c) { if ($c !== '' && !in_array($c, $conf, true)) return cortar($c, 0, 64); }
  }
  return cortar($ip, 0, 64);
}

/* ---------- banco ---------- */
function db(): PDO {
  static $pdo = null; global $CFG;
  if ($pdo) return $pdo;
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $CFG['db_host'], (int)$CFG['db_port'], $CFG['db_name']);
  try {
    $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (Throwable $e) {
    error_log('SISI api: banco indisponível: ' . $e->getMessage());   // detalhe só no log do servidor
    out(['ok'=>false,'erro'=>'banco indisponível — confira db_host, db_user e db_pass no config.php (detalhe no log de erros do PHP)'], 500);
  }
  return $pdo;
}
function logApi(bool $ok, string $erro = '', string $rota = '', string $om = ''): void {
  try {
    $st = db()->prepare('INSERT INTO api_log (ip, metodo, rota, om, ok, erro, bytes) VALUES (?,?,?,?,?,?,?)');
    $st->execute([ ipCliente(), $_SERVER['REQUEST_METHOD'] ?? null,
      cortar($rota ?: ($GLOBALS['ROTA'] ?? ''), 0, 40), cortar($om ?: ($GLOBALS['OM'] ?? ''), 0, 40), $ok ? 1 : 0, cortar($erro, 0, 250), strlen(rawBody()) ]);
  } catch (Throwable $e) { /* o log nunca derruba a requisição */ }
}
function segLog(string $evento, string $detalhe = '', ?int $uid = null, string $login = ''): void {
  try {
    db()->prepare('INSERT INTO seguranca_log (evento, usuario_id, login, ip, rota, detalhe) VALUES (?,?,?,?,?,?)')
      ->execute([$evento, $uid, cortar($login, 0, 120), ipCliente(), cortar((string)($GLOBALS['ROTA'] ?? ''), 0, 40), cortar($detalhe, 0, 250)]);
  } catch (Throwable $e) { }
}

/* =====================================================================
   SENHAS
   ===================================================================== */
function algoSenha() { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; }
function hashSenha(string $s): string { return password_hash($s, algoSenha()); }
/* Confere a senha contra o hash moderno ou, no primeiro acesso, contra o hash
   herdado do painel antigo / senha provisória definida pelo gestor: sha256(salt+senha).
   Esse sha256 nunca é guardado puro: fica EMBRULHADO em password_hash (mesmo custo
   de uma senha normal — não dá para quebrar rápido se o banco vazar, e o tempo de
   resposta não revela quem ainda está com senha provisória). */
function embrulhaLegado(string $sha256hex): string { return password_hash(strtolower($sha256hex), algoSenha()); }
function senhaConfere(array $u, string $senha): bool {
  if (!empty($u['senha_hash'])) return password_verify($senha, (string)$u['senha_hash']);
  if (!empty($u['legado_hash'])) return password_verify(hash('sha256', (string)$u['legado_salt'] . $senha), (string)$u['legado_hash']);
  password_hash($senha, algoSenha());                 // custo equivalente mesmo sem senha cadastrada
  return false;
}
/* Política (NIST SP 800-63B / OWASP): tamanho mínimo, sem regra de composição,
   bloqueio de senhas comuns e de senhas ligadas ao próprio usuário ou ao sistema. */
function senhaMin(): int { global $CFG; return max(8, (int)$CFG['senha_min']); }
function senhaProblema(string $nova, array $ctx = []): string {
  $min = senhaMin();
  $len = function_exists('mb_strlen') ? mb_strlen($nova, 'UTF-8') : strlen($nova);
  if ($len < $min) return "a senha precisa ter pelo menos $min caracteres (dica: use uma frase, ex.: três ou quatro palavras)";
  if ($len > 128) return 'a senha pode ter no máximo 128 caracteres';
  if (algoSenha() === PASSWORD_BCRYPT && strlen($nova) > 72) return 'neste servidor a senha pode ter no máximo 72 bytes';
  $n = strtolower(preg_replace('/\s+/', '', $nova));
  if (count(array_unique(str_split($n))) < 4) return 'a senha tem caracteres repetidos demais';
  $comuns = ['123456789012','1234567890123','qwertyuiopas','qwertyuiop123','senha1234567','senhasenha12','password1234',
    'passwordpassword','aaaaaaaaaaaa','abcdefghijkl','abc123abc123','iloveyou1234','brasil123456','brasil2026!!',
    'forcaaerea12','forcaaereabrasileira','aeronautica12','12345678910','01234567890','098765432109','111111111111',
    'mudar1234567','trocar123456','alterar12345','admin1234567','administrador'];
  if (in_array($n, $comuns, true)) return 'essa senha é muito comum — escolha outra';
  foreach (['sisi','comaer','comprep','senha','password','123456','qwerty'] as $p)
    if ($n === $p || preg_match('/^(' . preg_quote($p, '/') . ')+[0-9!@#$%*.\-_]*$/', $n)) return 'a senha não pode ser só o nome do sistema ou palavras óbvias';
  foreach (['login', 'nome'] as $k) {
    foreach (preg_split('/[\s.@_\-]+/', strtolower(trim((string)($ctx[$k] ?? '')))) as $c)
      if (strlen($c) >= 4 && strpos($n, $c) !== false) return 'a senha não pode conter o seu usuário ou o seu nome';
  }
  if (!empty($ctx['atual']) && hash_equals((string)$ctx['atual'], $nova)) return 'a nova senha deve ser diferente da atual';
  return '';
}

/* =====================================================================
   SESSÃO (cookie HttpOnly + SameSite=Strict, token anti-CSRF)
   ===================================================================== */
function caminhoCookie(): string {
  $d = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php')));
  return rtrim($d, '/') . '/';
}
function gravaCookie(string $valor, bool $apagar = false): void {
  global $CFG;
  setcookie((string)$CFG['cookie_nome'], $apagar ? '' : $valor, [
    'expires'  => $apagar ? time() - 3600 : 0,         // cookie de sessão: some ao fechar o navegador
    'path'     => caminhoCookie(),
    'secure'   => (bool)$CFG['cookie_seguro'] || ehHttps(),
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
}
function usuarioPublico(array $u): array {
  $rot = trim(((string)($u['posto'] ?? '')) . ' ' . ((string)($u['nome'] ?? '')));
  return ['id'=>(int)$u['id'], 'login'=>$u['login'], 'nome'=>$u['nome'], 'posto'=>(string)($u['posto'] ?? ''),
          'rotulo'=>$rot, 'perfil'=>$u['perfil'], 'escopo'=>(string)($u['escopo'] ?? ''),
          'trocaObrigatoria'=>((int)$u['troca_obrigatoria'] === 1)];
}
function abreSessao(array $u): array {
  global $CFG;
  $pdo = db();
  $tok = bin2hex(random_bytes(32)); $csrf = bin2hex(random_bytes(32));
  $h = max(1, (int)$CFG['sessao_horas']);
  $pdo->exec('DELETE FROM sessoes WHERE expira_em < NOW()');
  $pdo->prepare("INSERT INTO sessoes (token_hash, usuario_id, csrf, expira_em, ultimo_uso, ip, user_agent) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL $h HOUR), NOW(), ?, ?)")
      ->execute([hash('sha256', $tok), (int)$u['id'], $csrf, ipCliente(), cortar((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250)]);
  $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([(int)$u['id']]);
  gravaCookie($tok);
  $exp = $pdo->prepare('SELECT expira_em FROM sessoes WHERE token_hash = ?'); $exp->execute([hash('sha256', $tok)]);
  return ['csrf'=>$csrf, 'expiraEm'=>(string)$exp->fetchColumn()];
}
/* Sessão da requisição atual (ou null). */
function sessao(): ?array {
  static $s = false; global $CFG;
  if ($s !== false) return $s;
  $s = null;
  $tok = isset($_COOKIE[$CFG['cookie_nome']]) ? (string)$_COOKIE[$CFG['cookie_nome']] : '';
  if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
  $th = hash('sha256', $tok);
  $st = db()->prepare('SELECT s.token_hash, s.csrf, s.expira_em, u.* FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id
                       WHERE s.token_hash = ? AND s.expira_em > NOW() AND u.ativo = 1');
  $st->execute([$th]); $r = $st->fetch();
  if (!$r) return null;
  db()->prepare('UPDATE sessoes SET ultimo_uso = NOW() WHERE token_hash = ?')->execute([$th]);
  $r['escopo'] = sig((string)($r['escopo'] ?? ''));
  return $s = $r;
}
/* Origem da requisição: bloqueia gravações disparadas por outros sites (defesa
   adicional ao SameSite=Strict e ao token anti-CSRF). */
function origemOk(): bool {
  global $CFG;
  $sfs = headerVal('Sec-Fetch-Site');
  if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) {
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    return $o !== '' && trim((string)$CFG['cors_origin']) !== '' && rtrim($o, '/') === rtrim((string)$CFG['cors_origin'], '/');
  }
  $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
  if ($o === '' || $o === 'null') return $o === '';
  $esq = ehHttps() ? 'https' : 'http';
  $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  if (strpos($host, ':') === false) { $pp = (int)($_SERVER['SERVER_PORT'] ?? 0); if ($pp && $pp !== ($esq === 'https' ? 443 : 80) && !proxyConfiavel()) $host .= ':' . $pp; }
  $u = parse_url(strtolower($o)); if (!$u || empty($u['host'])) return false;
  $porta = isset($u['port']) ? (int)$u['port'] : (($u['scheme'] ?? '') === 'https' ? 443 : 80);
  $alvo = ($u['scheme'] ?? '') . '://' . $u['host'] . ($porta === (($u['scheme'] ?? '') === 'https' ? 443 : 80) ? '' : ':' . $porta);
  if ($alvo === $esq . '://' . preg_replace('/:(80|443)$/', '', $host)) return true;
  return trim((string)$CFG['cors_origin']) !== '' && rtrim($o, '/') === rtrim((string)$CFG['cors_origin'], '/');
}
function proxyConfiavel(): bool { global $CFG; return in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), (array)$CFG['proxies_confiaveis'], true); }
function ehHttps(): bool {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
  return proxyConfiavel() && strtolower(headerVal('X-Forwarded-Proto')) === 'https';
}
function exigeOrigem(): void { if (!origemOk()) { segLog('negado', 'origem recusada: ' . ($_SERVER['HTTP_ORIGIN'] ?? '?')); erro('origem da requisição não autorizada', 403); } }
/* Exige sessão válida. $gravacao = true também exige o token anti-CSRF. */
function exigeSessao(bool $gravacao = false, bool $permiteTroca = false): array {
  $s = sessao();
  if (!$s) erro('sessão ausente ou expirada — entre novamente', 401, ['codigo'=>'sessao']);
  if (!$permiteTroca && (int)$s['troca_obrigatoria'] === 1) erro('troque a senha antes de continuar', 403, ['codigo'=>'troca_senha']);
  if ($gravacao) {
    exigeOrigem();
    if (!hash_equals((string)$s['csrf'], headerVal('X-CSRF-Token'))) { segLog('negado', 'token anti-CSRF ausente/inválido', (int)$s['id'], $s['login']); erro('token anti-CSRF ausente ou inválido — recarregue a página', 403, ['codigo'=>'csrf']); }
  }
  return $s;
}
function negar(array $s, string $msg): void { segLog('negado', $msg, (int)$s['id'], (string)$s['login']); erro($msg, 403, ['codigo'=>'escopo']); }
function exigeGestor(array $s): void { if ($s['perfil'] !== 'gestor') negar($s, 'acesso restrito ao gestor'); }

/* =====================================================================
   ESCOPO — quem pode ver/gravar o quê
     gestor → tudo
     ods    → lê as OMs cujo ODS (tabela roster) é o seu; não grava dados de OM
     om     → lê e grava somente a própria OM
   ===================================================================== */
function odsDaOm(string $om): string {
  static $c = [];
  $k = sig($om); if (isset($c[$k])) return $c[$k];
  $st = db()->prepare('SELECT ods FROM roster WHERE om = ?'); $st->execute([$om]);
  return $c[$k] = sig((string)($st->fetchColumn() ?: ''));
}
function nomeDaOm(string $om): string { $st = db()->prepare('SELECT nome FROM roster WHERE om = ?'); $st->execute([$om]); return (string)($st->fetchColumn() ?: ''); }
function podeLerOm(array $s, string $om): bool {
  if ($s['perfil'] === 'gestor') return true;
  if ($s['perfil'] === 'om') return sig($om) === $s['escopo'];
  if ($s['perfil'] === 'ods') return $s['escopo'] !== '' && odsDaOm($om) === $s['escopo'];
  return false;
}
function podeGravarOm(array $s, string $om): bool {
  if ($s['perfil'] === 'gestor') return true;
  if ($s['perfil'] === 'om') return sig($om) === $s['escopo'];
  return false;                       // ODS: somente consulta (decisão do gestor, 10/10/2026)
}
/* Cláusula SQL de escopo para consultas que listam OMs (coluna $col). */
function filtroOms(array $s, string $col): array {
  if ($s['perfil'] === 'gestor') return ['1=1', []];
  if ($s['perfil'] === 'om') return ["$col = ?", [$s['escopo']]];
  return ["$col IN (SELECT om FROM roster WHERE ods = ?)", [$s['escopo']]];
}

/* =====================================================================
   LIMITE DE TENTATIVAS DE LOGIN
   ===================================================================== */
/* Trava por usuário durante a conferência da senha: requisições paralelas com o
   mesmo login são atendidas uma a uma, então o limite não pode ser burlado em rajada. */
function travaLogin(string $login): void {
  try { $st = db()->prepare('SELECT GET_LOCK(?, 15)'); $st->execute(['sisi_login_' . sha1($login)]); } catch (Throwable $e) { }
}
/* Bloqueio temporário. Regra: falhas do mesmo login vindas do mesmo IP (login_max_falhas);
   falhas do mesmo login de qualquer IP (4× — evita que um terceiro trave a conta de
   alguém com poucas tentativas); falhas do IP (ip_max_falhas). Sucesso zera a contagem. */
function bloqueioLogin(string $login): int {
  global $CFG;
  $jan = max(1, (int)$CFG['login_janela_min']); $max = max(1, (int)$CFG['login_max_falhas']);
  $pdo = db(); $ip = ipCliente();
  $st = $pdo->prepare("SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND ok = 0 AND quando > DATE_SUB(NOW(), INTERVAL $jan MINUTE)");
  $st->execute([$ip]);
  if ((int)$st->fetchColumn() >= max(1, (int)$CFG['ip_max_falhas'])) return $jan;
  $ult = "COALESCE((SELECT MAX(t2.quando) FROM (SELECT quando FROM login_tentativas WHERE login = ? AND ok = 1) t2), '1970-01-01')";
  $st = $pdo->prepare("SELECT SUM(ip = ?), COUNT(*) FROM login_tentativas WHERE login = ? AND ok = 0 AND quando > DATE_SUB(NOW(), INTERVAL $jan MINUTE) AND quando > $ult");
  $st->execute([$ip, $login, $login]); $r = $st->fetch(PDO::FETCH_NUM);
  if ((int)$r[0] >= $max || (int)$r[1] >= 4 * $max) return $jan;
  return 0;
}
function registraTentativa(string $login, bool $ok): void {
  try {
    db()->prepare('INSERT INTO login_tentativas (ip, login, ok) VALUES (?,?,?)')->execute([ipCliente(), cortar($login, 0, 120), $ok ? 1 : 0]);
    if (random_int(1, 50) === 1) db()->exec('DELETE FROM login_tentativas WHERE quando < DATE_SUB(NOW(), INTERVAL 30 DAY)');
  } catch (Throwable $e) { }
}

/* =====================================================================
   ROTEAMENTO
   ===================================================================== */
$METHOD = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($METHOD, ['POST', 'PUT'], true)) {
  $lim = (int)$CFG['max_mb'] * 1024 * 1024;
  if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $lim) erro('envio maior que o limite de ' . (int)$CFG['max_mb'] . ' MB', 413);
}
$body   = ($METHOD === 'POST' || $METHOD === 'PUT') ? bodyJson() : null;
$ROTA   = cortar(q('rota') ?: str(prop($body, 'rota', ''), 40), 0, 40);
$OM     = q('om');
$GLOBALS['ROTA'] = $ROTA; $GLOBALS['OM'] = $OM;

/* 1) ping (público; não revela versões de software) */
if (isset($_GET['ping'])) {
  try { db()->query('SELECT 1'); } catch (Throwable $e) { erro('banco indisponível', 500); }
  logApi(true, '', 'ping');
  out(['ok'=>true,'versao'=>API_VERSAO,'servidor'=>now()]);
}

/* 2) sessão atual (público): quem sou eu · se o servidor já foi instalado */
if ($ROTA === 'sessao') {
  $s = sessao();
  if ($s) out(['ok'=>true,'logado'=>true,'usuario'=>usuarioPublico($s),'csrf'=>$s['csrf'],'expiraEm'=>$s['expira_em'],'senhaMin'=>senhaMin(),'versao'=>API_VERSAO]);
  $n = (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'gestor' AND ativo = 1")->fetchColumn();
  out(['ok'=>true,'logado'=>false,'instalado'=>$n > 0,'senhaMin'=>senhaMin(),'versao'=>API_VERSAO]);
}

/* 3) login */
if ($ROTA === 'login') {
  if ($METHOD !== 'POST' || $body === null) erro('use POST com {login, senha}', 405);
  exigeOrigem();
  $login = strtolower(str(prop($body, 'login', ''), 120)); $senha = (string)prop($body, 'senha', '');
  if ($login === '' || $senha === '') erro('informe usuário e senha', 400);
  if (strlen($senha) > 1024) erro('senha inválida', 400);
  travaLogin($login);
  if ($m = bloqueioLogin($login)) { segLog('login_bloqueado', 'limite de tentativas', null, $login); header('Retry-After: ' . ($m * 60));
    erro("muitas tentativas sem sucesso — aguarde $m minutos e tente de novo (ou peça ao gestor uma nova senha)", 429, ['codigo'=>'bloqueado']); }
  $st = db()->prepare('SELECT * FROM usuarios WHERE login = ? AND ativo = 1'); $st->execute([$login]); $u = $st->fetch();
  if ($u) $ok = senhaConfere($u, $senha);
  else { password_hash($senha, algoSenha()); $ok = false; }        // mesmo custo de tempo: não revela se o usuário existe
  registraTentativa($login, $ok);
  if (!$ok) { segLog('login_falha', $u ? 'senha incorreta' : 'usuário inexistente', $u ? (int)$u['id'] : null, $login); erro('usuário ou senha inválidos', 401, ['codigo'=>'credencial']); }
  if (!empty($u['senha_hash']) && password_needs_rehash((string)$u['senha_hash'], algoSenha()))
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([hashSenha($senha), (int)$u['id']]);
  if (empty($u['senha_hash'])) { db()->prepare('UPDATE usuarios SET troca_obrigatoria = 1 WHERE id = ?')->execute([(int)$u['id']]); $u['troca_obrigatoria'] = 1; }
  $ses = abreSessao($u);
  segLog('login', empty($u['senha_hash']) ? 'senha herdada/provisória — troca obrigatória' : '', (int)$u['id'], $login);
  logApi(true, '', 'login');
  out(['ok'=>true,'usuario'=>usuarioPublico($u),'csrf'=>$ses['csrf'],'expiraEm'=>$ses['expiraEm'],'senhaMin'=>senhaMin()]);
}

/* 4) logout */
if ($ROTA === 'logout') {
  if ($METHOD !== 'POST') erro('use POST', 405);
  exigeOrigem();
  $s = sessao();
  if ($s) { db()->prepare('DELETE FROM sessoes WHERE token_hash = ?')->execute([$s['token_hash']]); segLog('logout', '', (int)$s['id'], (string)$s['login']); }
  gravaCookie('', true);
  out(['ok'=>true]);
}

/* 5) troca da própria senha (também a troca obrigatória do primeiro acesso) */
if ($ROTA === 'senha') {
  if ($METHOD !== 'POST' || $body === null) erro('use POST com {atual, nova}', 405);
  $s = exigeSessao(true, true);
  $atual = (string)prop($body, 'atual', ''); $nova = (string)prop($body, 'nova', '');
  travaLogin((string)$s['login']);
  if ($m = bloqueioLogin((string)$s['login'])) erro("muitas tentativas sem sucesso — aguarde $m minutos", 429);
  if (!senhaConfere($s, $atual)) { registraTentativa((string)$s['login'], false); segLog('senha_falha', 'senha atual incorreta', (int)$s['id'], (string)$s['login']); erro('a senha atual não confere', 400, ['codigo'=>'senha_atual']); }
  $p = senhaProblema($nova, ['login'=>$s['login'], 'nome'=>$s['nome'], 'atual'=>$atual]);
  if ($p !== '') erro($p, 400, ['codigo'=>'politica']);
  $pdo = db();
  $pdo->prepare('UPDATE usuarios SET senha_hash = ?, legado_salt = NULL, legado_hash = NULL, troca_obrigatoria = 0, senha_alterada_em = NOW() WHERE id = ?')
      ->execute([hashSenha($nova), (int)$s['id']]);
  $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ? AND token_hash <> ?')->execute([(int)$s['id'], $s['token_hash']]);   // derruba as demais sessões
  segLog('senha_trocada', ((int)$s['troca_obrigatoria'] === 1) ? 'troca obrigatória concluída' : '', (int)$s['id'], (string)$s['login']);
  $s['troca_obrigatoria'] = 0;
  out(['ok'=>true,'usuario'=>usuarioPublico($s)]);
}

/* 6) instalação: cria o gestor inicial (só enquanto não houver gestor ativo) */
if ($ROTA === 'instalar') {
  if ($METHOD !== 'POST' || $body === null) erro('use POST', 405);
  exigeOrigem();
  travaLogin('#instalar');
  if ($m = bloqueioLogin('#instalar')) erro("muitas tentativas — aguarde $m minutos", 429);
  $tok = (string)$CFG['token'];
  if ($tok === '' || $tok === 'TROQUE-ESTE-TOKEN' || strlen($tok) < 16) erro('defina um "token" longo e aleatório no config.php antes de instalar', 500);
  if (!hash_equals($tok, (string)prop($body, 'token', ''))) { registraTentativa('#instalar', false); segLog('instalar_falha', 'token de instalação incorreto'); erro('token de instalação incorreto', 401); }
  $n = (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'gestor' AND ativo = 1")->fetchColumn();
  if ($n > 0) erro('o servidor já tem gestor cadastrado — entre com usuário e senha', 409);
  $login = strtolower(str(prop($body, 'login', ''), 120)); $nome = str(prop($body, 'nome', ''), 160); $senha = (string)prop($body, 'senha', '');
  if (!preg_match('/^[a-z0-9][a-z0-9._@-]{2,119}$/', $login)) erro('usuário inválido: use de 3 a 120 caracteres (letras, números, ponto, hífen, @)');
  if ($nome === '') erro('informe o nome do gestor');
  $p = senhaProblema($senha, ['login'=>$login, 'nome'=>$nome]); if ($p !== '') erro($p, 400, ['codigo'=>'politica']);
  $pdo = db();
  $pdo->prepare("INSERT INTO usuarios (login, nome, posto, perfil, escopo, senha_hash, troca_obrigatoria, senha_alterada_em, alterado_em) VALUES (?,?,?, 'gestor', NULL, ?, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE nome=VALUES(nome), posto=VALUES(posto), perfil='gestor', escopo=NULL, senha_hash=VALUES(senha_hash), legado_salt=NULL, legado_hash=NULL, troca_obrigatoria=0, ativo=1, alterado_em=NOW()")
      ->execute([$login, $nome, str(prop($body, 'posto', ''), 40), hashSenha($senha)]);
  $st = $pdo->prepare('SELECT * FROM usuarios WHERE login = ?'); $st->execute([$login]); $u = $st->fetch();
  registraTentativa('#instalar', true);
  segLog('instalado', 'gestor inicial criado', (int)$u['id'], $login);
  $ses = abreSessao($u);
  out(['ok'=>true,'usuario'=>usuarioPublico($u),'csrf'=>$ses['csrf'],'expiraEm'=>$ses['expiraEm']]);
}

/* 7) usuários (somente gestor) */
if ($ROTA === 'usuarios') {
  $s = exigeSessao($METHOD !== 'GET'); exigeGestor($s);
  if ($METHOD === 'GET') { logApi(true, '', 'usuarios'); out(['ok'=>true] + listaUsuarios()); }
  if ($body === null) erro('corpo JSON inválido');
  $acao = str(prop($body, 'acao', ''), 20);
  if ($acao === 'importar') out(['ok'=>true] + importarUsuarios($s, prop($body, 'credenciais', [])) + listaUsuarios());
  if ($acao === 'sincronizar') out(['ok'=>true] + sincronizarUsuarios($s, $body) + listaUsuarios());
  erro('ação desconhecida (use "sincronizar" ou "importar")');
}

/* --- daqui em diante, tudo exige sessão válida (e troca de senha concluída) --- */
$S = exigeSessao($METHOD !== 'GET');

/* 8) estado consolidado (substitui o jsonbin) — lê: todos · grava: gestor */
if ($ROTA === 'estado' || $ROTA === 'latest') {
  $chave = q('chave', 'sisi'); if (!preg_match('/^[a-z0-9_\-]{1,64}$/i', $chave)) erro('chave inválida');
  if ($METHOD === 'GET') {
    $st = db()->prepare('SELECT conteudo, atualizado_em FROM estado WHERE chave = ?'); $st->execute([$chave]);
    $r = $st->fetch();
    logApi(true, '', 'estado');
    if (!$r) out(['record'=>null, 'metadata'=>['chave'=>$chave,'vazio'=>true]]);
    $rec = limpaEstado(json_decode($r['conteudo'], false));
    out(['record'=>$rec, 'metadata'=>['chave'=>$chave,'atualizado_em'=>$r['atualizado_em']]]);   // mesmo formato do jsonbin
  }
  exigeGestor($S);
  if ($body === null) erro('corpo JSON inválido');
  unset($body->token, $body->rota);
  $body = limpaEstado($body);
  $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  db()->prepare('INSERT INTO estado (chave, conteudo, atualizado_por) VALUES (?,?,?) ON DUPLICATE KEY UPDATE conteudo = VALUES(conteudo), atualizado_por = VALUES(atualizado_por)')
      ->execute([$chave, $json, cortar((string)$S['login'], 0, 120)]);
  logApi(true, '', 'estado');
  out(['ok'=>true, 'metadata'=>['chave'=>$chave,'bytes'=>strlen($json),'atualizado_em'=>now()]]);
}

/* 9) rol de OMs — lê: todos · grava: gestor */
if (isset($_GET['roster'])) {
  if ($METHOD === 'GET') {
    $rows = db()->query('SELECT om, nome, ods, guarnicao, extra FROM roster ORDER BY om')->fetchAll();
    $o = [];
    foreach ($rows as $r) { $e = $r['extra'] ? (json_decode($r['extra'], true) ?: []) : [];
      $o[$r['om']] = array_merge($e, ['nome'=>$r['nome'],'ods'=>$r['ods'],'guarnicao'=>$r['guarnicao']]); }
    logApi(true, '', 'roster');
    out(['ok'=>true,'data'=>$o ?: null]);
  }
  exigeGestor($S);
  if ($body === null) erro('corpo JSON inválido');
  unset($body->token);
  $rol = get_object_vars($body);
  $pdo = db(); $pdo->beginTransaction();
  try {
    $pdo->exec('DELETE FROM roster');
    $st = $pdo->prepare('INSERT INTO roster (om, nome, ods, guarnicao, extra) VALUES (?,?,?,?,?)');
    foreach ($rol as $om => $r) { if (!is_object($r) || !siglaOk((string)$om)) continue;
      $extra = clone $r; unset($extra->nome, $extra->ods, $extra->guarnicao);
      $st->execute([(string)$om, str(prop($r,'nome'), 160), str(prop($r,'ods'), 20), str(prop($r,'guarnicao'), 10), get_object_vars($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null]); }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); error_log('SISI roster: ' . $e->getMessage()); erro('falha ao gravar o rol', 500); }
  segLog('rol_gravado', count($rol) . ' OM', (int)$S['id'], (string)$S['login']);
  logApi(true, '', 'roster');
  out(['ok'=>true,'total'=>count($rol)]);
}

/* 10) pacote de uma OM — contrato "?om=" (GET lê / POST grava) */
if ($OM !== '' && $ROTA === '') {
  if (!siglaOk($OM)) erro('sigla inválida');
  if ($METHOD === 'GET') {
    if (!podeLerOm($S, $OM)) negar($S, 'sem acesso aos dados da OM ' . $OM);
    $st = db()->prepare('SELECT conteudo FROM pacotes WHERE om = ?'); $st->execute([$OM]); $r = $st->fetch();
    logApi(true, '', 'om', $OM);
    $p = $r ? json_decode($r['conteudo'], false) : null;      // objeto: {} não vira []
    out(['ok'=>true, 'data'=> $p ? prop($p,'dados',$p) : null]);
  }
  if (!podeGravarOm($S, $OM)) negar($S, 'sem permissão para gravar dados da OM ' . $OM);
  if ($body === null) erro('corpo JSON inválido');
  $pkg = isset($body->dados) ? $body : (object)['v'=>3,'om'=>$OM,'omNome'=>'','ods'=>'','enviadoEm'=>now(),'dados'=>$body,'fotos'=>[]];
  $pkg->om = $OM;
  gravarPacote($pkg, $OM, $S);
  out(['ok'=>true]);
}

/* 11) todos os pacotes (no escopo do usuário) — contrato "?all=1" */
if (isset($_GET['all'])) {
  [$w, $par] = filtroOms($S, 'om');
  $st = db()->prepare("SELECT om, conteudo FROM pacotes WHERE $w"); $st->execute($par);
  $o = []; foreach ($st->fetchAll() as $r) { $p = json_decode($r['conteudo'], false); $o[$r['om']] = prop($p,'dados',$p); }
  logApi(true, '', 'all');
  out(['ok'=>true,'data'=>(object)$o]);
}

/* 12) contrato do Apps Script */
if ($ROTA === 'pacotes') {                     // GET: lista / pacotes completos / pacote de uma OM
  $lista = isset($_GET['lista']);
  if ($OM !== '') {
    if (!siglaOk($OM)) erro('sigla inválida');
    if (!podeLerOm($S, $OM)) negar($S, 'sem acesso aos dados da OM ' . $OM);
    $st = db()->prepare('SELECT * FROM pacotes WHERE om = ?'); $st->execute([$OM]);
  } else {
    [$w, $par] = filtroOms($S, 'om');
    $st = db()->prepare("SELECT * FROM pacotes WHERE $w ORDER BY om"); $st->execute($par);
  }
  $pac = [];
  foreach ($st->fetchAll() as $r) {
    if ($lista) { $pac[] = ['om'=>$r['om'],'omNome'=>$r['om_nome'],'ods'=>$r['ods'],'savedAt'=>$r['saved_at'],'enviadoEm'=>$r['enviado_em'],'fotos'=>(int)$r['fotos']]; continue; }
    $p = json_decode($r['conteudo'], false); if (!($p instanceof stdClass)) continue;
    // Mesmo formato do Code.gs v4+: os DADOS da OM na raiz, com om / omNome / ods / savedAt / enviadoEm por cima.
    $d = ($p->dados ?? null) instanceof stdClass ? $p->dados : $p;
    $d->om = $r['om']; $d->omNome = $r['om_nome']; $d->ods = $r['ods'];
    if (!isset($d->savedAt) || !$d->savedAt) $d->savedAt = $r['saved_at'];
    $d->enviadoEm = $r['enviado_em']; $d->fotosNoServidor = (int)$r['fotos'];
    $pac[] = $d;
  }
  logApi(true, '', 'pacotes', $OM);
  out(['ok'=>true,'versao'=>'mysql-2.0','pacotes'=>$pac]);
}
if ($ROTA === 'ciencia') {                     // qualquer perfil registra a PRÓPRIA ciência
  if ($body === null) erro('corpo JSON inválido');
  $msg = str(prop($body,'msgId',''), 80); if ($msg === '') erro('msgId obrigatório');
  db()->prepare('INSERT INTO ciencias (msg_id, texto, usuario, perfil, escopo, quando) VALUES (?,?,?,?,?,?)')
      ->execute([$msg, str(prop($body,'texto',''), 250), cortar(trim(($S['posto'] ?? '') . ' ' . $S['nome']), 0, 160), $S['perfil'], $S['escopo'], now()]);
  logApi(true, '', 'ciencia'); out(['ok'=>true]);
}
if ($ROTA === 'ciencias') {
  if ($S['perfil'] === 'gestor') { $st = db()->query('SELECT msg_id AS msgId, texto, usuario, perfil, escopo, quando FROM ciencias ORDER BY id DESC LIMIT 2000'); }
  elseif ($S['perfil'] === 'ods') { $st = db()->prepare('SELECT msg_id AS msgId, texto, usuario, perfil, escopo, quando FROM ciencias WHERE escopo = ? OR escopo IN (SELECT om FROM roster WHERE ods = ?) ORDER BY id DESC LIMIT 2000'); $st->execute([$S['escopo'], $S['escopo']]); }
  else { $st = db()->prepare('SELECT msg_id AS msgId, texto, usuario, perfil, escopo, quando FROM ciencias WHERE escopo = ? ORDER BY id DESC LIMIT 2000'); $st->execute([$S['escopo']]); }
  logApi(true, '', 'ciencias'); out(['ok'=>true,'ciencias'=>$st->fetchAll()]);
}
if ($ROTA === 'auditoria') {                   // consulta direta: ?rota=auditoria&om=BAPV
  $cols = 'om, ts, evento, acao, detalhe, posto, nome, repeticoes, enviado_por_login AS enviadoPor, recebido_em AS recebidoEm';
  if ($OM !== '') {
    if (!podeLerOm($S, $OM)) negar($S, 'sem acesso à auditoria da OM ' . $OM);
    $st = db()->prepare("SELECT $cols FROM auditoria WHERE om = ? ORDER BY ts DESC LIMIT 1000"); $st->execute([$OM]);
  } else {
    [$w, $par] = filtroOms($S, 'om');
    $st = db()->prepare("SELECT $cols FROM auditoria WHERE $w ORDER BY ts DESC LIMIT 2000"); $st->execute($par);
  }
  logApi(true, '', 'auditoria', $OM); out(['ok'=>true,'auditoria'=>$st->fetchAll()]);
}
if ($ROTA === 'foto') {                        // ?rota=foto&om=BAPV&nome=xxx.jpg
  $nome = q('nome');
  if ($OM === '' || $nome === '') erro('om e nome são obrigatórios');
  if (!siglaOk($OM) || !nomeFotoOk($nome)) { segLog('negado', 'nome de foto inválido: ' . cortar($nome, 0, 80), (int)$S['id'], (string)$S['login']); erro('nome de arquivo inválido', 400); }
  if (!podeLerOm($S, $OM)) negar($S, 'sem acesso às fotos da OM ' . $OM);
  $st = db()->prepare('SELECT caminho, mime FROM fotos WHERE om = ? AND nome = ?'); $st->execute([$OM, $nome]); $r = $st->fetch();
  $base = realpath(rtrim((string)$CFG['uploads'], '/\\'));
  $arq  = $r ? realpath((string)$r['caminho']) : false;
  if (!$r || !$arq || !$base || strpos($arq, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($arq)) erro('foto não encontrada', 404);
  $mime = in_array($r['mime'], ['image/jpeg','image/png','image/webp'], true) ? $r['mime'] : 'application/octet-stream';
  logApi(true, '', 'foto', $OM);
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . filesize($arq));
  header('Content-Disposition: inline; filename="' . $nome . '"');
  header('Cache-Control: private, max-age=3600');
  header_remove('Content-Security-Policy');
  header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
  readfile($arq); exit;
}

/* 13) POST do pacote da OM (o "Enviar ao servidor" do painel) */
if ($METHOD === 'POST' && $body instanceof stdClass && isset($body->om, $body->dados)) {
  $om = str($body->om, 40); if (!siglaOk($om)) erro('sigla inválida');
  if (!podeGravarOm($S, $om)) negar($S, 'sem permissão para enviar o pacote da OM ' . $om);
  $res = gravarPacote($body, $om, $S);
  out(['ok'=>true,'versao'=>'mysql-2.0','fotosNovas'=>$res['fotosNovas'],'fotosTotal'=>$res['fotosTotal'],'fotosRecusadas'=>$res['fotosRecusadas'],'fotosRenomeadas'=>$res['fotosRenomeadas'],
       'folderUrl'=>'(servidor interno) uploads/'.pastaOm($om).'/', 'sheetUrl'=>'(banco de dados interno · tabela pacotes)']);
}

erro('rota não reconhecida', 404);

/* =====================================================================
   FUNÇÕES DE APOIO
   ===================================================================== */
/* Remove do estado o que não pode circular: credenciais (hashes) e tokens. */
function limpaEstado($rec) {
  if (!($rec instanceof stdClass)) return $rec;
  unset($rec->auth);
  if (isset($rec->gas) && $rec->gas instanceof stdClass) unset($rec->gas->token);
  return $rec;
}
function nomeFotoOk(string $n): bool {
  return strlen($n) <= 180 && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*\.(jpe?g|png|webp)$/i', $n) && strpos($n, '..') === false;
}
/* Tipo real da imagem pelo conteúdo (não pela extensão nem pelo que o navegador diz). */
function tipoImagem(string $bin): string {
  if (function_exists('finfo_buffer')) {
    $f = finfo_open(FILEINFO_MIME_TYPE); $m = $f ? (string)finfo_buffer($f, $bin) : ''; if ($f) finfo_close($f);
    if (in_array($m, ['image/jpeg','image/png','image/webp'], true)) return $m;
    if ($m !== '' && $m !== 'application/octet-stream') return '';
  }
  if (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
  if (strncmp($bin, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
  if (strncmp($bin, 'RIFF', 4) === 0 && substr($bin, 8, 4) === 'WEBP') return 'image/webp';
  return '';
}

function gravarPacote(stdClass $pkg, string $om, array $S): array {
  global $CFG;
  unset($pkg->token);
  $om = sig((string)$om);                               // sigla canônica (pasta e índice sempre iguais)
  // OM, nome e ODS vêm do rol (fonte de autoridade), nunca do que o navegador informa
  $pkg->om = $om;
  $odsRol = odsDaOm($om); if ($odsRol !== '') $pkg->ods = $odsRol;
  $nomeRol = nomeDaOm($om); if ($nomeRol !== '') $pkg->omNome = $nomeRol;
  $fotos = is_array($pkg->fotos ?? null) ? $pkg->fotos : [];
  $pkg->fotos = [];                                     // as fotos vão para arquivos, não para o banco
  $dir = rtrim((string)$CFG['uploads'], '/\\') . '/' . pastaOm($om);
  if ($fotos && !is_dir($dir) && !@mkdir($dir, 0770, true)) erro('não foi possível criar a pasta de fotos (permissão de escrita em uploads/?)', 500);
  $pdo = db(); $novas = 0; $recusadas = [];
  $maxFoto = (int)$CFG['foto_max_mb'] * 1024 * 1024;
  $stF = $pdo->prepare('INSERT INTO fotos (om, nome, mime, registro_id, data_reg, tamanho, caminho) VALUES (?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE registro_id = VALUES(registro_id), data_reg = VALUES(data_reg)');
  $renomeadas = [];
  foreach ($fotos as $f) {
    $nome = basename(str(prop($f,'name',''), 200)); $b64 = (string)prop($f,'b64','');
    if ($nome === '' || $b64 === '') continue;
    if (!nomeFotoOk($nome)) { $recusadas[] = $nome . ' (nome)'; continue; }
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') { $recusadas[] = $nome . ' (base64)'; continue; }
    if (strlen($bin) > $maxFoto) { $recusadas[] = $nome . ' (tamanho)'; continue; }
    $mime = tipoImagem($bin);
    if ($mime === '') { $recusadas[] = $nome . ' (não é imagem JPEG/PNG/WebP)'; continue; }
    $caminho = $dir . '/' . $nome;
    if (is_file($caminho) && !hash_equals((string)sha1_file($caminho), sha1($bin))) {
      // mesmo nome, imagem diferente (foto trocada ou registro novo com o mesmo título):
      // nunca sobrescreve — grava com sufixo do conteúdo e corrige a referência no pacote
      $novo = preg_replace('/(\.[A-Za-z0-9]+)$/', '_' . substr(sha1($bin), 0, 8) . '$1', $nome);
      $renomeadas[$nome] = $novo; $nome = $novo; $caminho = $dir . '/' . $nome;
    }
    if (!is_file($caminho)) {
      $tmp = $caminho . '.tmp' . bin2hex(random_bytes(4));
      if (@file_put_contents($tmp, $bin, LOCK_EX) === false || !@rename($tmp, $caminho)) { @unlink($tmp); erro('falha ao gravar a foto '.$nome.' (permissão?)', 500); }
      $novas++;
    }
    $stF->execute([$om, $nome, $mime ?: null, str(prop($f,'registroId',''), 80), str(prop($f,'data',''), 40), is_file($caminho) ? filesize($caminho) : null, $caminho]);
  }
  if ($recusadas) segLog('foto_recusada', implode(', ', array_slice($recusadas, 0, 5)), (int)$S['id'], (string)$S['login']);
  $dados   = ($pkg->dados ?? null) instanceof stdClass ? $pkg->dados : new stdClass;
  if ($renomeadas && is_array($dados->registros ?? null)) {
    foreach ($dados->registros as $reg) { if (!is_object($reg) || !is_array($reg->fotos ?? null)) continue;
      foreach ($reg->fotos as $fo) { if (is_object($fo) && isset($fo->ref) && isset($renomeadas[(string)$fo->ref])) $fo->ref = $renomeadas[(string)$fo->ref]; } }
  }
  $savedAt = str(prop($dados,'savedAt', prop($pkg,'savedAt', now())), 40);
  $json    = json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $quem    = cortar(trim(($S['posto'] ?? '') . ' ' . $S['nome']), 0, 160);   // quem enviou = usuário da sessão
  $aud     = is_array($dados->auditoria ?? null) ? $dados->auditoria : [];
  $tot     = 0;
  $pdo->beginTransaction();
  try {
    $c = $pdo->prepare('SELECT COUNT(*) FROM fotos WHERE om = ?'); $c->execute([$om]); $tot = (int)$c->fetchColumn();
    $pdo->prepare('INSERT INTO pacotes (om, om_nome, ods, saved_at, enviado_em, enviado_por, conteudo, fotos) VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE om_nome=VALUES(om_nome), ods=VALUES(ods), saved_at=VALUES(saved_at), enviado_em=VALUES(enviado_em), enviado_por=VALUES(enviado_por), conteudo=VALUES(conteudo), fotos=VALUES(fotos)')
      ->execute([$om, str(prop($pkg,'omNome',''), 160), str(prop($pkg,'ods',''), 20), $savedAt, now(), $quem, $json, $tot]);
    $pdo->prepare('INSERT INTO pacotes_hist (om, saved_at, enviado_em, enviado_por, tamanho_kb, fotos, conteudo) VALUES (?,?,?,?,?,?,?)')
      ->execute([$om, $savedAt, now(), $quem, (int)round(strlen($json)/1024), $novas, $json]);
    $hm = (int)$CFG['hist_max'];
    if ($hm > 0) { $st = $pdo->prepare('SELECT id FROM pacotes_hist WHERE om = ? ORDER BY id DESC LIMIT 1000 OFFSET ?'); $st->bindValue(1, $om); $st->bindValue(2, $hm, PDO::PARAM_INT); $st->execute();
      $ids = array_column($st->fetchAll(), 'id'); if ($ids) $pdo->exec('DELETE FROM pacotes_hist WHERE id IN ('.implode(',', array_map('intval', $ids)).')'); }
    if ($aud) {
      $stA = $pdo->prepare('INSERT IGNORE INTO auditoria (om, ts, evento, acao, detalhe, posto, nome, repeticoes, hash, enviado_por_login) VALUES (?,?,?,?,?,?,?,?,?,?)');
      foreach ($aud as $a) { $ts = str(prop($a,'ts',''), 40); if ($ts === '') continue;
        $ev = str(prop($a,'ev','edicao'), 20); $ac = str(prop($a,'acao',''), 200); $dt = str(prop($a,'det',''), 2000); $nm = str(prop($a,'nome',''), 160);
        $h = sha1($om.'|'.$ts.'|'.$ev.'|'.$ac.'|'.$dt.'|'.$nm);
        $stA->execute([$om, $ts, $ev, $ac, $dt, str(prop($a,'posto',''), 40), $nm, (int)prop($a,'n',1), $h, cortar((string)$S['login'], 0, 120)]); }
    }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); error_log('SISI pacote ' . $om . ': ' . $e->getMessage()); erro('falha ao gravar o pacote (detalhe no log de erros do PHP)', 500); }
  logApi(true, '', 'pacote', $om);
  return ['fotosNovas'=>$novas, 'fotosTotal'=>$tot, 'fotosRecusadas'=>$recusadas, 'fotosRenomeadas'=>(object)$renomeadas];
}

/* ---------- usuários ---------- */
function listaUsuarios(): array {
  $rows = db()->query('SELECT id, login, nome, posto, om_lotacao, tel, email, perfil, escopo, ativo, troca_obrigatoria, criado_em, alterado_em, senha_alterada_em, ultimo_login,
                              (senha_hash IS NULL AND legado_hash IS NOT NULL) AS legado FROM usuarios ORDER BY nome')->fetchAll();
  $l = []; $v = [];
  foreach ($rows as $r) {
    // versão = conteúdo dos campos que o gestor edita (a troca de senha pelo próprio usuário não invalida a lista)
    $v[] = implode("\x1f", [$r['id'], $r['login'], $r['nome'], $r['posto'], $r['om_lotacao'], $r['tel'], $r['email'], $r['perfil'], $r['escopo'], $r['ativo']]);
    $l[] = ['id'=>(int)$r['id'], 'login'=>$r['login'], 'nome'=>$r['nome'], 'posto'=>(string)$r['posto'], 'omUser'=>(string)$r['om_lotacao'],
            'tel'=>(string)$r['tel'], 'email'=>(string)$r['email'], 'perfil'=>$r['perfil'], 'escopo'=>$r['escopo'],
            'rotulo'=>trim($r['posto'] . ' ' . $r['nome']), 'ativo'=>(int)$r['ativo'] === 1,
            'trocaPendente'=>(int)$r['troca_obrigatoria'] === 1, 'senhaHerdada'=>(int)$r['legado'] === 1,
            'criadoEm'=>$r['criado_em'], 'alteradoEm'=>$r['senha_alterada_em'] ?: $r['criado_em'], 'ultimoLogin'=>$r['ultimo_login']];
  }
  return ['usuarios'=>$l, 'versao'=>sha1(implode('|', $v))];
}
function slugLogin(string $s): string {
  $t = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s;
  $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '.', $t));
  return trim(preg_replace('/\.+/', '.', $t), '.');
}
function loginLivre(string $base, ?int $exceto = null): string {
  $base = cortar($base !== '' ? $base : 'usuario', 0, 100); $l = $base; $i = 2;
  $st = db()->prepare('SELECT id FROM usuarios WHERE login = ?');
  while (true) { $st->execute([$l]); $id = $st->fetchColumn(); if ($id === false || ($exceto !== null && (int)$id === $exceto)) return $l; $l = $base . '.' . $i++; }
}
/* Login sugerido: e-mail (se houver) ou perfil.escopo.nome — ex.: om.bapv.silva */
function loginSugerido(string $email, string $perfil, string $escopo, string $nome): string {
  $email = strtolower(trim($email));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/^[a-z0-9][a-z0-9._@-]{2,119}$/', $email)) return $email;
  $ult = preg_split('/\s+/', trim($nome)); $sob = $ult ? end($ult) : '';
  return slugLogin($perfil . ($escopo !== '' ? ' ' . $escopo : '') . ' ' . $sob);
}
function validaCad(stdClass $c): array {
  $perfil = str(prop($c,'perfil',''), 10);
  if (!in_array($perfil, ['gestor','ods','om'], true)) throw new CadInvalido('perfil inválido: ' . $perfil);
  $escopo = $perfil === 'gestor' ? '' : sig(str(prop($c,'escopo',''), 40));
  if ($perfil !== 'gestor' && ($escopo === '' || !siglaOk($escopo))) throw new CadInvalido('escopo (ODS/OM) inválido para ' . str(prop($c,'nome',''), 60));
  $nome = str(prop($c,'nome',''), 160); if ($nome === '') throw new CadInvalido('nome obrigatório');
  foreach (['nome','posto','omUser','tel','email'] as $k) if (preg_match('/[<>"`]/', str(prop($c,$k,''), 200))) throw new CadInvalido('caracteres não permitidos (< > " `) em ' . $k . ' de ' . cortar($nome, 0, 40));
  $email = str(prop($c,'email',''), 160); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new CadInvalido('e-mail inválido: ' . $email);
  $login = strtolower(str(prop($c,'login',''), 120));
  if ($login !== '' && !preg_match('/^[a-z0-9][a-z0-9._@-]{2,119}$/', $login) && $login !== strtolower(str(prop($c,'_loginAtual',''), 120))) throw new CadInvalido('usuário (login) inválido: ' . $login . ' — use de 3 a 120 caracteres: letras minúsculas, números, ponto, hífen, @');
  return ['perfil'=>$perfil, 'escopo'=>$escopo ?: null, 'nome'=>$nome, 'posto'=>str(prop($c,'posto',''), 40), 'om_lotacao'=>str(prop($c,'omUser',''), 80),
          'tel'=>str(prop($c,'tel',''), 40), 'email'=>$email, 'login'=>$login];
}
/* Sincroniza a lista editada na Área Admin. Controle otimista: a lista enviada
   precisa partir da versão atual do servidor (evita que um navegador com lista
   antiga apague usuários cadastrados em outro). Senhas novas chegam como
   sha256(salt+senha) gerado no navegador do gestor e valem só até o 1º acesso,
   quando o usuário é obrigado a trocar (a nova vai para password_hash). */
function travaUsuarios(): void {   // uma alteração de usuários por vez (duplo clique, duas abas)
  try { db()->query("SELECT GET_LOCK('sisi_usuarios', 15)"); } catch (Throwable $e) { }
}
function sincronizarUsuarios(array $S, stdClass $b): array {
  travaUsuarios();
  $atual = listaUsuarios();
  if (str(prop($b,'versao',''), 64) !== $atual['versao']) erro('a lista de usuários foi alterada em outro navegador — recarregue a lista e refaça a alteração', 409, ['codigo'=>'versao']);
  $lista = prop($b, 'credenciais', []); if (!is_array($lista)) erro('credenciais deve ser uma lista');
  $existentes = []; foreach ($atual['usuarios'] as $u) $existentes[$u['id']] = $u;
  $pdo = db(); $res = ['criados'=>0,'alterados'=>0,'removidos'=>0,'senhas'=>0]; $vistos = [];
  $pdo->beginTransaction();
  try {
    foreach ($lista as $c) {
      if (!($c instanceof stdClass)) continue;
      $id = (int)prop($c,'id',0);
      if ($id && isset($existentes[$id])) $c->_loginAtual = $existentes[$id]['login'];
      $d = validaCad($c);
      $salt = str(prop($c,'salt',''), 64); $hash = strtolower(str(prop($c,'hash',''), 64));
      $temSenha = $salt !== '' && preg_match('/^[a-f0-9]{64}$/', $hash);
      if ($id && isset($existentes[$id])) {
        $vistos[$id] = 1;
        $e = $existentes[$id];
        $login = $d['login'] !== '' ? $d['login'] : $e['login'];
        if ($login !== $e['login'] && loginLivre($login, $id) !== $login) throw new CadInvalido('o usuário (login) "' . $login . '" já está em uso');
        $mudou = $login !== $e['login'] || $d['nome'] !== $e['nome'] || $d['posto'] !== $e['posto'] || $d['om_lotacao'] !== $e['omUser'] || $d['tel'] !== $e['tel']
              || $d['email'] !== $e['email'] || $d['perfil'] !== $e['perfil'] || (string)$d['escopo'] !== (string)$e['escopo'];
        if ($mudou) {
          if ($id === (int)$S['id'] && $d['perfil'] !== 'gestor') throw new CadInvalido('você não pode retirar o seu próprio perfil de gestor');
          $pdo->prepare('UPDATE usuarios SET login=?, nome=?, posto=?, om_lotacao=?, tel=?, email=?, perfil=?, escopo=?, alterado_em=NOW() WHERE id=?')
              ->execute([$login, $d['nome'], $d['posto'], $d['om_lotacao'], $d['tel'], $d['email'], $d['perfil'], $d['escopo'], $id]);
          if ($d['perfil'] !== $e['perfil'] || (string)$d['escopo'] !== (string)$e['escopo']) $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
          $res['alterados']++; segLog('usuario_alterado', $login . ' · ' . $d['perfil'] . ' ' . $d['escopo'], (int)$S['id'], (string)$S['login']);
        }
        if ($temSenha) {
          $pdo->prepare('UPDATE usuarios SET senha_hash=NULL, legado_salt=?, legado_hash=?, troca_obrigatoria=1, senha_alterada_em=NOW() WHERE id=?')->execute([$salt, embrulhaLegado($hash), $id]);
          $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
          $res['senhas']++; segLog('senha_redefinida', $login . ' (pelo gestor)', (int)$S['id'], (string)$S['login']);
        }
        continue;
      }
      if (!$temSenha) throw new CadInvalido('defina a senha do novo usuário ' . $d['nome']);
      $login = $d['login'] !== '' ? $d['login'] : loginSugerido($d['email'], $d['perfil'], (string)$d['escopo'], $d['nome']);
      if ($d['login'] !== '' && loginLivre($login) !== $login) throw new CadInvalido('o usuário (login) "' . $login . '" já está em uso');
      $login = loginLivre($login);
      $pdo->prepare('INSERT INTO usuarios (login, nome, posto, om_lotacao, tel, email, perfil, escopo, legado_salt, legado_hash, troca_obrigatoria, alterado_em, senha_alterada_em)
                     VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())')
          ->execute([$login, $d['nome'], $d['posto'], $d['om_lotacao'], $d['tel'], $d['email'], $d['perfil'], $d['escopo'], $salt, embrulhaLegado($hash)]);
      $res['criados']++; segLog('usuario_criado', $login . ' · ' . $d['perfil'] . ' ' . $d['escopo'], (int)$S['id'], (string)$S['login']);
    }
    foreach ($existentes as $id => $e) {
      if (isset($vistos[$id])) continue;
      if ($id === (int)$S['id']) throw new CadInvalido('você não pode remover o seu próprio usuário');
      $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
      $res['removidos']++; segLog('usuario_removido', $e['login'], (int)$S['id'], (string)$S['login']);
    }
    $g = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'gestor' AND ativo = 1")->fetchColumn();
    if ($g < 1) throw new CadInvalido('é preciso manter pelo menos um gestor ativo');
    $pdo->commit();
  } catch (CadInvalido $e) { if ($pdo->inTransaction()) $pdo->rollBack(); erro($e->getMessage(), 400);
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('SISI usuarios: ' . $e->getMessage()); erro('falha ao gravar os usuários', 500); }
  return ['resumo'=>$res];
}
/* Migração: importa as credenciais do painel antigo (estado.auth do JSONBin).
   A senha atual continua valendo no primeiro acesso (hash herdado) e a troca é
   obrigatória logo em seguida (decisão do gestor, 10/10/2026). */
function importarUsuarios(array $S, $lista): array {
  if (!is_array($lista)) erro('credenciais deve ser uma lista');
  $pdo = db(); $rel = [];
  travaUsuarios(); $hashesVistos = [];
  $stN = $pdo->prepare('SELECT login FROM usuarios WHERE perfil = ? AND COALESCE(escopo, \'\') = ? AND nome = ?');
  $ins = $pdo->prepare('INSERT INTO usuarios (login, nome, posto, om_lotacao, tel, email, perfil, escopo, legado_salt, legado_hash, troca_obrigatoria, criado_em, alterado_em, senha_alterada_em)
                        VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),NOW())');
  foreach ($lista as $c) {
    if (!($c instanceof stdClass)) continue;
    $nome = str(prop($c,'nome','') ?: prop($c,'rotulo',''), 160);
    $c->nome = $nome;
    try { $d = validaCad($c); } catch (CadInvalido $e) { $rel[] = ['nome'=>$nome, 'situacao'=>'ignorado: ' . $e->getMessage()]; continue; }
    $salt = str(prop($c,'salt',''), 64); $hash = strtolower(str(prop($c,'hash',''), 64));
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) { $rel[] = ['nome'=>$nome, 'situacao'=>'ignorado (sem senha)']; continue; }
    if (isset($hashesVistos[$hash])) { $rel[] = ['nome'=>$nome, 'login'=>$hashesVistos[$hash], 'situacao'=>'já importado']; continue; }
    $stN->execute([$d['perfil'], (string)$d['escopo'], $d['nome']]); if ($l = $stN->fetchColumn()) { $rel[] = ['nome'=>$nome, 'login'=>$l, 'situacao'=>'já existe (mesmo nome e escopo)']; continue; }
    $login = loginLivre(loginSugerido($d['email'], $d['perfil'], (string)$d['escopo'], $d['nome']));
    $ins->execute([$login, $d['nome'], $d['posto'], $d['om_lotacao'], $d['tel'], $d['email'], $d['perfil'], $d['escopo'], $salt, embrulhaLegado($hash)]);
    $hashesVistos[$hash] = $login;
    $rel[] = ['nome'=>trim($d['posto'] . ' ' . $d['nome']), 'login'=>$login, 'perfil'=>$d['perfil'], 'escopo'=>(string)$d['escopo'], 'situacao'=>'importado'];
  }
  $n = count(array_filter($rel, function ($r) { return $r['situacao'] === 'importado'; }));
  segLog('usuarios_importados', $n . ' de ' . count($lista), (int)$S['id'], (string)$S['login']);
  return ['importacao'=>$rel];
}
