# Grade Mestra PostgreSQL — Do Zero ao Intermediário (V2)

## 1. Objetivo profissional

O curso deve levar uma pessoa sem experiência prévia em PostgreSQL até um nível intermediário prático, capaz de:

- compreender banco de dados relacional e a arquitetura básica do PostgreSQL;
- criar e evoluir estruturas de tabelas;
- consultar dados com segurança e clareza;
- executar CRUD completo;
- trabalhar com relacionamentos, chaves e integridade;
- construir consultas com JOINs, agregações, subconsultas, CTEs e funções de janela;
- trabalhar com tipos úteis do PostgreSQL, incluindo JSONB;
- utilizar transações;
- criar views e índices e fazer leitura inicial de planos de execução;
- entender schemas, roles, privilégios e segurança básica;
- realizar manutenção, monitoramento e backup/restauração em nível introdutório/intermediário;
- diagnosticar erros SQL e problemas comuns;
- construir um banco de dados completo para uma aplicação real.

A meta profissional é preparar o aluno para tarefas de SQL/PostgreSQL em desenvolvimento, suporte a sistemas, análise de dados operacional e funções iniciais de administração de banco. O curso não deve prometer experiência equivalente à de um DBA sênior de produção.

---

## 2. Padrão pedagógico obrigatório

### Ciclo de aprendizagem de toda aula prática

1. **Objetivo da aula**
   - o que o aluno vai aprender;
   - por que aquilo importa no trabalho.

2. **Explicação em linguagem simples**
   - conceito;
   - sintaxe;
   - leitura do comando linha por linha.

3. **Ilustração**
   - tabela visual;
   - fluxo;
   - relacionamento;
   - diagrama;
   - comparação antes/depois;
   - emoji/ícone apenas como apoio visual, sem transformar a aula em texto informal.

4. **Exemplo real**
   - sempre dentro do cenário da LD Informática, help desk, clientes, produtos, estoque, ordens de serviço ou vendas.

5. **Prática guiada**
   - comando correto visível;
   - editor começa vazio;
   - aluno precisa digitar manualmente;
   - validação automática.

6. **Feedback após acerto**
   - “Parabéns, você acertou”;
   - o que o comando fez;
   - como interpretar o resultado;
   - onde um DBA/desenvolvedor/analista usa aquilo no trabalho;
   - erro comum e cuidado profissional.

7. **Desafio sem modelo**
   - problema semelhante;
   - sem código pronto;
   - dados/nomes ligeiramente diferentes.

8. **Reforço sem modelo**
   - novo cenário;
   - exige adaptação do padrão aprendido;
   - somente após concluir o reforço a próxima aula é liberada.

9. **Resumo visual**
   - 3 a 5 pontos essenciais.

10. **Checklist**
    - “sei explicar”;
    - “sei escrever”;
    - “sei adaptar”;
    - “sei dizer quando usar”.

### Regra de progressão

**Prática guiada → desafio → reforço → próxima aula.**

Uma aula não deve ser marcada como concluída enquanto houver atividade obrigatória pendente.

Para temas administrativos que o PGlite não reproduz com fidelidade (ex.: pg_dump, restore, autenticação real, criação de instâncias), o mesmo ciclo deve ser mantido usando demonstração, cenário profissional, interpretação de comandos e exercícios de decisão; a execução real deve ser reservada ao ambiente PostgreSQL/pgAdmin/psql apropriado.

---

# 3. Grade definitiva

## Módulo 0 — Primeiros Passos com PostgreSQL
**Resultado do módulo:** o aluno entende o que é banco de dados, reconhece tabelas/linhas/colunas, sabe o papel do PostgreSQL e consegue executar e interpretar comandos SQL básicos.

### Fase 0.1 — Fundamentos de Banco de Dados e PostgreSQL

#### 0.1 Banco de dados e PostgreSQL: o que são
**Objetivo:** diferenciar dado, banco de dados, SGBD, PostgreSQL e SQL.

**Visual:** fluxo:
Usuário → Sistema → PostgreSQL → Tabelas → Dados.

**Prática:** SELECT de valor fixo para validar que o ambiente está funcionando.

**Aplicação profissional:** conferência rápida de conexão e execução de SQL.

