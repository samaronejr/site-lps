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
  ações exclusivas do professor; os botões correspondentes nem aparecem para o delegado.

## As tarefas do painel

Toda tela do painel abre com o selo `Área do membro`, o título da tela e a linha
`Tarefas, ofertas e envios vinculados à sua conta.` — o mesmo cabeçalho das páginas públicas.
A página inicial lista apenas as tarefas que o seu escopo cobre:

| Tarefa | Quem vê | Para que serve |
| --- | --- | --- |
| `Meu perfil` | professor, delegado | Propor alterações no seu registro público de pessoa. |
| `Minhas ofertas` | professor, delegado | Abrir a área da oferta: unidades, materiais e a cópia do próximo período. |
| `Enviar notícia` | professor com escopo `news` | Rascunhar uma notícia para revisão editorial. |
| `Fila de revisão` | editor de seção, publicador | Aprovar ou rejeitar envios e propostas pendentes. |
| `Criar oferta` | editor de seção, publicador | Criar uma oferta em rascunho ligando disciplina, período e turma. |

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

Disponível apenas para professor com o escopo `news` concedido. Os envios entram em revisão como
rascunhos; um rascunho salvo nunca é público.

1. Abra `Enviar notícia` e preencha `Título`, `Resumo`, `Conteúdo` e `Data de publicação`.
2. Clique em `Enviar para revisão`. O aviso "Enviado para revisão. Um editor decidirá." confirma,
   e o item aparece em `Minhas notícias` com o estado `Em revisão`.
3. Se o editor rejeitar, o item volta para `Rascunho` com a nota da revisão visível. Abra
   `Editar e reenviar`, corrija e clique em `Reenviar para revisão`.
4. Quando aprovada, a notícia fica `Público` e o link público passa a funcionar.

## Área da oferta (`/pt-br/painel/ofertas/`)

Cada oferta atribuída abre uma área de trabalho com os links `Ver a página pública` e
`Editar o registro da oferta`, as listas `Unidades` e `Materiais`, e os formulários descritos
abaixo.

### Adicionar unidade

1. Em `Adicionar unidade`, preencha `Título`, `Âncora` (o identificador estável da unidade),
   `Posição` (número inteiro a partir de 1) e, se quiser, `Data do tópico` no formato
   `AAAA-MM-DD`.
2. Clique em `Criar a unidade em rascunho`. A unidade entra na lista como `Rascunho`.
3. Professor pode publicar a unidade com `Publicar unidade`. Unidade é conteúdo interno: ela
   organiza os materiais e não vira página pública própria.

### Adicionar material

1. Em `Adicionar material`, preencha `Título`, `Resumo`, `Tipo de material`, `Idioma do material`
   e, se couber, a unidade à qual o material pertence.
2. Anexe um arquivo ou informe uma `URL externa`, nunca os dois. O sistema recusa o envio com os
   dois preenchidos.
3. Clique em `Criar o material em rascunho`. O material entra na lista `Materiais` como
   `Rascunho`.

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
- Corrigir dados da oferta (horários, local, ementa publicada, link do LMS, cancelamento): edite o
  registro da oferta. Quando a mesma correção vale para outras ofertas da mesma disciplina, a
  propagação é uma operação do editor que grava uma revisão em cada oferta escolhida; nada muda em
  oferta que não foi explicitamente selecionada.

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
| "O período de destino pertence a outro calendário." | A cópia só vale dentro do mesmo calendário. |
