<?php
/* Roteador do servidor embutido do PHP (php -S), usado SÓ nos testes.
   Reproduz as regras do .htaccess: só a api.php executa; config.php, .sql,
   .md e a pasta uploads/ nunca são servidos. */
$p = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (preg_match('#/uploads(/|$)#i', $p) || preg_match('#/\.#', $p)
    || preg_match('#\.(sql|md|log|sh|py|bak|ini|config)$#i', $p)
    || (preg_match('#\.(php|phtml|phar|inc)$#i', $p) && basename($p) !== 'api.php')) {
  http_response_code(403); header('Content-Type: text/plain'); echo "403 Forbidden\n"; return true;
}
return false;