#### 0.2 Tabelas, linhas e colunas
**Objetivo:** visualizar uma tabela como estrutura de registros e atributos.

**Visual:** tabela de clientes colorindo coluna, linha e célula.

**Prática:** consultar uma tabela pronta.

#### 0.3 SQL, pgAdmin, psql e laboratório
**Objetivo:** entender que SQL é a linguagem; pgAdmin/psql/laboratório são interfaces diferentes.

**Visual:** um mesmo comando indo para PostgreSQL por três caminhos.

**Aplicação profissional:** escolher a ferramenta conforme suporte, administração ou desenvolvimento.

### Fase 0.2 — Primeiro Contato com SQL

#### 0.4 Seu primeiro SELECT
**Objetivo:** entender SELECT e resultado de consulta.

#### 0.5 Consultando uma tabela pronta
**Objetivo:** introduzir SELECT ... FROM.

#### 0.6 Como ler erros e aprender com eles
**Objetivo:** interpretar mensagem, posição e causa provável de erro SQL.

**Visual:** mensagem de erro anotada com “tipo”, “local” e “correção”.

---

## Módulo 1 — Consultando Dados com SELECT
**Resultado do módulo:** o aluno localiza, filtra, organiza e apresenta dados sem alterar o banco.

### Fase 1.1 — SELECT e projeção

#### 1.1 Selecionando colunas com SELECT
- SELECT coluna;
- SELECT coluna1, coluna2;
- diferença entre selecionar colunas e SELECT *;
- boa prática: evitar SELECT * em consultas de produção quando não é necessário.

**Visual:** tabela completa → seleção de duas colunas → resultado menor.

#### 1.2 Aliases com AS
- alias de coluna;
- nomes legíveis para relatórios;
- aliases de tabela como preparação para JOINs.

#### 1.3 DISTINCT
- remoção de duplicidades;
- diferença entre dados repetidos e resultado distinto.

### Fase 1.2 — Filtros e ordenação

#### 1.4 WHERE e operadores de comparação
- =, <>, >, <, >=, <=.

#### 1.5 AND, OR e NOT
- combinação de regras;
- precedência;
- importância de parênteses.

#### 1.6 LIKE, ILIKE, IN e BETWEEN
- buscas textuais;
- lista de valores;
- intervalo inclusivo.

#### 1.7 NULL, IS NULL e IS NOT NULL
- diferença entre NULL, zero e texto vazio;
- por que “= NULL” está errado.

#### 1.8 ORDER BY, LIMIT e OFFSET
- ordenação;
- ASC/DESC;
- paginação básica.

**Projeto do módulo:** consulta de catálogo da LD Informática com filtros de nome, categoria, estoque e preço.

---

## Módulo 2 — Construindo o Banco LD Informática
**Resultado do módulo:** o aluno cria estruturas consistentes e entende como decisões de modelagem afetam a aplicação.

### Fase 2.1 — Criando estruturas

#### 2.1 CREATE TABLE
- estrutura básica;
- definição de colunas.

**Visual:** desenho da tabela → comando CREATE TABLE → estrutura criada.

#### 2.2 Tipos de dados
- INTEGER/BIGINT;
- VARCHAR/TEXT;
- NUMERIC;
- BOOLEAN;
- DATE;
- TIMESTAMP;
- UUID;
- noção de JSONB.

**Prática profissional:** escolher tipo pensando no dado, não apenas “o que aceita”.

#### 2.3 Datas, horários e fuso
- DATE;
- TIMESTAMP;
- TIMESTAMPTZ;
- CURRENT_DATE/NOW;
- cuidado com fuso em aplicações.

#### 2.4 IDENTITY e geração de identificadores
- GENERATED ... AS IDENTITY;
- diferença conceitual para IDs informados manualmente.

### Fase 2.2 — Restrições e qualidade dos dados

#### 2.5 PRIMARY KEY
- identidade única;
- duplicidade;
- NULL não permitido.

#### 2.6 NOT NULL, UNIQUE e DEFAULT
- regras de consistência.

#### 2.7 CHECK
- regras de negócio no banco.

#### 2.8 ALTER TABLE
- adicionar/alterar/remover estrutura com cuidado.

**Projeto do módulo:** criar clientes, produtos e equipamentos da LD Informática com tipos e restrições coerentes.

