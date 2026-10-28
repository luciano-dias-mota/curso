# Sindicância PMMT — revisão pedagógica e correções de referências (V1)

## Objetivo

Aprofundar a fase **2. Sindicância PMMT** do **Módulo 4** do Curso Preparatório PMMT, preservando a progressão existente, com distinção explícita entre:

- **Manual original de 2009** (Portaria nº 218/GCG/PMMT/09, que o aprovou);
- **Portaria nº 160/GCG/PMMT/09**, sobre sindicância PMMT;
- **Portaria nº 01/QCG/CORREGPM, de 22 de janeiro de 2018**, que modificou prazos.

**Cautela:** em outubro de 2026 foi possível cotejar a Portaria de 2018 em reproduções públicas, mas não se confirmou um boletim oficial consolidado de vigência atual. Não se deve apresentar automaticamente os prazos originais de 2009 como atuais, nem afirmar que não existiram alterações depois de 2018. Qualquer questão sobre validade vigente requer conferência dos atos oficiais e do edital.

## Escopo versionado

- 5 aulas opcionais; **28 blocos**, com leitura completa necessária para concluir a aula, mas sem impedir o avanço nas fases.
- 25 questões inéditas A–E; 1 correta; dificuldade medium/hard; todas com justificativa contextualizada na fonte.
- 8 correções no banco complementar: índices **13, 14, 15, 16, 17, 31, 32, 37** do módulo "Corregedoria" no arquivo `database/questoes_simulados_complementares.json`. Essas correções são idempotentes no banco quando suas versões originais estão presentes.
- Não se atribui nenhum exercício inédito a uma prova real da UFMT.

## Correções específicas

| Índice | Ajuste |
|---|---|
| 13 | Autuação de 48h atribuída expressamente ao Manual **original de 2009**, art. 4º, I |
| 14 | Defesa prévia de 72h atribuída ao **Manual de Sindicância**, art. 15, §1º, não ao IPM |
| 15 | Alegações finais de 5 dias corridos atribuídas ao **Manual de Sindicância**, arts. 6º, §2º, e 17, não ao IPM |
| 16 | Prazo de 20 dias: explícito como **histórico** da sindicância **demissória**, art. 18 original de 2009 |
| 17 | Prorrogação de 10 dias: explícita como redação **histórica** do art. 18, parágrafo único |
| 31, 32, 37 | Proposições integradoras datadas para não apresentarem prazos históricos como atuais |

Importante: **o gabarito não foi invertido nas questões preexistentes**. Para as três correções de alternativas certas, somente a redação da alternativa correta foi esclarecida, mantendo o mesmo índice e o campo de correção. O script compara literalmente o registro anterior antes de qualquer UPDATE.

## Fontes de revisão

- Documento enviado: `09_MANUAL DE SINDICANCIA.docx` — PMMT, Corregedoria-Geral, 2009, 32 páginas originais.
- [Portaria nº 218/GCG/PMMT/09 de 16/10/2009](https://www.scribd.com/document/607010256/Portaria-218-Publicacao-Manual-Sindicancia).
- [Reprodução da Portaria nº 01/QCG/CORREGPM/2018](https://www.scribd.com/document/1012895142/2-11-PORTARIA01-QCG-corrEGPM-de22-01-18-Altera-Prazos-Do-Manual-de-Sindicancia).
- [Reprodução do Manual de Sindicância atualizado em janeiro de 2018](https://pt.scribd.com/document/607010042/MANUAL-DE-SINDICANCIA-atualizado-jan-18).

Na Portaria de 2018, conforme reproduções: investigatória/acusatória **40 dias + 20 de prorrogação**; demissória **60 dias + 30 de prorrogação**; pedido no mínimo um dia antes do vencimento; autuação **até 02 dias**. Os arts. 1º e 2º também disciplinam dilação/sobrestamento. Diferentemente, o texto original de 2009 traz **48 horas** para autuação e art. 18, na seção demissória, **20 + até 10 dias** para conclusão/remessa.

No art. 14, parágrafo único, o Manual de 2009 possui **quatro impedimentos (a–d)**; não omitir a alínea **d** sobre participação em procedimento que serviu de base à acusação. O Manual informa até **três testemunhas de defesa e mais três referidas/informantes**. A orientação de inquirição estabelece **07h–18h** (salvo urgência registrada), máximo de **quatro horas consecutivas** e descanso de **meia hora facultado** para continuação.

## Instruções de homologação

O código foi submetido como alteração do GitHub e **NÃO FOI** executado no banco da AlwaysData.

1. Faça backup. Verifique a versão do projeto e a presença da fase `2-sindicancia-pmmt` do PMMT.
2. **Importante:** aplique este complemento **antes** de reexecutar o importador geral de simulados `importar_banco_simulados.php`. Assim os registros antigos podem ser atualizados pelo mapa de correção antes que os enunciados novos sejam vistos como itens inéditos.
3. Valide a sintaxe do PHP e execute dry-run:

```bash
php -l database/aplicar_sindicancia_ufmt_v1.php
php database/aplicar_sindicancia_ufmt_v1.php
```

4. Em HOMOLOGAÇÃO, execute:

```bash
php database/aplicar_sindicancia_ufmt_v1.php --apply
php database/aplicar_sindicancia_ufmt_v1.php --audit
```

5. Verifique as cinco aulas na fase 2 do Módulo 4; percorra blocos, conclua a aula opcional, confirme o exercício e a navegação sem travar aulas obrigatórias.
6. Confira no gerador de exercícios a seleção por **Módulo 4 / fase 2**; não foram feitas mudanças nos quizzes/simulados pré-configurados. Para garantir visibilidade em simulados específicos, será necessário verificar os filtros/escopos deles.
7. **Somente depois de homologar e fazer backup de produção**:

```bash
php database/aplicar_sindicancia_ufmt_v1.php
php database/aplicar_sindicancia_ufmt_v1.php --apply --force-production
php database/aplicar_sindicancia_ufmt_v1.php --audit
```

## Comportamento do script

- CLI apenas; `--apply` transacional; bloqueia produção sem confirmação explícita.
- Isola curso `experience_key=pmmt` (quando houver a coluna) e fase da sindicância.
- Usa `question_import_keys` para evitar duplicação; nunca substitui gabaritos preexistentes em conflito.
- Compara os enunciados, explicações e alternativas anteriores aos updates legados; divergência é erro, não sobrescrita silenciosa.
- Caso um dos oito itens legados não exista no banco, informa ausência e não o recria.
- Aulas novas: `lessons.is_required=0`. Blocos novos: `lesson_blocks.is_required=1`, exigindo leitura da aula sem torná-la requisito da fase.
- Cria somente registros **novos** de disponibilidade para alunos ativos que já alcançaram a fase; não altera conclusão, notas ou progresso anterior.

## Limites de validação

Sem conexão SSH/banco real, não foi possível executar `--apply`, verificar dependências locais, validar a vigência oficial consolidada nem testar a UI. O PR mantém toda a alteração isolada de produção até essas verificações.
