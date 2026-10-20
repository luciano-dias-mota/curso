window.CursoMILabEngines = window.CursoMILabEngines || {};

window.CursoMILabEngines.postgresql_pglite = {
    async mount({ root, manifest, submitUrl, csrfToken, showStatus }) {
        const editor = root.querySelector('[data-lab-fallback-editor]');
        const runButton = root.querySelector('[data-lab-run]');
        const resetButton = root.querySelector('[data-lab-reset]');
        const resultHost = root.querySelector('[data-lab-result]');
        const config = manifest?.engine?.config || {};
        const activity = manifest?.activity || {};

        if (!editor || !runButton || !resultHost) {
            throw new Error('A interface do laboratório SQL está incompleta.');
        }

        const moduleUrl = String(config.module_url || '');
        if (!moduleUrl) {
            throw new Error('O módulo PGlite não foi configurado.');
        }

        const storageKey = String(config.storage_key || 'curso-mi-pg');
        const stateVersion = Number(config.state_version || 0);
        const maxRows = Math.max(10, Math.min(500, Number(config.max_result_rows || 200)));
        const timeoutMs = Math.max(1000, Math.min(30000, Number(config.statement_timeout_ms || 10000)));

        editor.disabled = true;
        runButton.disabled = true;
        showStatus('Carregando o PostgreSQL no navegador...', 'warning');

        const imported = await import(moduleUrl);
        const PGlite = imported?.PGlite;

        if (typeof PGlite?.create !== 'function') {
            throw new Error('A biblioteca PGlite carregada não possui a API esperada.');
        }

        const db = await PGlite.create('idb://' + storageKey);

        await db.exec(
            'CREATE SCHEMA IF NOT EXISTS curso_mi_internal;'
            + 'CREATE TABLE IF NOT EXISTS curso_mi_internal.lab_state ('
            + 'id integer PRIMARY KEY, state_version integer NOT NULL DEFAULT 0, updated_at timestamptz NOT NULL DEFAULT now()'
            + ');'
            + 'INSERT INTO curso_mi_internal.lab_state (id, state_version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING;'
        );

        try {
            await db.exec("SET statement_timeout TO '" + timeoutMs + "ms';");
        } catch {
            // Alguns ambientes PGlite podem não aplicar este parâmetro.
        }

        const currentStateResult = await db.query(
            'SELECT state_version FROM curso_mi_internal.lab_state WHERE id = 1'
        );
        const currentState = Number(currentStateResult?.rows?.[0]?.state_version || 0);
        const bootstrapSql = String(config.bootstrap_sql || '').trim();

        if (stateVersion > currentState && bootstrapSql !== '') {
            showStatus('Preparando o ambiente desta atividade...', 'warning');
            await db.exec(bootstrapSql);
            await db.query(
                'UPDATE curso_mi_internal.lab_state SET state_version = $1, updated_at = now() WHERE id = 1',
                [stateVersion]
            );
        }

        editor.disabled = false;
        runButton.disabled = false;
        showStatus('PostgreSQL pronto. Digite seu SQL e execute.', 'success');

        const renderValue = (value) => {
            if (value === null) return 'NULL';
            if (typeof value === 'object') {
                try {
                    return JSON.stringify(value);
                } catch {
                    return String(value);
                }
            }
            return String(value);
        };

        const clearResult = () => {
            resultHost.innerHTML = '';
        };

        const renderResult = (result) => {
            clearResult();

            const wrapper = document.createElement('div');
            wrapper.className = 'lab-sql-result';

            const heading = document.createElement('div');
            heading.className = 'lab-sql-result-head';

            const command = document.createElement('strong');
            command.textContent = result?.command || 'SQL';

            const count = document.createElement('span');
            const rows = Array.isArray(result?.rows) ? result.rows : [];
            count.textContent = rows.length + ' linha(s) exibida(s)';

            heading.append(command, count);
            wrapper.appendChild(heading);

            if (rows.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'lab-sql-empty';
                empty.textContent = 'Comando executado sem conjunto de resultados.';
                wrapper.appendChild(empty);
                resultHost.appendChild(wrapper);
                return;
            }

            const columns = Array.isArray(result?.fields)
                ? result.fields.map((field) => String(field?.name || '')).filter(Boolean)
                : Object.keys(rows[0] || {});

            const scroller = document.createElement('div');
            scroller.className = 'lab-sql-table-wrap';
            const table = document.createElement('table');
            table.className = 'lab-sql-table';

            const thead = document.createElement('thead');
            const trHead = document.createElement('tr');
            columns.forEach((column) => {
                const th = document.createElement('th');
                th.textContent = column;
                trHead.appendChild(th);
            });
            thead.appendChild(trHead);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            rows.slice(0, maxRows).forEach((row) => {
                const tr = document.createElement('tr');
                columns.forEach((column) => {
                    const td = document.createElement('td');
                    td.textContent = renderValue(row?.[column]);
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            scroller.appendChild(table);
            wrapper.appendChild(scroller);

            if (rows.length > maxRows) {
                const notice = document.createElement('small');
                notice.textContent = 'Resultado limitado às primeiras ' + maxRows + ' linhas.';
                wrapper.appendChild(notice);
            }

            resultHost.appendChild(wrapper);
        };

        const schemaSnapshot = async () => {
            const response = await db.query(
                "SELECT table_name, column_name, data_type, is_nullable, ordinal_position "
                + "FROM information_schema.columns "
                + "WHERE table_schema = 'public' "
                + "ORDER BY table_name, ordinal_position"
            );

            return Array.isArray(response?.rows) ? response.rows : [];
        };

        const buildEvidence = async (results) => {
            const resultList = Array.isArray(results) ? results : [];
            const lastResult = [...resultList].reverse().find(
                (item) => Array.isArray(item?.rows) || item?.command
            ) || {};

            const rows = Array.isArray(lastResult.rows)
                ? lastResult.rows.slice(0, maxRows)
                : [];

            const columns = Array.isArray(lastResult.fields)
                ? lastResult.fields.map((field) => String(field?.name || '')).filter(Boolean)
                : (rows[0] ? Object.keys(rows[0]) : []);

            return {
                success: true,
                last_result: {
                    command: String(lastResult.command || ''),
                    row_count: Number(lastResult.rowCount ?? rows.length ?? 0),
                    affected_rows: Number(lastResult.affectedRows || 0),
                    columns,
                    rows,
                },
                schema: await schemaSnapshot(),
            };
        };

        const postAttempt = async (sql, evidence, executionMs) => {
            const response = await fetch(submitUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    sql,
                    evidence,
                    execution_ms: Math.max(0, Math.round(executionMs)),
                }),
            });

            let payload = {};
            try {
                payload = await response.json();
            } catch {
                payload = {};
            }

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'O servidor não conseguiu validar a atividade.');
            }

            return payload;
        };

        runButton.addEventListener('click', async () => {
            const sql = String(editor.value || '').trim();

            if (!sql) {
                showStatus('Digite uma instrução SQL antes de executar.', 'warning');
                editor.focus();
                return;
            }

            runButton.disabled = true;
            clearResult();
            showStatus('Executando SQL...', 'warning');
            const startedAt = performance.now();

            try {
                const results = await db.exec(sql);
                const elapsed = performance.now() - startedAt;
                const lastDisplay = [...results].reverse().find(
                    (item) => Array.isArray(item?.rows) || item?.command
                ) || {};

                renderResult(lastDisplay);

                const evidence = await buildEvidence(results);
                const validation = await postAttempt(sql, evidence, elapsed);

                showStatus(
                    validation.message || (validation.passed ? 'Atividade concluída.' : 'Revise sua solução.'),
                    validation.passed ? 'success' : 'warning'
                );

                if (validation.lesson_completed) {
                    const note = document.createElement('p');
                    note.className = 'lab-sql-completed';
                    note.textContent = '✓ Aula concluída. Você já pode seguir para a próxima etapa.';
                    resultHost.appendChild(note);
                }
            } catch (error) {
                const elapsed = performance.now() - startedAt;
                const message = error instanceof Error
                    ? error.message
                    : 'Erro ao executar a instrução SQL.';

                showStatus(message, 'error');

                try {
                    await postAttempt(
                        sql,
                        { success: false, error: message },
                        elapsed
                    );
                } catch {
                    // A tentativa local já foi exibida; falha de telemetria não
                    // deve esconder o erro SQL do estudante.
                }
            } finally {
                runButton.disabled = false;
            }
        });

        if (resetButton) {
            resetButton.textContent = 'Restaurar código inicial';
            resetButton.addEventListener('click', () => {
                editor.value = String(activity.starter_code || '');
                clearResult();
                showStatus(
                    'O código inicial foi restaurado. O banco desta trilha foi preservado.',
                    'warning'
                );
                editor.focus();
            });
        }
    },
};