---

## Módulo 3 — CRUD na Prática
**Resultado do módulo:** o aluno cadastra, altera e exclui dados com procedimentos seguros.

### Fase 3.1 — Inserindo dados

#### 3.1 INSERT
- uma linha;
- lista explícita de colunas.

#### 3.2 INSERT de várias linhas
- carga inicial;
- produtividade.

#### 3.3 RETURNING
- recuperar ID e dados recém-gravados.

#### 3.4 Importação e exportação de dados
- conceito de CSV;
- COPY/\copy;
- quando usar importação em lote;
- atenção a cabeçalho, delimitador e tipos.

### Fase 3.2 — Alterando e excluindo

#### 3.5 UPDATE
- alteração de registros.

#### 3.6 DELETE
- exclusão de registros.

#### 3.7 WHERE seguro
**Regra profissional obrigatória:**
antes de UPDATE/DELETE, executar SELECT com o mesmo filtro quando a operação oferecer risco.

**Visual:** SELECT de conferência → quantidade de linhas → UPDATE/DELETE.

#### 3.8 Mini CRUD completo
- cadastrar;
- consultar;
- alterar;
- excluir;
- validar resultado.

**Projeto do módulo:** CRUD completo de uma ordem de serviço.

---

## Módulo 4 — Relacionamentos e Integridade
**Resultado do módulo:** o aluno modela dados relacionados e evita registros órfãos ou inconsistentes.

### Fase 4.1 — Modelagem

#### 4.1 Por que relacionar tabelas
- evitar repetição;
- separar responsabilidades.

#### 4.2 Normalização básica
- repetição de dados;
- dependências simples;
- noções práticas de 1FN, 2FN e 3FN sem aprofundamento acadêmico excessivo.

**Visual:** tabela “ruim” repetindo cliente → modelo separado cliente/pedido.

#### 4.3 Relacionamento 1:N
- cliente possui vários pedidos;
- técnico possui várias ordens de serviço.

#### 4.4 Relacionamento N:N
- produtos e pedidos;
- tabela associativa.

### Fase 4.2 — Integridade

#### 4.5 FOREIGN KEY
- referência válida.

#### 4.6 Integridade referencial
- registro pai/filho;
- erro de referência.

#### 4.7 ON DELETE e ON UPDATE
- RESTRICT/NO ACTION;
- CASCADE;
- SET NULL;
- decisão conforme regra de negócio.

**Projeto do módulo:** relacionamento clientes → ordens de serviço → itens/serviços.

---

## Módulo 5 — JOINs e Consultas com Múltiplas Tabelas
**Resultado do módulo:** o aluno combina dados de tabelas relacionadas para produzir informações úteis à operação.

### Fase 5.1 — JOINs

#### 5.1 INNER JOIN
- apenas correspondências.

#### 5.2 LEFT JOIN
- preservar a tabela esquerda;
- encontrar registros sem correspondência.

#### 5.3 RIGHT JOIN e FULL JOIN
- entender comportamento;
- saber quando LEFT JOIN costuma ser mais legível.

**Visual obrigatório:** duas tabelas com linhas conectadas por ID; não depender somente de diagramas de conjuntos.

### Fase 5.2 — Relatórios

#### 5.4 Múltiplos JOINs
- cliente + pedido + item + produto.

#### 5.5 JOINs com filtros e ordenação
- combinar ON, WHERE e ORDER BY.

#### 5.6 UNION, INTERSECT e EXCEPT
- combinar conjuntos de resultados;
- diferença para JOIN.

#### 5.7 Relatório integrado da LD Informática
- cliente;
- equipamento;
- ordem de serviço;
- técnico;
- situação;
- valor.

**Projeto do módulo:** relatório operacional completo.

---

## Módulo 6 — Agregações e SQL Intermediário
**Resultado do módulo:** o aluno cria indicadores, consultas analíticas e SQL organizado para problemas reais.

### Fase 6.1 — Agregações

#### 6.1 COUNT, SUM, AVG, MIN e MAX
- indicadores básicos.

#### 6.2 GROUP BY
- resumo por grupo.

#### 6.3 HAVING
- filtrar grupos após agregação;
- diferença para WHERE.

