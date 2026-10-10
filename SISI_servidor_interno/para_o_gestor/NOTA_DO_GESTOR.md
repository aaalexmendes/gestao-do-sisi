# Nota para o gestor — duas linhas de versão

**Linha em uso (GitHub Pages + JSONBin + Apps Script)**: continua sendo o `index.html` publicado
no repositório `gestao-do-sisi`, hoje na **u2.27**. Nada muda enquanto a TI não concluir a instalação.

**Linha do servidor interno (esta pasta)**: `sisi/index.html` é a **u2.28** = u2.27 + o cartão
"Servidor interno · MySQL / PHP" na Área Admin. É o único ponto de diferença entre as duas.
Enquanto o cartão não for preenchido, ela se comporta exatamente como a u2.27 (JSONBin/Apps Script).

## Quando a TI avisar que está no ar
1. Abra `http://<servidor>/sisi/index.html` como gestor.
2. Área Admin → "Servidor interno · MySQL / PHP" → URL da api.php + token → **Salvar e testar**.
3. "Compartilhamento" → **Enviar** (leva o estado atual para o MySQL).
4. Peça às OMs que passem a abrir o painel pelo endereço interno e cliquem **Enviar ao servidor** uma vez.
5. Só depois de uma semana de uso normal, considere desativar o JSONBin/Apps Script.

## Manutenção das duas linhas
Correções futuras devem ser feitas na linha u2.27+ e reaplicadas na variante com
`build_u228_variante_mysql.py` (13 patches por âncora; roda em segundos sobre o HTML da linha principal).
