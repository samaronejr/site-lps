# Guia do docente: painel de tarefas

Este guia explica, em português, cada tarefa que o painel do site do LPS oferece a docentes e
delegados. Todos os nomes de telas, botões e mensagens citados aqui são os que o sistema exibe de
fato; se algo na tela divergir deste texto, avise a equipe editorial, porque a divergência é um
defeito e não uma nova regra.

O painel fica em `/pt-br/painel/` e é renderizado no servidor, sem JavaScript. Ele não concede
permissão nenhuma por si só: cada envio de formulário passa pela mesma verificação de escopo e de
papel que a API usa. Quem pode fazer o quê está em
[role workflows](role-workflows.md) (em inglês); a política editorial completa está em
[governança editorial](../content/governance.md).

## Antes de começar

- Conta individual, com papel `professor` ou `delegate`. Contas compartilhadas são recusadas.
- Professor precisa de verificação em duas etapas habilitada; sem ela, restam apenas leitura e
  perfil.
- Pelo menos uma permissão de escopo registrada por um administrador ou por um editor de seção da
  coleção `teaching`. A permissão vale para uma oferta específica ou para o escopo `news`, tem
  validade e pode ser revogada. Sem permissão, a lista de tarefas aparece vazia com o aviso
  "Nenhuma oferta está atribuída à sua conta ainda".
- Delegado prepara rascunhos. Publicar, liberar material e copiar para o próximo período são
  ações exclusivas do professor; os botões correspondentes nem aparecem para o delegado. Cada
  rascunho criado por um delegado carrega a marca `Preparado por` com o nome dele e aparece com
  esse selo na área do professor, que revisa, edita e publica sob a própria autoridade.

## As tarefas do painel

Toda tela do painel abre com o selo `Área do membro`, o título da tela e a linha
`Tarefas, ofertas e envios vinculados à sua conta.` — o mesmo cabeçalho das páginas públicas.
A página inicial lista apenas as tarefas que o seu escopo cobre:

| Tarefa | Quem vê | Para que serve |
| --- | --- | --- |
| `Meu perfil` | professor, delegado | Propor alterações no seu registro público de pessoa. |
| `Minhas ofertas` | professor, delegado | Abrir a área da oferta: unidades, materiais e a cópia do próximo período. |
| `Enviar notícia` | professor ou delegado com escopo `news` | Rascunhar uma notícia para revisão editorial. |
| `Enviar evento` | professor ou delegado com escopo `news` | Rascunhar um evento para revisão editorial. |
| `Fila de revisão` | editor de seção, publicador | Aprovar ou rejeitar envios e propostas pendentes. |
| `Criar oferta` | editor de seção, publicador | Criar uma oferta em rascunho ligando disciplina, período e turma. |
| `Criar disciplina` | professor, administrador | Cadastrar uma disciplina e abrir a primeira oferta em um único envio, sem revisão. |
| `Gerenciar usuários` | professor, administrador | Criar contas de membro, aplicar categorias e definir novas categorias de acesso. |

Se você é docente e não vê `Fila de revisão` nem `Criar oferta`, está certo: essas tarefas são
editoriais. Uma conta de docente nunca edita ofertas fora do seu escopo, equipes docentes, campos
de revisão, de verificação de arquivo ou de armazenamento.

## Meu perfil (`/pt-br/painel/perfil/`)

Alterações de perfil são propostas, não edições diretas. Um editor revisa antes de o registro
público mudar; o valor atual aparece ao lado de cada campo.

1. Abra `Meu perfil`. O formulário `Propor alterações de perfil` mostra os campos `E-mail público`,
   `ORCID`, `URL do Lattes`, `URL do Scholar` e `URL do site`, cada um com a dica `Atual:` seguida
   do valor vigente.
2. Preencha ao menos um campo. Enviar vazio retorna "Preencha ao menos um campo antes de enviar."
3. Clique em `Enviar proposta para revisão`. O aviso "Proposta de perfil enviada para revisão."
   confirma o envio e a proposta entra no `Histórico de propostas` como `Aguardando revisão`.
