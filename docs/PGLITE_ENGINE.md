# Engine PostgreSQL / PGlite

A primeira engine concreta do Motor Genérico de Laboratórios usa PGlite para executar PostgreSQL real no navegador do estudante.

## Identificador

`postgresql_pglite`

## Responsabilidades

- PGlite executa o SQL no navegador;
- IndexedDB preserva o banco local entre acessos;
- o JavaScript coleta resultado e snapshot do schema;
- o backend PHP mantém o validador/gabarito fora do navegador;
- `PgliteEngine` compara a evidência recebida com `validator_config`;
- o núcleo de laboratórios registra tentativas, dicas, XP e progresso.

## Validadores iniciais

### statement_success

Confirma que uma instrução foi executada e, opcionalmente, que o comando retornado é o esperado.

Exemplo de `validator_config`:

```json
{
  "command": "INSERT"
}
```

### query_result

Valida colunas, quantidade de linhas e/ou linhas esperadas.

```json
{
  "columns": ["id", "nome"],
  "row_count": 2,
  "rows": [
    {"id": 1, "nome": "Ana"},
    {"id": 2, "nome": "Bruno"}
  ],
  "ordered": true
}
```

### schema_table

Valida a existência de uma tabela e as colunas obrigatórias.

```json
{
  "table": "clientes",
  "columns": [
    {"name": "id", "type": "integer", "nullable": false},
    {"name": "nome", "type": "text", "nullable": false}
  ],
  "allow_extra_columns": true
}
```

## Bootstrap e estado

`bootstrap_config.sql` pode preparar dados necessários para uma atividade. O runtime usa `state_version` para não reaplicar o bootstrap da mesma versão.

O banco é salvo em IndexedDB com uma chave separada por aluno + curso + engine.

## Dependência PGlite

A URL do módulo pode ser configurada por ambiente:

```env
PGLITE_MODULE_URL=https://cdn.jsdelivr.net/npm/@electric-sql/pglite@0.5.10/dist/index.js
```

A URL padrão é fixada em uma versão específica. Em uma etapa posterior, o mesmo parâmetro pode apontar para uma cópia self-hosted.

## Limitação de integridade

Nesta primeira implementação a execução é local. O backend verifica o resultado contra regras escondidas, porém um usuário tecnicamente avançado poderia falsificar a evidência enviada pelo navegador.

Isso é aceitável para exercícios formativos. Uma futura avaliação final com valor de certificação forte deve ser revalidada em sandbox PostgreSQL controlado pelo servidor.
