# CPM / UFMT — reforço de literalidade (2026-10-27)

Complemento **aditivo** ao Módulo 3 do curso PMMT. Foco principal: **art. 9º** e, em seguida, **arts. 195, 187 e 149** do Decreto-Lei nº 1.001/1969 (redação compilada). Gabaritos cobram o **texto expresso da lei**; não exigem interpretação jurisprudencial controvertida.

## Escopo

| Artigo | Aula opcional | Blocos | Questões A–E |
| --- | --- | ---: | ---: |
| 9º (+ arts. 12/13 como contraste) | Reforço art. 9º | 10 | 10 |
| 195 — abandono de posto | Reforço art. 195 | 5 | 5 |
| 187 — deserção | Reforço art. 187 | 5 | 5 |
| 149 — motim/revolta | Reforço art. 149 | 6 | 4 |
| **Total** | **4** | **26** | **24** |

15 questões **medium**, 9 **hard**, sempre 5 alternativas e gabarito único. Questões **inéditas de treino**, sem atribuição indevida à banca UFMT.

## Fontes jurídicas verificadas

- [CPM — texto compilado oficial, art. 9º e arts. 149, 187, 195](https://www.planalto.gov.br/ccivil_03/decreto-lei/del1001compilado.htm).
- [Constituição — art. 125, §4º](https://www.planalto.gov.br/ccivil_03/constituicao/constituicao.htm).
- Precedente jurisprudencial utilizado somente em **nota complementar** (não define gabaritos literais): STJ, [Informativo 667/2020, CC 170.201/PI](https://processo.stj.jus.br/jurisprudencia/externo/informativo/?acao=pesquisarumaedicao&livre=0667.cod.). **Não** atribuir esse entendimento ao Informativo 886/2026, que trata de outro assunto.

**Regras de segurança pedagógica:** (a) art. 187 exige **mais de** oito dias, não oito dias exatos; (b) art. 195 exige ausência **sem ordem superior** e abandono **antes do término**; (c) art. 149 pressupõe conduta do caput, e a revolta exige agentes armados, sem necessidade textual de disparos; (d) a exceção do art. 9º, §2º, destina-se às **Forças Armadas**, não à PMMT; (e) conflitos privados entre militares da ativa fora de serviço são reservados à nota sobre jurisprudência, sem resposta automática nas questões de literalidade.

## Instalação segura

O **repositório não tem acesso ao banco de produção**. Os scripts apenas foram versionados nesta branch, não executados. Antes de aplicar:

1. Faça backup do banco de dados e use uma cópia de **homologação**.
2. Baixe/atualize a branch após revisão e execute `composer install`, se necessário.
3. Confirme a existência de `courses.experience_key` (na plataforma multi-curso), `lessons.is_required` e `question_import_keys`. O script interrompe com erro se faltar estrutura essencial.
4. Faça dry-run, depois aplique na homologação e faça auditoria:

```bash
php database/aplicar_cpm_ufmt_literalidade_v1.php
php database/aplicar_cpm_ufmt_literalidade_v1.php --apply
php database/aplicar_cpm_ufmt_literalidade_v1.php --audit
```

Na produção, **somente após backup e validação na homologação**:

```bash
php database/aplicar_cpm_ufmt_literalidade_v1.php
php database/aplicar_cpm_ufmt_literalidade_v1.php --apply --force-production
php database/aplicar_cpm_ufmt_literalidade_v1.php --audit
```

## Comportamento e garantias

- Encontra **somente** o módulo PMMT publicado, conferindo `experience_key=pmmt` quando disponível.
- Cria quatro aulas **`is_required=0`** dentro das fases CPM existentes, com blocos **`is_required=0`**.
- Não atualiza aulas existentes; não altera quizzes/checkpoints, usuários, progresso, pontuação, matrícula ou outras experiências.
- Importa as 24 questões vinculadas ao curso, módulo, fase e aula corretos, com `source_key` idempotente em `question_import_keys`.
- Em reexecução, recusa dados existentes que divergirem da fonte, em vez de sobrescrevê-los silenciosamente.
- `--audit` exige 4 aulas, 26 blocos, 24 chaves e verifica enunciados, alternativas, gabaritos e vinculação.
- Não altera o gerador nem garante que um simulado pré-configurado buscará as novas questões; isso exige conferência de filtros e testes funcionais na homologação.

## Conferência pedagógica na interface

Abrir Módulo 3 → fase **Código Penal Militar — recorte do edital** (reforço art. 9º) e fase **Crimes militares selecionados** (outros três reforços). Verificar apresentação dos 26 blocos, progressão independente e geração de exercícios por módulo/fase/aula. Não executar as antigas rotinas que reorganizam fases após este complemento sem verificar o impacto em `is_required` e progresso.
