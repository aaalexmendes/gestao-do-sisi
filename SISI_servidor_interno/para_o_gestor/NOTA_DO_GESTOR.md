# Nota para o gestor — variante do servidor interno (u2.30-srv)

## Duas linhas, como combinado

- **Linha em uso** (GitHub Pages + JSONBin + Apps Script): o `index.html` do repositório, **u2.30, sem nenhuma alteração**. Continue usando normalmente enquanto a TI não concluir a instalação.
- **Linha do servidor interno**: `sisi/index.html` = **u2.30-srv**, gerada da u2.30 por `build_u230_variante_srv.py`. O número da linha principal fica intacto.

## O que muda para quem usa o painel do servidor interno

1. **Entrada com usuário e senha.** Antes, bastava a senha. O usuário é o e-mail cadastrado ou um login gerado como `om.bapv.silva`; a lista da Área Admin mostra o de cada pessoa.
2. **Primeiro acesso:** quem entra com uma senha recebida (provisória ou a do painel antigo) é obrigado a criar a própria senha, com **mínimo de 12 caracteres** (dica: uma frase de três ou quatro palavras).
3. **Nada a configurar nos navegadores.** O painel encontra a `api.php` sozinho. Não há mais token para distribuir às OMs.
4. **Cadastro de usuários** (Área Admin → Acessos e usuários) grava **direto no servidor**. Não é mais preciso "Enviar dados ao JSONBin" para uma senha valer. Há um campo novo, **Usuário (login)**: deixe em branco para usar o e-mail ou um login gerado.
5. **E-mail de acesso:** passa a informar o **usuário** e avisa que a senha é provisória. Nos modelos personalizados, pode usar o marcador `{LOGIN}`.
6. **Sessão de 8 h.** Depois disso, ou ao clicar **Sair**, é preciso entrar de novo. Há um botão **Trocar senha** na barra superior.
7. **ODS:** somente consulta das OMs do próprio ODS (decisão de 10/10). A API recusa qualquer gravação de ODS em dados de OM.
8. **Fotos dos registros** aparecem para quem tem acesso à OM: a própria OM, o ODS dela e o gestor.

## Quando a TI avisar que está no ar

1. Abra o endereço interno (ex.: `https://intranet/sisi/`) e clique **Primeira configuração**. Informe o token que a TI passar, o seu usuário, nome e senha.
2. Área Admin → **Sincronização** → **Migração dos dados atuais** → **Importar do JSONBin**. O computador precisa de internet; se o bin for privado, preencha antes a chave no cartão do JSONBin. A alternativa é **Importar de arquivo .json**.
3. Confira a tabela que aparece: ela traz o **login** de cada usuário. Cada pessoa entra com esse login e a senha que já tinha, e cria uma nova na hora.
4. Peça às OMs que entrem pelo novo endereço e cliquem **Enviar ao servidor** uma vez (os lançamentos delas estão no navegador de cada uma).
5. Só depois de algumas semanas de uso normal, considere desativar o JSONBin e o Apps Script.

## Manutenção das duas linhas

Correções futuras continuam sendo feitas na linha principal (u2.30+). Para refletir na variante:

```
cd para_o_gestor
python3 build_u230_variante_srv.py      # lê ../../index.html, grava ../sisi/index.html
```

O script aplica 53 alterações por âncora. Se a linha principal mudou num trecho que ele altera, ele **para** e diz qual âncora sumiu; nada é gerado pela metade. Ele também atualiza o rol inicial de OMs no `schema.sql`.

## Pontos para sua decisão (não bloqueiam a instalação)

0. **Dados financeiros no estado.** O estado do gestor (execução 2026, semana anterior, prazos, RaP, planejamento) continua sendo entregue inteiro a qualquer usuário logado, como no JSONBin; o painel é que mostra a cada um só a sua OM/ODS. Isso quer dizer que uma OM que inspecione o tráfego consegue ver os números das outras OMs. Lançamentos, fotos, auditoria e ciências já são filtrados pelo servidor. Se quiser, filtro também o estado no servidor (cada OM recebe só as suas linhas; o ODS, as do seu ODS). Isso muda o carregamento do painel e precisa de testes com os dados reais. **A execução financeira pode ser vista por todas as OMs, ou deve ser restrita?**

1. **Ciência do ODS.** Deixei o ODS registrar a **própria** ciência dos avisos, porque é um dado dele e não da OM. Se preferir bloquear, é uma linha na API.
2. **Nome da ferramenta e endereço:** nada foi renomeado no código, como combinado.
3. **HTTPS:** recomendo pedir à TI um certificado interno. Sem ele, a senha trafega sem cifragem na rede.
4. **Biblioteca de planilhas (xlsx 0.18.5):** a mesma da u2.30, com duas vulnerabilidades publicadas ao ler planilhas maliciosas (CVE-2023-30533 e CVE-2024-22363). O risco é baixo, porque só o gestor importa planilhas. Ainda assim, vale atualizar na linha principal numa próxima versão (detalhes no README da TI, item 11).
5. **Linha principal (fora deste escopo, só para registro):** o estado publicado no JSONBin contém os hashes das senhas (`auth`). O painel lê o bin sem chave (`JSONBIN_BUILTIN.accessKey` vazio), e o Bin ID está no HTML público. Se o bin estiver aberto para leitura (não conferi, para não baixar os hashes reais), quem tiver o arquivo pode tentar adivinhar senhas fracas fora do sistema. As senhas geradas pelo botão **Gerar** (12 caracteres aleatórios) resistem bem a isso; senhas escolhidas à mão, não. A variante do servidor elimina esse ponto.
