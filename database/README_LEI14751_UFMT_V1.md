# Lei Orgânica Nacional nº 14.751/2023 — atualização didática UFMT (V1)

## Objetivo e escopo

**Somente Módulo 6, fase `4-regime-dos-militares-e-seguranca-publica`, curso PMMT.** A importação não interfere em PostgreSQL, aulas originais, avaliações configuradas, rankings, notas nem progresso já concluído. O importador adiciona **9 aulas opcionais e 54 blocos**. Cada nova aula opcional exige leitura de seus blocos para conclusão própria, mas não impõe novo requisito sequencial às aulas antigas.

O conteúdo novo traz correções de precisão e complementa a lei já reproduzida no bloco 761 de produção. **O bloco 761 existente não foi sobrescrito**; eventuais inconsistências históricas nele ou em questões pré-existentes dependem de revisão pontual.

## Revisão jurídica aplicada

1. Art. 15: incluir **seis quadros**: QOEM, QOE, QOS, QORR, QP, QPRR; QOT/QPT são hipóteses facultativas conforme condições locais.
2. Art. 15, II e §2º: QOE até tenente-coronel; integrantes da instituição não têm limite etário para concurso ao QOEM após promulgação de parte vetada em 2024. Não estender automaticamente a civis externos.
3. Art. 16: distinguir **CAO capitão→major**, **CCEM major/tenente-coronel→coronel**, **CAP segundo-sargento→primeiro-sargento**.
4. Art. 13, X: tatuagem visível só é vedada quando seu conteúdo se enquadra nas hipóteses legais. Não eliminar todo candidato tatuado.
5. Art. 18: não se trata apenas de prisão; diferenciar custódia pré-trânsito (V), pós-trânsito (VI), flagrante comum (VIII), estabilidade de carreira de 3 anos, permanência mínima na unidade com ressalvas e demais garantias.
6. Arts. 19 e 22: ressalvas à atividade societária; divulgação de imagens de pessoas sob custódia sem autorização judicial vedada; em candidatura diferenciar afastamento/agregação, pagamento apenas nos prazos eleitorais (§1º), suplente (III) e recálculo de proventos (§2º, promulgado em 2024).
7. Art. 26: AGU quando atuação efetiva na mobilização gerar procedimentos; atos prioritariamente remotos; Justiça Militar **do ente de origem** em crimes militares do convocado.
8. Art. 28–29: IGPM/BM integra o Comando do Exército e é chefiada por oficial-general da ativa; plano do Comandante-Geral até **60 dias** da posse.
9. Arts. 39–40: escolaridade até **6 anos** da publicação; regra transitória de opção de quadros em **180 dias** após a redação promulgada.

## Inventário das 90 questões

| Origem | Quantidade | Como foram tratadas |
|---|---:|---|
| Anexo rotulado `UFMT - Lei 14.751/23` | 10 | Aproveitadas com as cinco alternativas. **A identificação UFMT foi fornecida pelo usuário, não autenticada em prova oficial ou caderno da banca** |
| Anexo rotulado `L.O. PM/BM - Inédita` | 6 | Aproveitadas por conter alternativas completas e gabarito juridicamente aferível (eleições; vedações; convocação; IGPM; princípios; cooperação) |
| Questões originais elaboradas nesta atualização | 74 | Alternam cenários práticos e julgamento de quatro assertivas, com cinco alternativas, um gabarito, explicação e dificuldade `medium` |
| Anexo não incorporado diretamente | 14 | 4 questões externas de IDHTEC/FGV/IDECAN sem alternativas, 6 inéditas posteriores sem opções e 4 inéditas completas separadas para revisão jurídica adicional. Servem apenas como referência de estilo |

Distribuição por aula: **10 questões em cada uma das 9 aulas**. Toda questão tem 5 alternativas A–E, 1 única marcada correta, gabarito comentado, identificação de origem no JSON e `source_key` única.

**Atribuição correta de banca:** Não usar `Questão oficial UFMT` nas telas ou relatórios sem prova externa independente. Fonte genérica no importador: “UFMT modelo; anexo do estudante (banca não autenticada) + inéditas”. A mera marcação do anexo não comprova autenticidade.

## Arquivos

- `database/sources/lei14751_ufmt_aulas_v1.json`: aulas e blocos do reforço.
- `database/sources/lei14751_ufmt_questoes_v1.json`: 90 questões estruturadas e inventário de proveniência.
- `database/aplicar_lei14751_ufmt_v1.php`: importador CLI com dry-run padrão, `--audit`, transação, verificação de duplicatas e escopo PMMT.

## Verificação sem nenhuma gravação

**Esta branch não foi integrada à `main` nem implantada na AlwaysData.** Para diagnosticar em worktree separado depois de confirmar que os arquivos da branch estão completos, executar:

```bash
php -l database/aplicar_lei14751_ufmt_v1.php
php database/aplicar_lei14751_ufmt_v1.php
```

O segundo comando acessa o banco configurado, executa SELECTs e informa pendências, **sem INSERT/UPDATE/DELETE**. O modo `--audit` verifica integridade de uma aplicação anterior.

A execução com `--apply` faz INSERTs no banco, e em `APP_ENV=production` exige adicionalmente `--force-production`. NÃO executar sem backup verificado, revisão jurídica final, `php -l`, dry-run sem conflitos e teste de interface, inclusive se a progressão de novas aulas opcionais permanece correta. O script de aplicação não foi executado nem em produção nem em homologação nesta etapa.

## Fontes institucionais e atualização

- [Lei nº 14.751/2023 — Câmara, texto atualizado](https://www2.camara.leg.br/legin/fed/lei/2023/lei-14751-12-dezembro-2023-795052-normaatualizada-pl.html)
- [Lei nº 14.751/2023 — Planalto](https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/lei/l14751.htm)
- [Promulgação dos vetos rejeitados, DOU de 13/06/2024](https://www2.camara.leg.br/legin/fed/lei/2023/lei-14751-12-dezembro-2023-795052-promulgacaodevetos-172038-pl.html)
- [Senado — registro e histórico normativo](https://legis.senado.leg.br/norma/37911403)

**Atenção ao conteúdo do art. 18, VI:** as regras pós-trânsito e a perda de posto/graduação devem ser explicadas integralmente a partir do texto legal e das condições concretas, não por equivalência simplista com o inciso V.

## Resultado das verificações de estrutura

Os três arquivos desta branch foram relidos após os commits: 9 aulas, 54 blocos, 90 questões Medium, distribuição 10 por tópico, chaves exclusivas, 5 opções distintas por questão, um único gabarito marcado. O importador conserva o bloqueio para produção, transação e validação de conflitos. O lint PHP e dry-run real no banco ainda dependem de execução na pasta de revisão pelo usuário.