### Fase 6.2 — Consultas intermediárias

#### 6.4 CASE
- classificação e regras no resultado.

#### 6.5 Subconsultas
- consulta dentro de consulta.

#### 6.6 CTE com WITH
- dividir problemas grandes em etapas legíveis.

#### 6.7 Funções de texto
- LOWER/UPPER;
- TRIM;
- CONCAT;
- LENGTH;
- REPLACE;
- noções de busca textual.

#### 6.8 Funções de data e hora
- NOW/CURRENT_DATE;
- intervalos;
- diferenças de datas;
- extração de partes da data.

#### 6.9 CAST e COALESCE
- conversão;
- tratamento de NULL.

#### 6.10 UPSERT com ON CONFLICT
- inserir ou atualizar;
- conflito de chave/unique.

#### 6.11 Funções de janela
- OVER;
- ROW_NUMBER;
- RANK;
- SUM/AVG por partição;
- diferença entre GROUP BY e window function.

**Visual:** linhas preservadas + coluna “posição/ranking”.

#### 6.12 JSONB na prática
- armazenar dado semiestruturado;
- -> e ->>;
- consulta básica;
- quando usar e quando preferir colunas relacionais.

**Projeto do módulo:** dashboard SQL de vendas e ordens de serviço.

---

## Módulo 7 — PostgreSQL Profissional: Consistência, Performance, Segurança e Operação
**Resultado do módulo:** o aluno entende práticas fundamentais usadas no ambiente profissional além do CRUD.

### Fase 7.1 — Consistência e performance

#### 7.1 Transações: BEGIN, COMMIT e ROLLBACK
**Visual:** duas operações dentro de uma caixa “transação”; sucesso → COMMIT; erro → ROLLBACK.

#### 7.2 Views
- encapsular consultas;
- reutilização;
- limites.

#### 7.3 Índices
- B-tree como padrão;
- custo de leitura x escrita;
- índice não é solução para tudo.

**Visual:** “procurar página em livro com índice”.

#### 7.4 EXPLAIN e EXPLAIN ANALYZE
- Seq Scan;
- Index Scan;
- custo estimado;
- tempo real;
- cuidado: EXPLAIN ANALYZE realmente executa a consulta.

#### 7.5 VACUUM e ANALYZE
- MVCC em nível conceitual;
- estatísticas;
- autovacuum;
- por que manutenção importa.

#### 7.6 Monitoramento básico
- pg_stat_activity;
- sessões;
- consultas ativas;
- noções de bloqueio;
- identificar atividade anormal sem ensinar intervenção destrutiva.

### Fase 7.2 — Organização, acesso e operação

#### 7.7 Schemas
- namespace;
- organização;
- search_path em nível introdutório.

#### 7.8 Roles e usuários
- role;
- login;
- princípio do menor privilégio.

#### 7.9 GRANT e REVOKE
- SELECT/INSERT/UPDATE/DELETE;
- privilégios por tabela/schema.

#### 7.10 Sequences e revisão de IDENTITY
- sequência;
- nextval/currval conceitualmente;
- preferência moderna por IDENTITY quando adequada.

#### 7.11 Backup e restauração
- backup lógico;
- pg_dump;
- pg_restore;
- formato plain/custom;
- restaurar em ambiente seguro;
- nunca considerar backup válido sem teste de restauração.

**Projeto do módulo:** checklist operacional de um banco antes de colocar uma aplicação em uso.

---

## Módulo 8 — Projeto Final: Banco da LD Informática
**Resultado do módulo:** o aluno integra modelagem, SQL, segurança e operação em um projeto semelhante a uma demanda de trabalho.

### Fase 8.1 — Construção

#### 8.1 Planejamento do schema final
- requisitos;
- entidades;
- relações;
- regras.

#### 8.2 Criação das tabelas principais
- clientes;
- técnicos;
- equipamentos;
- ordens_servico;
- produtos/serviços;
- itens.

#### 8.3 Relacionamentos e integridade
- PK;
- FK;
- UNIQUE;
- CHECK;
- NOT NULL.

#### 8.4 Carga de dados de teste
- INSERT;
- carga em lote;
- dados coerentes.

### Fase 8.2 — Desafio integrador

