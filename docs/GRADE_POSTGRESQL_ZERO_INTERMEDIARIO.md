# PostgreSQL — Do Zero ao Intermediário

## Metodologia

A trilha foi organizada para o estudante aprender executando SQL desde o início. O curso usa o projeto cumulativo **LD Informática** e a engine `postgresql_pglite`.

Fluxo previsto por aula:

```
teoria curta
→ exemplo
→ aluno digita
→ executa PostgreSQL
→ recebe resultado/erro
→ resolve desafio
→ validação
→ próxima aula
```

O curso nasce em rascunho. A grade-base cria somente módulos, fases e aulas-shell; blocos teóricos e atividades práticas serão adicionados em versões posteriores.

## Grade

### Módulo 0 — Primeiros Passos no PostgreSQL
**Fundamentos de Banco de Dados e PostgreSQL**
- Banco de dados e PostgreSQL: o que são
- Tabelas, linhas e colunas
- SQL, pgAdmin, psql e o laboratório do curso

**Primeiro Contato com SQL**
- Seu primeiro SELECT
- Consultando uma tabela pronta
- Como ler erros e aprender com eles

### Módulo 1 — Consultando Dados com SELECT
**SELECT e Projeção de Dados**
- Selecionando colunas com SELECT
- Aliases com AS
- Removendo duplicidades com DISTINCT

**Filtros, Condições e Ordenação**
- WHERE e operadores de comparação
- AND, OR e NOT
- LIKE, IN e BETWEEN
- NULL, IS NULL e IS NOT NULL
- ORDER BY, LIMIT e paginação básica

### Módulo 2 — Construindo o Banco LD Informática
**Criando Estruturas**
- CREATE TABLE
- Tipos de dados
- Identity

**Restrições e Qualidade dos Dados**
- PRIMARY KEY
- NOT NULL, UNIQUE e DEFAULT
- CHECK
- ALTER TABLE

### Módulo 3 — CRUD na Prática
**Inserindo Dados**
- INSERT
- Múltiplas linhas
- RETURNING

**Alterando e Excluindo com Segurança**
- UPDATE
- DELETE
- WHERE seguro
- Desafio CRUD completo

### Módulo 4 — Relacionamentos e Integridade
**Modelando Relacionamentos**
- Conceito de relacionamentos
- 1:N
- N:N e tabela associativa

**Foreign Keys e Integridade Referencial**
- FOREIGN KEY
- Integridade referencial
- ON DELETE e ON UPDATE

### Módulo 5 — JOINs e Consultas com Várias Tabelas
**JOINs Fundamentais**
- INNER JOIN
- LEFT JOIN
- RIGHT JOIN e FULL JOIN

**Relatórios com Múltiplas Tabelas**
- Vários JOINs
- JOINs com filtros e ordenação
- Relatório integrado da LD Informática

### Módulo 6 — Agregações e SQL Intermediário
**Agregações e Grupos**
- COUNT, SUM, AVG, MIN e MAX
- GROUP BY
- HAVING

**Consultas Intermediárias**
- CASE
- Subconsultas
- CTEs com WITH
- Funções de texto
- Funções de data e hora
- CAST e COALESCE
- UPSERT com ON CONFLICT

### Módulo 7 — Recursos Profissionais do PostgreSQL
**Consistência e Performance**
- Transações
- Views
- Índices
- EXPLAIN

**Organização, Acesso e Operação**
- Schemas
- Roles e usuários
- GRANT e REVOKE
- Sequences e identity
- Fundamentos de backup e restauração

### Módulo 8 — Projeto Final: Banco LD Informática
**Construindo o Banco Final**
- Planejamento do schema
- Tabelas principais
- Relacionamentos e integridade
- Carga de dados

**Desafio Integrador**
- Relatórios operacionais
- Consultas analíticas
- Debug de SQL
- Desafio final
- Revisão e próximos passos

## Escopo

A grade encerra no nível intermediário. Administração avançada, tuning profundo, replicação, WAL, alta disponibilidade e operação de servidor devem compor cursos posteriores, em vez de inflar esta primeira trilha.
