# Módulo 6 — Revisão jurídica de CF/88 e CE-MT + reforço UFMT (V1)

## 1. Correções jurídicas — antes do reforço

As correções estão versionadas separadamente em `database/sources/m6_cf_cemt_errata_juridica_v1.json` (8 itens; fontes por ocorrência). A nova aula de direitos fundamentais e as demais aulas tratam a redação corrigida.

**Cuidado com o alcance:** nesta branch **não foi alterado o arquivo original gigante** `database/mega_apostila_content.json`, que não pôde ser revisado integralmente na conexão usada, nem foram editadas aulas antigas no banco. Portanto, a errata é fonte de referência e material incorporado às **aulas opcionais novas**; ela não substitui qualquer enunciado incorreto já existente em produção. É indispensável auditar conteúdo original e questões antigas separadamente antes de afirmar saneamento completo.

| Questão | Texto correto para ensino |
|---|---|
| CF 5º, XI | Constituição exige determinação judicial **durante o dia**, sem escrever 06h–18h. STJ, RHC 196.496/RN, Terceira Seção, admite mandados de busca domiciliar a partir de **5h até 21h**, com base na Lei 13.869/2019. Separar texto constitucional, lei e jurisprudência |
| CF 5º, LVIII e LXIV | LVIII = **identificação criminal** salvo hipóteses legais. LXIV = nomes dos responsáveis pela prisão/interrogatório |
| CF 144, §5º | Polícia ostensiva e ordem pública cabem às PMs; não afirmar exclusividade universal: STF Tema 656 admite segurança urbana, inclusive policiamento ostensivo/comunitário por guarda municipal, respeitados limites, sem polícia judiciária |
| CE 39 e 40 | PGE no caput: expressão declarada inconstitucional (ADI 291); iniciativa exclusiva do Governador para efetivos PM/CBM **e Polícia Penal**; art. 40 tem ressalva expressa do art. 164 |
| CE 41 | 45 dias de urgência, **não correm no recesso** e **não se aplicam a lei complementar** |
| CE 49 | TCE com 7 conselheiros; 3 escolhas do Executivo (uma livre, duas de listas alternadas) + 4 pela Assembleia; idade **maior que 30 e menor que 70** |
| CE 66, XII / 80 / 81 | Comando supremo do Governador está no art. 66, XII. Art. 80 regula direção/escolha do comandante; art. 81 competências da PM |
| CE 101 x CF 125, §§4º–5º | Leitura em conjunto. Juiz de direito julga singularmente crimes militares contra civis e ações contra atos disciplinares; Conselho, sob juiz de direito, julga outros crimes militares; ressalva de Júri quando constitucionalmente aplicável |

**Vigência:** compilado oficial CE-MT consultado atualizado até EC 119/2024; EC 121/2025 e EC 122/2026 posteriores também consultadas. Conferir as alterações entre a compilação e o edital antes de afirmar texto consolidado integral.

## 2. Complementação didática

- **6 aulas opcionais**, cada uma com **6 blocos de leitura obrigatória dentro da aula**, mas sem impedir progressão das aulas obrigatórias. Total: 36 blocos.
- **56 questões inéditas, todas Medium e A–E**, um gabarito por questão, explicação e chaves exclusivas para impedir duplicação.
- Distribuição: art. 5º (10), art. 144 (10), arts. 39–41 CE (9), arts. 46–49 TCE (10), arts. 66/80–83 (8), arts. 91/96/101 em conjunto com art. 125 CF (9).
- As questões foram compostas para treino e não são anunciadas como itens oficiais de concurso UFMT.
- As aulas são inseridas nas **fases já existentes** do `modulo-6-direito-constitucional-e-legislacao-institucional-da-pmmt`. Somente o curso PMMT é selecionado, pelo `experience_key` quando essa coluna existe.
- Não há mudança nos módulos 3/4, no PostgreSQL, em quizzes existentes ou em progresso concluído.

## 3. Procedimento recomendado — inicialmente apenas consulta

O script está em `database/aplicar_m6_constitucional_ufmt_v1.php`.

**Este complemento está somente numa branch de GitHub; não foi integrado à `main` nem executado no banco AlwaysData.**

Na pasta de revisão criada pelo usuário, com dependências e configuração do projeto presentes, testar:

```bash
php -l database/aplicar_m6_constitucional_ufmt_v1.php
php database/aplicar_m6_constitucional_ufmt_v1.php
```

O segundo comando é **dry-run sem gravação**, mas realiza SELECTs no banco configurado por `.env`. Validar todos os dados e escopos antes de prosseguir. O modo `--audit` verifica a aplicação já feita e retorna código 6 se faltarem registros.

A aplicação só pode ser considerada após backup conferido, homologação e revisão do resultado do dry-run. O script exige `--apply` e, quando `APP_ENV=production`, também `--force-production`. **Não executar na produção durante esta revisão.**

## 4. Fontes legais consultadas

- [Constituição Federal compilada — Planalto](https://www4.planalto.gov.br/legislacao/portal-legis/legislacao-1/constituicao).
- [STJ — RHC 196.496/RN, notícia de 15/01/2026](https://www.stj.jus.br/sites/portalp/paginas/comunicacao/noticias/2026/15012026-para-terceira-secao--busca-domiciliar-pode-ocorrer-a-partir-das-5h--mesmo-sem-luz-solar.aspx).
- [STF — RE 608.588, Tema 656, tese](https://portal.stf.jus.br/jurisprudenciaRepercussao/verAndamentoProcesso.asp?classeProcesso=RE&incidente=3832832&numeroProcesso=608588&numeroTema=656).
- [CE-MT compilação da ALMT até EC 119/2024, via Biblioteca do Senado](https://www2.senado.leg.br/bdsf/bitstream/handle/id/70444/Constituicao_Estado_MT.pdf).
- [EC 96/2021 — Polícia Penal e comando supremo](https://iomat.mt.gov.br/legislacao/diario_oficial/detalhes/174399).
- [EC 33/2005 — POLITEC, art. 83](https://storage.al.mt.gov.br/api/v1/download/default/639107).
- [EC 107/2022 — idade para conselheiros TCE-MT](https://diariooficial.al.mt.gov.br/publicacoes/16070/).
- [EC 121/2025 — texto publicado](https://diariooficial.al.mt.gov.br/publicacoes/33520/).
- [EC 122/2026 — texto publicado](https://diariooficial.al.mt.gov.br/publicacoes/34997/).

## 5. Validações e limites

- Conferidos por releitura dos arquivos versionados: 8 correções, 6 aulas com 6 blocos cada, 56 questões (10/10/9/10/8/9), 56 chaves distintas e cada questão com 5 alternativas distintas e um gabarito.
- O script usa transação, scope PMMT, comparação de dados existentes, `source_key` e `INSERT IGNORE` apenas para liberar novas aulas opcionais a alunos que já tenham acesso à fase.
- **Não foram feitos** lint PHP na AlwaysData, dry-run real, teste de importação no banco, interface de estudante, verificação de aparecimento em simulados ou auditoria integral de questões antigas. A aprovação desses testes é pré-condição de qualquer publicação.