#### 8.5 Relatórios operacionais
- OS abertas;
- OS por técnico;
- clientes/equipamentos;
- faturamento básico.

#### 8.6 Consultas analíticas
- agrupamentos;
- indicadores;
- ranking;
- função de janela.

#### 8.7 Debug SQL
- consultas com erro;
- erros de JOIN;
- WHERE incorreto;
- NULL;
- tipos incompatíveis.

#### 8.8 Desafio final
O aluno recebe demandas sem solução pronta e precisa:
- interpretar;
- consultar;
- corrigir;
- atualizar com segurança;
- produzir relatório;
- explicar a decisão.

#### 8.9 Revisão e próximos passos
- PostgreSQL intermediário concluído;
- trilhas possíveis: DBA, backend, dados/BI, DevOps;
- o que estudar depois: PL/pgSQL, triggers, replicação, alta disponibilidade, tuning avançado.

---

# 4. Quantidade planejada

A grade V2 passa de **68 para 76 aulas**, mantendo os 9 módulos atuais.

Novas aulas propostas:

- Datas, horários e fuso;
- Importação/exportação com COPY/CSV;
- Normalização básica;
- UNION/INTERSECT/EXCEPT;
- Funções de janela;
- JSONB;
- VACUUM/ANALYZE;
- Monitoramento básico com pg_stat_activity.

Algumas aulas atuais terão conteúdo ampliado sem aumentar o número total, especialmente:

- Tipos de dados;
- EXPLAIN → EXPLAIN + EXPLAIN ANALYZE;
- Backup e restauração;
- Índices;
- Roles/GRANT/REVOKE.

---

# 5. Padrão visual por tipo de conteúdo

## Consulta
Visual: tabela de entrada → SQL → tabela de saída.

## Filtro
Visual: funil eliminando linhas.

## JOIN
Visual: duas tabelas com linhas ligadas por chaves.

## Relacionamento
Visual: mini DER em CSS/SVG.

## CRUD
Visual: Antes → comando → Depois.

## Agregação
Visual: registros → grupos → indicador.

## Transação
Visual: BEGIN → operações → COMMIT/ROLLBACK.

## Índice
Visual: livro sem índice versus livro com índice.

## EXPLAIN
Visual: plano em árvore simplificado.

## Segurança
Visual: usuário → role → privilégio → objeto.

## Backup
Visual: banco → arquivo de backup → banco restaurado.

Usar SVG/CSS simples e responsivo sempre que possível para não depender de imagens externas.

---

# 6. Padrão das atividades

Para cada aula SQL executável:

### Atividade A — Prática guiada
- resposta visível;
- aluno digita;
- validação;
- feedback explicativo.

### Atividade B — Desafio
- mesma competência;
- outro cenário;
- nenhuma resposta visível;
- feedback explicativo.

### Atividade C — Reforço
- mesma competência;
- pequena variação;
- nenhuma resposta visível;
- só depois libera a próxima aula.

Para conteúdo administrativo:
- demonstração guiada;
- cenário de decisão;
- análise de saída/comando;
- reforço prático em ambiente real quando houver suporte.

---

# 7. Avaliação por módulo

Ao final de cada módulo:

- 5 a 10 questões conceituais curtas;
- 2 desafios SQL cumulativos;
- 1 cenário profissional;
- nota recomendada mínima: 70%;
- erros devem gerar feedback explicativo e indicar a aula a revisar.

O aluno deve conseguir resolver o cenário sem copiar código para considerar a competência consolidada.

---

# 8. Critérios para conclusão profissional

Antes do certificado, o aluno precisa demonstrar que consegue:

- criar uma estrutura relacional coerente;
- consultar sem depender de código pronto;
- trabalhar com filtros e JOINs;
- alterar dados com WHERE seguro;
- compreender constraints e foreign keys;
- criar indicadores com GROUP BY e window functions;
- usar transações;
- explicar por que/onde usar índice;
- ler um EXPLAIN básico;
- reconhecer roles e privilégios;
- explicar backup/restauração;
- diagnosticar SQL incorreto;
- finalizar o projeto LD Informática.

O certificado deve indicar “PostgreSQL — Do Zero ao Intermediário”, sem usar título profissional de DBA como equivalência automática de experiência de mercado.