4. O resultado aparece no mesmo histórico: `Aprovado` significa que o registro público já mudou;
   `Rejeitado` vem acompanhado da nota do editor explicando a correção necessária.

Formatos conferidos no envio: ORCID no formato `0000-0000-0000-0000`, e-mail e URLs válidos. Um
erro de validação devolve o formulário com o campo indicado e os valores preservados.

## Enviar notícia (`/pt-br/painel/noticias/`)

Disponível para professor e delegado com o escopo `news` concedido. Os envios entram em revisão como
rascunhos; um rascunho salvo nunca é público. Um rascunho criado por um delegado aparece na lista
do professor com o selo `Preparado por` seguido do nome do delegado: o professor abre
`Editar e reenviar`, ajusta e envia sob a própria autoridade — a marca continua como
procedência, mesmo se a autoria do rascunho for transferida depois.

1. Abra `Enviar notícia` e preencha `Título`, `Resumo`, `Conteúdo` e `Data de publicação`.
   Opcionalmente escolha o `Tema` (Pessoas, Pesquisa, História, Ensino, Institucional ou
   Parcerias) e anexe uma `Imagem destacada` — JPG, PNG ou WebP, até 15 MB — com a
   `Descrição da imagem` preenchida (ela serve como texto alternativo e legenda).
2. Clique em `Pré-visualizar o cartão`. A etapa de pré-visualização mostra o cartão da
   notícia exatamente como aparecerá publicamente — data, tema, título, resumo e a imagem.
   Nada é enviado nesse ponto: confira e clique em `Enviar para revisão` ou em
   `Descartar pré-visualização` para voltar ao formulário.
3. O aviso "Enviado para revisão. Um editor decidirá." confirma o envio e o item aparece em
   `Minhas notícias` com o estado `Em revisão`.
4. Se o editor rejeitar, o item volta para `Rascunho` com a nota da revisão visível. Abra
   `Editar e reenviar`, corrija — tema e imagem também podem ser trocados aí — e clique em
   `Reenviar para revisão`.
5. Quando aprovada, a notícia fica `Público` e o link público passa a funcionar. Se a imagem
   for recusada, o erro nomeia o arquivo e os tipos aceitos ("O arquivo "X" não é um tipo
   aceito. Tipos permitidos: JPG, PNG, WebP.").

A submissão é em português primeiro; a variante em inglês vem depois. Com o item em
`Minhas notícias`, abra `Adicionar tradução EN` no próprio registro e preencha `Título em
inglês`, `Resumo em inglês` e `Conteúdo em inglês`. O envio cria a variante EN já associada e
revisada — o aviso "Tradução em inglês registrada e enviada para revisão." confirma. Se o texto
em português mudar depois, a tarefa volta como `Atualizar a tradução EN` até que a variante
seja revista de novo; quando a tradução existe e está revisada, a tarefa desaparece.

## Enviar evento (`/pt-br/painel/eventos/`)

Disponível para professor e delegado com o escopo `news` concedido — a mesma permissão da
notícia, em faixa própria. Os envios entram em revisão como rascunhos e um editor decide a
publicação; um rascunho nunca é público.

1. Abra `Enviar evento` e preencha `Título`, `Resumo`, `Conteúdo` e `Início (data e hora)`
   — obrigatórios. Opcionalmente informe `Término (data e hora)`, `Local`, `Link on-line`,
   `Link de inscrição`, o `Status do evento` (programado, adiado ou cancelado) e uma
   `Imagem destacada` com a `Descrição da imagem` — mesma disciplina de upload da notícia.
2. Clique em `Pré-visualizar o cartão`. A etapa mostra o cartão do evento como aparecerá
   publicamente — data, título, resumo, local e a imagem. Nada é enviado nesse ponto:
   confirme em `Enviar para revisão` ou volte com `Descartar pré-visualização`.
3. O aviso "Enviado para revisão. Um editor decidirá." confirma o envio e o item aparece
   em `Meus eventos` com o estado `Em revisão`. A `Fila de revisão` dos editores lista
   eventos na faixa própria, abaixo das notícias.
4. Se o editor rejeitar, o item volta para `Rascunho` com a nota da revisão visível e a
   decisão chega por e-mail. Abra `Editar e reenviar`, corrija e reenvie.
5. Aprovado, o evento fica `Público` e passa a aparecer na seção de eventos e na linha do
   tempo institucional da página `Notícias e eventos`.

A tradução funciona como a da notícia: com o item em `Meus eventos`, abra `Adicionar
tradução EN` e preencha os campos em inglês. O envio cria a variante EN já associada e
revisada; se o português mudar depois, a tarefa volta como `Atualizar a tradução EN`.

## Criar disciplina (`/pt-br/painel/disciplinas/`)

Disponível para professor e administrador — o docente cadastra disciplinas diretamente, sem
passar por revisão editorial. A conta precisa ter a verificação em duas etapas ativa e um
registro de pessoa vinculado; sem isso, a tela mostra o aviso de acesso em vez do formulário.

1. Abra `Criar disciplina`. O formulário tem dois blocos: `Disciplina` e `Primeira oferta`.
2. Em `Disciplina`, preencha `Título`, `Código`, `Nível` e `Calendário` (obrigatórios) e,
   opcionalmente, `Programa`, `Pré-requisitos`, `Ementa`, `Resumo` e `Conteúdo`.
3. Em `Primeira oferta`, escolha o `Período`, informe a `Turma` e, opcionalmente, `Horários`,
   `Local` e a `Equipe docente`. A oferta precisa de uma equipe com responsável e, como
   docente, você precisa constar nela — o formulário traz duas linhas de membro, para você
   e para o responsável quando são pessoas diferentes. O `Período` precisa ter um
   calendário declarado igual ao `Calendário` da disciplina.
4. Clique em `Criar disciplina e primeira oferta`. O aviso "Disciplina e primeira
   oferta criadas como rascunho — publique a oferta pela área de trabalho e a
   disciplina entra no ar junto." confirma.
5. Os dois registros nascem como rascunho e a oferta já aparece na sua lista
   `Minhas ofertas`: a permissão sobre ela é concedida automaticamente. Quando a
   oferta estiver pronta (incluindo a variante em inglês, que pode ser adicionada
   depois), publique-a pela área de trabalho — a disciplina publica junto
   automaticamente.

Se a segunda etapa falhar, a disciplina recém-criada é removida automaticamente — tente de
novo sem medo de duplicar cadastros.

## Gerenciar usuários (`/pt-br/painel/usuarios/`)

A tela lista três blocos: `Membros`, `Adicionar membro` e `Categorias de membro`. Para docentes,
a tarefa exige o segundo fator ativo — sem ele, a tela mostra o aviso de exigência em vez das
listas.

- **Membros**: cada conta mostra nome, categoria, selo de suspensão e a marcação `(você)` na
  sua própria linha. `Gerenciar conta` abre duas ações: `Aplicar categoria` (troca o papel e as
  áreas de conteúdo da conta) e `Suspender`/`Reativar` (suspender reduz a conta ao papel de
  membro sem apagar nada; reativar devolve o papel da categoria). Suspender duas vezes seguidas
  ou reativar uma conta que não está suspensa é recusado — o papel original fica guardado na
  primeira suspensão. Você não pode se suspender, e
  um docente não mexe em contas de administrador nem concede categorias com privilégio de
  administrador — a conta suspensa de um administrador continua protegida.
- **Adicionar membro**: nome, e-mail e login (o login sai do e-mail quando fica em branco),
  categoria e senha inicial (mínimo de 8 caracteres, entregue ao membro por fora — ele a troca
  no primeiro acesso). A caixa `Criar também o registro público de pessoa` cria um rascunho de
  pessoa ligado à conta, com os papéis públicos definidos na categoria — é assim que a nova
  conta passa a aparecer nas equipes docentes das ofertas.
- **Categorias de membro**: as cinco internas — `Professor`, `Doutorado`, `Mestrado`,
  `Graduação` e `Secretaria de laboratório` — não podem ser removidas. `Criar nova categoria`
  abre um formulário com chave, rótulos em português e inglês, privilégio (as opções sobem até
  `editor(a) publicador(a)`; `administrador` só aparece para administradores), as áreas de
  conteúdo que a categoria alcança (para contribuinte, tradutor e editor de seção) e os papéis
  públicos que o registro de pessoa recebe. Categorias em uso por alguma conta não podem ser
  removidas.

## Área da oferta (`/pt-br/painel/ofertas/`)

Cada oferta atribuída abre uma área de trabalho com os links `Ver a página pública` e
`Editar o registro da oferta`, a seção `Dados da oferta`, a lista `Avisos`, as listas
`Unidades` e `Materiais`, e os formulários descritos abaixo.

### Dados da oferta

1. Em `Dados da oferta`, ajuste `Horários`, `Local` e `Notas do período` e clique em
   `Salvar dados da oferta`.
2. `Horários` e `Local` aparecem na página pública da oferta nos dois idiomas. As
   `Notas do período` são do registro em português — a versão em inglês é um campo
   localizado da variante e atualiza pela tarefa de tradução.
3. A edição exige permissão sobre a oferta; fora do seu escopo, o envio é recusado com
   "Este registro está fora do seu escopo atribuído.".

### Avisos

1. Em `Avisos`, escreva o texto no campo `Aviso` e clique em `Publicar aviso`. O aviso aparece
   na hora na seção `Avisos` da página pública da oferta, nas rotas em português e em inglês,
   do mais recente ao mais antigo.
2. Avisos são um fluxo próprio, leve e PT-first: não passam pela fila de publicação nem
   exigem variante em inglês — a decisão é manter o mural da turma sem fricção editorial.
3. `Remover` tira o aviso da página pública; a remoção é imediata e definitiva.

### Adicionar unidade

1. Em `Adicionar unidade`, preencha `Título`, `Âncora` (o identificador estável da unidade),
   `Posição` (número inteiro a partir de 1) e, se quiser, `Resumo`, `Conteúdo` (o corpo da
   aula, com parágrafos e HTML básico) e `Data do tópico` no formato `AAAA-MM-DD`.
2. Clique em `Criar a unidade em rascunho`. A unidade entra na lista como `Rascunho`.
3. Para ajustar uma unidade existente, abra `Editar unidade` nela ou use o link `Editar` para
   abri-la no editor: título, resumo, conteúdo, âncora, posição e data do tópico ficam
   editáveis, e `Salvar unidade` grava tudo.
4. Professor pode publicar a unidade com `Publicar unidade`. Unidade é conteúdo interno: ela
   organiza os materiais e não vira página pública própria — mas seu `Conteúdo` renderiza na
   página pública da oferta, dentro da unidade.

Uma unidade preparada por um delegado aparece na lista com o selo `Preparado por` seguido do nome
do delegado; o professor edita e publica normalmente sob a própria autoridade.

### Adicionar material

1. Em `Adicionar material`, preencha `Título`, `Resumo`, `Tipo de material`, `Idioma do material`
   e, se couber, a unidade à qual o material pertence.
2. Anexe um arquivo ou informe uma `URL externa`, nunca os dois. O sistema recusa o envio com os
   dois preenchidos.
3. Clique em `Criar o material em rascunho`. O material entra na lista `Materiais` como
   `Rascunho`. Um material preparado por um delegado aparece com o selo `Preparado por`, como
   nas unidades.

Um arquivo enviado vira uma versão imutável identificada pelo seu hash. Ninguém edita um arquivo
já enviado: corrigir é enviar uma versão nova (veja "Corrigir ou retirar" abaixo).

### Liberar um material

Antes de liberar, três condições precisam estar cumpridas, e nenhuma é sua para preencher:

- a verificação do arquivo terminou (o estado sai de `Verificação pendente`; se aparecer
  `Verificação falhou`, reenvie o arquivo ou avise a equipe técnica);
- a revisão de direitos está aprovada por um editor;
- a revisão de acessibilidade está aprovada por um editor.

Sem isso, a liberação é recusada com a mensagem correspondente: "A revisão de direitos precisa
estar aprovada antes da publicação." ou "A revisão de acessibilidade precisa estar aprovada antes
da publicação."

Com tudo aprovado, o professor tem duas opções no material:

- `Publicar agora`: libera o download público imediatamente.
- `Agendar`: preencha `Publicar em` com data e hora. A liberação acontece quando o horário chega,
  sem depender de acesso ao site nem de tarefa agendada.

Depois, `Publicar material` torna o registro `Público` e o link `Baixar` passa a servir o arquivo
pela rota protegida. O arquivo nunca fica exposto como URL adivinhável.

### Corrigir ou retirar um material

- Corrigir o arquivo: envie a versão corrigida com `Enviar e selecionar` no formulário do
  material. A versão anterior continua registrada com sua procedência; a correção nunca altera um
  arquivo que alunos já baixaram.
- Retirar da entrega pública: clique em `Retirar`. O estado muda para `Retirado`, o link de
  download deixa de funcionar e o material sai da busca, mas o registro e o histórico permanecem.
  Voltar a disponibilizar exige uma nova decisão de publicação.
- Corrigir dados da oferta (horários, local, ementa publicada, link do LMS, cancelamento): use
  `Dados da oferta` na área de trabalho para horários, local e notas do período — eles valem na
  hora na página pública. Ementa publicada, link do LMS e cancelamento ficam no registro da
  oferta. Quando a mesma correção vale para outras ofertas da mesma disciplina, a propagação é
  uma operação do editor que grava uma revisão em cada oferta escolhida; nada muda em oferta
  que não foi explicitamente selecionada.
- Descrever e reordenar materiais: `Editar material` ajusta título, resumo (exibido na página
  pública), tipo, idioma e URL externa; `Reordenar materiais` numera a ordem da lista pública.

## Copiar para o próximo período

A cópia cria uma oferta em rascunho no período de destino, com a equipe revisada e os materiais
selecionados. Um editor ainda precisa publicá-la.

1. Na área da oferta de origem, abra `Copiar para o próximo período`.
2. Escolha o `Período de destino` e preencha a `Turma de destino` e o `Título` da nova oferta.
3. Revise a `Equipe docente` apresentada e marque `Revisei a equipe docente para o novo período`.
   Sem essa confirmação, o envio é recusado com "Confirme a equipe docente revisada antes de
   copiar."
4. Em `Materiais liberados a levar`, marque apenas os materiais que devem continuar públicos na
   nova oferta. Versões já liberadas e verificadas são reutilizadas como estão; todo o resto chega
   como rascunho com estado de publicação, datas e revisões zerados.
5. Clique em `Criar o rascunho do próximo período`. O aviso "Rascunho do próximo período criado.
   Um editor ainda precisa publicá-lo." confirma. Repetir a mesma cópia reutiliza o rascunho
   existente em vez de duplicar a oferta.

Campos ligados ao período ou sensíveis (link do LMS, cancelamento, estado temporal) não são
copiados; a ementa publicada, os horários e o local vão para o rascunho.

## Permissões de co-docência

A equipe docente de uma oferta é definida por um editor, com papéis `lead`, `co-teacher` e
`assistant`. Ser nomeado na equipe não é o que libera o acesso ao painel: o acesso vem da
permissão de escopo gravada na sua conta, que indica a oferta, o papel, quem concedeu, a validade
e uma eventual revogação.

- A permissão é reavaliada a cada acesso. Se ela expirar ou for revogada, a área da oferta nega na
  hora com "Sua permissão nesta oferta expirou." ou "Sua permissão nesta oferta foi revogada."
- Ninguém concede permissão a si mesmo, e o papel da permissão é sempre igual ao papel da conta.
- Pedidos de concessão, renovação ou revogação vão para um administrador ou para o editor de seção
  da coleção `teaching`. O docente não gerencia permissões pelo painel.

## Atividade recente e notificações por e-mail

A página inicial do painel fecha com a seção `Atividade recente`: uma lista dos seus últimos
eventos, no máximo oito, do mais novo ao mais antigo. Entram aí os seus envios para revisão, as
decisões do editor sobre os seus registros, as permissões concedidas ou revogadas na sua conta e
as unidades e materiais que você criou — cada item com um rótulo em linguagem corrente e a data.
Enquanto não houver nada seu registrado, a seção mostra o aviso "Seus envios, resultados de
revisão e mudanças de acesso aparecem aqui."

Duas decisões do sistema também chegam por e-mail, no idioma da sua conta:

- revisão de notícia — aprovada ("Notícia publicada: …") ou devolvida com a nota do editor
  ("Notícia devolvida pela revisão: …");
- permissão concedida — quando um administrador ou editor registra um escopo novo na sua conta
  ("Acesso liberado: …"), com o escopo, o papel e a validade.

As mensagens são simples e vão só para o endereço da sua conta: nenhum e-mail carrega dado de
outra pessoa. Se uma decisão aconteceu e o e-mail não chegou, verifique a caixa de spam e avise a
equipe técnica.

## Acessibilidade na autoria

As mesmas regras do [checklist de acessibilidade](accessibility-authoring-checklist.md) valem no
painel. Em resumo, antes de enviar:

- hierarquia de títulos sequencial, sem pular nível e sem usar título para aumentar texto;
- texto de link que descreve o destino fora de contexto, avisando quando o link baixa um arquivo;
- nada que dependa só de cor, forma ou posição;
- imagem significativa com texto alternativo próprio para aquele uso, ou marcada como decorativa;
- informação essencial sempre em HTML acessível; um PDF nunca é o único caminho;
- idioma do material declarado corretamente, sem misturar línguas no mesmo campo.

A revisão de acessibilidade do material é feita por um editor; caprichar na autoria é o que faz
essa revisão aprovar rápido.

## O que ainda não existe

Os responsáveis nomeados por papel, os contatos públicos (incluindo o canal de correções e o
contato de acessibilidade) e as aprovações de backup e restauração continuam **não resolvidos**:
são bloqueios de lançamento registrados em
`tests/fixtures/governance/role-collection-matrix.json`, e nenhum documento os apresenta como
preenchidos. Até que a instituição forneça esses nomes e canais, não existe rota pública de
contato para divulgar, e nenhum endereço pessoal deve ser publicado como se fosse institucional.

## Mensagens que você pode ver

| Mensagem | Significado |
| --- | --- |
| "Salvo. O registro continua rascunho até ser publicado." | O rascunho foi gravado; nada ficou público. |
| "Publicado. O link público está ativo." | O registro está público. |
| "Esta oferta está fora do seu escopo atribuído." | A oferta não tem permissão na sua conta. |
| "Sua conta não pode executar esta ação." | O seu papel não cobre aquela ação. |
| "O formulário expirou. Envie novamente." | Reenvie; os valores voltam preenchidos. |
| "Este campo não é editável pelo painel." | O campo pertence ao editor ou ao sistema. |
| "Uma rejeição precisa de uma nota explicando a correção necessária." | Revisão: rejeitar exige nota. |
| "Este período e turma já existem para a disciplina." | Escolha outra turma de destino. |
| "O período de destino pertence a outro calendário." | A cópia — e a primeira oferta — só valem dentro do mesmo calendário, e o período precisa declarar um. |
| "Pré-visualização gerada — confirme para enviar para revisão." | Confira o cartão e confirme; nada foi enviado ainda. |
| "Pré-visualização descartada. Nada foi enviado." | A prévia foi descartada; o formulário continua disponível. |
| "A pré-visualização expirou. Envie o formulário novamente." | Preencha e gere a pré-visualização de novo. |
| "Tradução em inglês registrada e enviada para revisão." | A variante EN foi criada, associada e revisada. |
| "O arquivo "X" não é um tipo aceito. Tipos permitidos: JPG, PNG, WebP." | A imagem destacada foi recusada; envie JPG, PNG ou WebP. |
| "O arquivo "X" excede o limite de 15 MB." | A imagem destacada passou do tamanho máximo. |
| "A imagem destacada precisa da descrição." | Imagem sem descrição não pode ser enviada. |
