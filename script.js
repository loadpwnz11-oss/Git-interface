/**
 * GitHub Manager — клиентская логика.
 * Никаких сторонних библиотек: только fetch + DOM.
 */
(function () {
    'use strict';

    var BOOT = window.GHM_BOOT || {};
    var API = BOOT.api || 'api.php';
    var STORAGE_THEME = 'ghm_theme';
    var STORAGE_USERNAME = 'ghm_username';

    var state = {
        csrf: BOOT.csrf || '',
        demo: !!BOOT.demo,
        modes: BOOT.modes || [],
        steps: BOOT.steps || {},
        user: BOOT.user || null,
        authorized: !!BOOT.auth,
        repos: [],
        page: 1,
        hasMore: false,
        query: '',
        filter: 'all',
        sort: 'updated',
        loading: false,
        copies: [],
        branches: [],
        branchFilter: '',
        branchesState: null,
        copy: {
            job: null,
            source: null,
            running: false,
            lastStep: '',
            retryMode: false
        },
        delete: { owner: '', repo: '' }
    };

    /* ------------------------------------------------------------------ */
    /* Утилиты                                                            */
    /* ------------------------------------------------------------------ */

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function safeUrl(value) {
        var url = String(value || '');
        return /^https?:\/\//i.test(url) ? url : '';
    }

    function toast(message, type, timeout) {
        var host = $('#toasts');
        if (!host) {
            return;
        }
        var node = document.createElement('div');
        node.className = 'toast toast-' + (type || 'success');
        node.innerHTML = '<span class="toast-text">' + escapeHtml(message) + '</span>';
        if (type === 'error') {
            node.innerHTML += '<button class="toast-close" aria-label="Закрыть">&times;</button>';
        }
        host.appendChild(node);

        var remove = function () {
            node.classList.add('toast-out');
            setTimeout(function () {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            }, 250);
        };

        var timer = setTimeout(remove, timeout || (type === 'error' ? 8000 : 4000));
        node.addEventListener('click', function (event) {
            if (event.target.classList.contains('toast-close')) {
                clearTimeout(timer);
                remove();
            }
        });
    }

    function plural(count, one, few, many) {
        count = Math.abs(parseInt(count, 10) || 0) % 100;
        var last = count % 10;
        if (count > 10 && count < 20) {
            return many;
        }
        if (last > 1 && last < 5) {
            return few;
        }
        if (last === 1) {
            return one;
        }
        return many;
    }

    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        var focusable = modal.querySelector('input, button, select, textarea');
        if (focusable) {
            setTimeout(function () {
                focusable.focus();
            }, 50);
        }
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    function setBusy(button, busy, text) {
        if (!button) {
            return;
        }
        if (busy) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = text || 'Подождите…';
        } else {
            button.disabled = false;
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* API                                                                */
    /* ------------------------------------------------------------------ */

    function api(action, options) {
        options = options || {};
        var method = options.method || 'GET';
        var url = API + '?action=' + encodeURIComponent(action);
        var params = options.params || {};
        Object.keys(params).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            }
        });

        var init = {
            method: method,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': state.csrf
            },
            credentials: 'same-origin'
        };

        if (options.data) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.data);
        }

        return fetch(url, init).then(function (response) {
            return response.text().then(function (text) {
                var payload;
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    throw new Error('Сервер вернул некорректный ответ: ' + text.slice(0, 200));
                }

                if (payload && payload.csrf) {
                    state.csrf = payload.csrf;
                }

                if (!payload.ok) {
                    var err = new Error((payload.error && payload.error.message) || 'Неизвестная ошибка');
                    err.code = payload.error && payload.error.code;
                    err.detail = payload.error && payload.error.detail;
                    err.status = response.status;
                    throw err;
                }

                return payload.data;
            });
        });
    }

    function handleApiError(error) {
        var message = error && error.message ? error.message : 'Ошибка запроса';
        if (error && error.status === 401) {
            message = 'Сессия истекла — обновите страницу и войдите снова.';
        }
        toast(message, 'error');
        return message;
    }

    /* ------------------------------------------------------------------ */
    /* Тема                                                               */
    /* ------------------------------------------------------------------ */

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
        try {
            localStorage.setItem(STORAGE_THEME, theme);
        } catch (error) {
            /* приватный режим — не страшно */
        }
    }

    (function initTheme() {
        var stored = null;
        try {
            stored = localStorage.getItem(STORAGE_THEME);
        } catch (error) {
            stored = null;
        }
        if (!stored) {
            stored = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }
        applyTheme(stored);
    }());

    /* ------------------------------------------------------------------ */
    /* Авторизация                                                        */
    /* ------------------------------------------------------------------ */

    function initAuth() {
        var form = $('#auth-form');
        if (!form) {
            return;
        }

        try {
            var saved = localStorage.getItem(STORAGE_USERNAME);
            if (saved) {
                $('#username').value = saved;
            }
        } catch (error) {
            /* игнорируем */
        }

        var toggle = $('#toggle-token');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var input = $('#token');
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = $('#auth-submit');
            var errorBox = $('#auth-error');
            var username = $('#username').value.trim();
            var token = $('#token').value.trim();

            errorBox.classList.add('hidden');
            setBusy(button, true, 'Проверяем токен…');

            api('login', { method: 'POST', data: { username: username, token: token } })
                .then(function () {
                    try {
                        if (username) {
                            localStorage.setItem(STORAGE_USERNAME, username);
                        }
                    } catch (error) {
                        /* игнорируем */
                    }
                    window.location.reload();
                })
                .catch(function (error) {
                    setBusy(button, false);
                    errorBox.textContent = error.message;
                    errorBox.classList.remove('hidden');
                });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Вкладки                                                            */
    /* ------------------------------------------------------------------ */

    function activateTab(tabId) {
        $all('.tab-btn').forEach(function (btn) {
            var active = btn.getAttribute('data-tab') === tabId;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        $all('.tab-content').forEach(function (section) {
            section.classList.toggle('active', section.id === tabId);
        });

        if (tabId === 'tab-copies' && !state.copies.length) {
            loadCopies();
        }
        if (tabId === 'tab-status') {
            loadStatus();
        }
    }

    function initTabs() {
        $all('.tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateTab(this.getAttribute('data-tab'));
            });
        });

        document.querySelector('.tabs').addEventListener('keydown', function (event) {
            var buttons = $all('.tab-btn');
            var index = buttons.indexOf(document.activeElement);
            if (index < 0) {
                return;
            }
            if (event.key === 'ArrowRight' && index < buttons.length - 1) {
                buttons[index + 1].focus();
                buttons[index + 1].click();
            } else if (event.key === 'ArrowLeft' && index > 0) {
                buttons[index - 1].focus();
                buttons[index - 1].click();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Список репозиториев                                                */
    /* ------------------------------------------------------------------ */

    function repoBadges(repo) {
        var badges = [];
        if (repo.private) {
            badges.push('<span class="badge badge-private">приватный</span>');
        }
        if (repo.archived) {
            badges.push('<span class="badge badge-warn">архив</span>');
        }
        if (repo.fork) {
            badges.push('<span class="badge badge-fork">форк</span>');
        }
        if (repo.is_copy) {
            badges.push('<span class="badge badge-copy">копия</span>');
        }
        return badges.join(' ');
    }

    function repoCard(repo) {
        var htmlUrl = safeUrl(repo.html_url) || 'https://github.com/' + encodeURIComponent(repo.full_name);
        var meta = [];
        if (repo.language) {
            meta.push('<span class="language"><i class="lang-dot" style="background:' + escapeHtml(repo.language_color) + '"></i>' + escapeHtml(repo.language) + '</span>');
        }
        meta.push('<span class="meta-item" title="Звёзды">★ ' + escapeHtml(repo.stars) + '</span>');
        meta.push('<span class="meta-item" title="Форки">⑂ ' + escapeHtml(repo.forks_count) + '</span>');
        meta.push('<span class="meta-item" title="Размер">' + escapeHtml(repo.size_human) + '</span>');
        if (repo.pushed_human) {
            meta.push('<span class="meta-item" title="Последний push">' + escapeHtml(repo.pushed_human) + '</span>');
        }
        meta.push('<span class="meta-item" title="Ветка по умолчанию">' + escapeHtml(repo.default_branch) + '</span>');

        var topics = (repo.topics || []).slice(0, 4).map(function (topic) {
            return '<span class="topic">' + escapeHtml(topic) + '</span>';
        }).join('');

        var parent = repo.fork_parent
            ? '<p class="repo-parent muted small">форк от <a href="' + escapeHtml(safeUrl(repo.fork_parent.html_url) || '#') + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(repo.fork_parent.full_name) + '</a></p>'
            : '';

        return '' +
            '<article class="repo-card" data-owner="' + escapeHtml(repo.owner) + '" data-name="' + escapeHtml(repo.name) + '">' +
                '<div class="repo-header">' +
                    '<svg class="repo-icon" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M2 2.5A2.5 2.5 0 014.5 0h8.75a.75.75 0 01.75.75v12.5a.75.75 0 01-.75.75h-2.5a.75.75 0 010-1.5h1.75v-2h-8a1 1 0 00-.714 1.7.75.75 0 01-1.072 1.05A2.495 2.495 0 012 11.5v-9zm10.5-1V9h-8c-.356 0-.694.074-1 .208V2.5a1 1 0 011-1h8z"/></svg>' +
                    '<h3 title="' + escapeHtml(repo.full_name) + '">' + escapeHtml(repo.name) + '</h3>' +
                    '<span class="repo-badges">' + repoBadges(repo) + '</span>' +
                '</div>' +
                '<p class="repo-description">' + (repo.description ? escapeHtml(repo.description) : '<span class="muted">Описание отсутствует</span>') + '</p>' +
                parent +
                (topics ? '<div class="topics">' + topics + '</div>' : '') +
                '<div class="repo-meta">' + meta.join('') + '</div>' +
                '<div class="repo-actions">' +
                    '<a class="btn-secondary" href="' + escapeHtml(htmlUrl) + '" target="_blank" rel="noopener noreferrer">Открыть</a>' +
                    '<button class="btn-secondary" data-action="branches">Ветки</button>' +
                    '<button class="btn-primary btn-compact" data-action="copy">Создать форк</button>' +
                    (repo.can_delete ? '<button class="btn-ghost btn-danger-text" data-action="delete" title="Удалить репозиторий">Удалить</button>' : '') +
                '</div>' +
            '</article>';
    }

    function loadRepos(options) {
        options = options || {};
        if (state.loading) {
            return Promise.resolve();
        }
        state.loading = true;

        var grid = $('#repos-grid');
        var empty = $('#repos-empty');
        var errorBox = $('#repos-error');
        var more = $('#repos-more');

        errorBox.classList.add('hidden');
        if (options.append !== true) {
            grid.innerHTML = '<div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div>';
            state.page = 1;
        }

        var params = {
            page: state.page,
            per_page: 30,
            sort: state.sort,
            filter: state.filter,
            q: state.query
        };
        if (state.query.length >= 2 || state.filter !== 'all') {
            params.all = 1;
        }

        return api('repos', { params: params })
            .then(function (data) {
                var items = data.items || [];
                state.hasMore = !!data.has_more;
                state.repos = options.append === true ? state.repos.concat(items) : items;

                grid.innerHTML = state.repos.map(repoCard).join('');
                empty.classList.toggle('hidden', state.repos.length > 0);
                more.classList.toggle('hidden', !state.hasMore);
                if (!state.repos.length) {
                    empty.querySelector('p').textContent = state.query
                        ? 'По запросу «' + state.query + '» ничего не найдено.'
                        : 'Репозитории не найдены.';
                }
            })
            .catch(function (error) {
                grid.innerHTML = '';
                errorBox.textContent = handleApiError(error);
                errorBox.classList.remove('hidden');
            })
            .then(function () {
                state.loading = false;
            });
    }

    function initRepos() {
        var search = $('#repo-search');
        var timer = null;
        search.addEventListener('input', function () {
            var value = this.value.trim();
            clearTimeout(timer);
            timer = setTimeout(function () {
                state.query = value;
                loadRepos();
            }, 320);
        });

        $all('.chip[data-filter]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                $all('.chip[data-filter]').forEach(function (other) {
                    other.classList.remove('active');
                });
                this.classList.add('active');
                state.filter = this.getAttribute('data-filter');
                loadRepos();
            });
        });

        $('#repo-sort').addEventListener('change', function () {
            state.sort = this.value;
            loadRepos();
        });

        $('#repo-refresh').addEventListener('click', function () {
            loadRepos().then(function () {
                toast('Список репозиториев обновлён');
            });
        });

        $('#repos-more').addEventListener('click', function () {
            state.page += 1;
            loadRepos({ append: true });
        });

        $('#repos-grid').addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]');
            if (!button) {
                return;
            }
            var card = button.closest('.repo-card');
            if (!card) {
                return;
            }
            var owner = card.getAttribute('data-owner');
            var name = card.getAttribute('data-name');
            var repo = state.repos.filter(function (item) {
                return item.owner === owner && item.name === name;
            })[0];

            var action = button.getAttribute('data-action');
            if (action === 'branches') {
                openBranches(owner, name);
            } else if (action === 'copy') {
                openCopy(owner, name, repo);
            } else if (action === 'delete') {
                openDelete(owner, name);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Ветки                                                              */
    /* ------------------------------------------------------------------ */

    function renderBranches() {
        var host = $('#branches-list');
        var filter = state.branchFilter.toLowerCase();
        var branches = state.branches.filter(function (branch) {
            return !filter || branch.name.toLowerCase().indexOf(filter) !== -1;
        });

        $('#branches-count').textContent = branches.length + ' ' + plural(branches.length, 'ветка', 'ветки', 'веток');

        if (!branches.length) {
            host.innerHTML = '<div class="empty-inline">Ничего не найдено.</div>';
            return;
        }

        host.innerHTML = branches.map(function (branch) {
            var info = state.branchesState || {};
            var url = (safeUrl(info.html_url) || 'https://github.com/' + encodeURIComponent(info.owner) + '/' + encodeURIComponent(info.repo))
                + '/tree/' + encodeURIComponent(branch.name);
            return '' +
                '<div class="branch-item">' +
                    '<svg class="branch-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M9.5 3.25a2.25 2.25 0 113 2.122V6A2.5 2.5 0 0110 8.5H6a1 1 0 00-1 1v1.128a2.251 2.251 0 11-1.5 0V5.372a2.25 2.25 0 111.5 0v1.836A2.492 2.492 0 016 7h4a1 1 0 001-1v-.628A2.25 2.25 0 019.5 3.25zM4.25 12a.75.75 0 100 1.5.75.75 0 000-1.5zM3.5 3.25a.75.75 0 101.5 0 .75.75 0 00-1.5 0zM12 3.25a.75.75 0 101.5 0 .75.75 0 00-1.5 0z"/></svg>' +
                    '<span class="branch-name">' + escapeHtml(branch.name) + '</span>' +
                    (branch.is_default ? '<span class="badge badge-default">по умолчанию</span>' : '') +
                    (branch.protected ? '<span class="badge">protected</span>' : '') +
                    (branch.short_sha ? '<code class="branch-sha" title="' + escapeHtml(branch.author ? branch.author : '') + '">' + escapeHtml(branch.short_sha) + '</code>' : '') +
                    '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" class="branch-link">Открыть →</a>' +
                '</div>';
        }).join('');
    }

    function openBranches(owner, repo) {
        $('#branches-title').textContent = 'Ветки ' + owner + '/' + repo;
        $('#branches-list').innerHTML = '<div class="loading">Загрузка…</div>';
        $('#branches-count').textContent = '';
        state.branchFilter = '';
        $('#branch-search').value = '';
        openModal('branches-modal');

        api('branches', { params: { owner: owner, repo: repo } })
            .then(function (data) {
                state.branches = data.branches || [];
                state.branchesState = data;
                renderBranches();
            })
            .catch(function (error) {
                $('#branches-list').innerHTML = '<div class="error-inline">' + escapeHtml(error.message) + '</div>';
            });
    }

    /* ------------------------------------------------------------------ */
    /* Создание копии                                                     */
    /* ------------------------------------------------------------------ */

    function currentFormMode() {
        var checked = $('#copy-modes input[type="radio"]:checked');
        return checked ? checked.value : 'copy';
    }

    function renderCopySteps(job) {
        var host = $('#copy-steps');
        var steps = job.steps || [];
        host.innerHTML = steps.map(function (step) {
            var icon = { done: '✓', error: '✕', running: '⟳', skipped: '–', pending: '•' }[step.status] || '•';
            return '<li class="step step-' + escapeHtml(step.status) + '">' +
                '<span class="step-icon">' + icon + '</span>' +
                '<span class="step-body"><strong>' + escapeHtml(step.title) + '</strong>' +
                (step.message ? '<span class="step-message">' + escapeHtml(step.message) + '</span>' : '') +
                '</span></li>';
        }).join('');

        var progress = typeof job.progress === 'number' ? job.progress : 0;
        $('#copy-progress-bar').style.width = progress + '%';

        var logHost = $('#copy-log');
        var lines = (job.log || []).map(function (entry) {
            return (entry.line || '');
        });
        logHost.textContent = lines.join('\n');
        logHost.scrollTop = logHost.scrollHeight;
    }

    function renderCopyResult(job) {
        var host = $('#copy-result');
        var result = job.result;

        if (job.status === 'error') {
            var error = job.error || {};
            host.className = 'copy-result error-message';
            host.innerHTML = '<strong>Ошибка на шаге «' + escapeHtml(error.step || '') + '».</strong> ' + escapeHtml(error.message || '');
            host.classList.remove('hidden');
            $('#copy-cancel').classList.remove('hidden');
            $('#copy-close').classList.remove('hidden');
            $('#copy-retry').classList.remove('hidden');
            $('#copy-retry').textContent = 'Повторить шаг';
            return;
        }

        if (job.status === 'cancelled') {
            host.className = 'copy-result error-message';
            host.textContent = 'Операция отменена.';
            host.classList.remove('hidden');
            $('#copy-close').classList.remove('hidden');
            return;
        }

        if (!result) {
            host.classList.add('hidden');
            return;
        }

        var stats = [];
        stats.push('режим: ' + escapeHtml(result.mode_title || ''));
        if (result.branches) {
            stats.push('веток: ' + escapeHtml(result.branches));
        }
        if (result.tags) {
            stats.push('тегов: ' + escapeHtml(result.tags));
        }
        if (result.commits) {
            stats.push('коммитов: ' + escapeHtml(result.commits));
        }
        if (result.size_human) {
            stats.push('размер: ' + escapeHtml(result.size_human));
        }
        if (result.default_branch) {
            stats.push('ветка по умолчанию: ' + escapeHtml(result.default_branch));
        }

        var warnings = (result.warnings || []).map(function (warning) {
            return '<p class="muted small">⚠ ' + escapeHtml(warning) + '</p>';
        }).join('');

        host.className = 'copy-result success-message';
        host.innerHTML =
            '<strong>Готово: ' + escapeHtml(result.full_name) + '</strong>' +
            '<p class="small">' + stats.join(' • ') + '</p>' +
            warnings +
            '<div class="modal-actions">' +
                '<a class="btn-primary" href="' + escapeHtml(safeUrl(result.html_url) || '#') + '" target="_blank" rel="noopener noreferrer">Открыть на GitHub</a>' +
                '<a class="btn-secondary" href="#tab-repositories" data-reload="1">К списку репозиториев</a>' +
            '</div>';
        host.classList.remove('hidden');
        $('#copy-close').classList.remove('hidden');
        $('#copy-cancel').classList.add('hidden');
        $('#copy-retry').classList.add('hidden');
    }

    function runCopyJob() {
        var job = state.copy.job;
        if (!job || state.copy.running) {
            return;
        }

        var next = (job.steps || []).filter(function (step) {
            return step.status === 'pending' || step.status === 'running' ||
                (state.copy.retryMode && step.key === state.copy.lastStep && step.status === 'error');
        })[0];

        if (!next) {
            renderCopyResult(job);
            return;
        }

        state.copy.running = true;
        state.copy.retryMode = false;
        state.copy.lastStep = next.key;
        $('#copy-cancel').classList.remove('hidden');
        $('#copy-retry').classList.add('hidden');
        $('#copy-close').classList.add('hidden');

        // Локально показываем шаг как выполняющийся.
        job.steps = job.steps.map(function (step) {
            return step.key === next.key ? Object.assign({}, step, { status: 'running' }) : step;
        });
        renderCopySteps(job);

        api('copy_step', { method: 'POST', data: { job_id: job.id, step: next.key } })
            .then(function (data) {
                state.copy.job = data.job;
                renderCopySteps(data.job);
                renderCopyResult(data.job);
                state.copy.running = false;

                if (data.job.status === 'error') {
                    if (data.job.error && data.job.error.code === 'name_taken') {
                        $('#copy-retry').textContent = 'Перезаписать существующий';
                        state.copy.retryMode = false;
                        $('#copy-retry').dataset.action = 'overwrite';
                    }
                    return;
                }

                if (data.job.status === 'pending' || data.job.status === 'running') {
                    runCopyJob();
                    return;
                }

                if (data.job.status === 'done') {
                    toast('Копия создана: ' + (data.job.result ? data.job.result.full_name : ''));
                    refreshRateLimit();
                    loadRepos();
                    state.copies = [];
                }
            })
            .catch(function (error) {
                state.copy.running = false;
                $('#copy-retry').classList.remove('hidden');
                handleApiError(error);
            });
    }

    function openCopy(owner, name, repo) {
        state.copy.job = null;
        state.copy.source = { owner: owner, name: name, repo: repo || null };
        state.copy.running = false;
        state.copy.retryMode = false;

        var repoInfo = repo || {};
        var meta = [];
        if (repoInfo.size_human) {
            meta.push('размер: ' + escapeHtml(repoInfo.size_human));
        }
        if (repoInfo.default_branch) {
            meta.push('ветка по умолчанию: ' + escapeHtml(repoInfo.default_branch));
        }
        if (repoInfo.private) {
            meta.push('приватный');
        }

        $('#copy-source').innerHTML =
            '<div class="copy-source-inner">' +
                '<strong>' + escapeHtml(owner + '/' + name) + '</strong>' +
                (repoInfo.description ? '<p class="muted small">' + escapeHtml(repoInfo.description) + '</p>' : '') +
                (meta.length ? '<p class="muted small">' + meta.join(' · ') + '</p>' : '') +
            '</div>';

        $('#copy-form').classList.remove('hidden');
        $('#copy-progress').classList.add('hidden');
        $('#copy-result').classList.add('hidden');
        $('#copy-form-error').classList.add('hidden');
        $('#copy-name').value = name + '-copy';
        $('#copy-overwrite').checked = false;
        $('#copy-private').checked = true;
        $('#copy-retry').dataset.action = 'retry';
        $('#copy-retry').classList.add('hidden');
        $('#copy-cancel').classList.add('hidden');
        $('#copy-close').classList.add('hidden');

        var firstMode = $('#copy-modes input[value="copy"]');
        if (firstMode) {
            firstMode.checked = true;
            $all('.mode-item').forEach(function (item) {
                item.classList.toggle('active', !!item.querySelector('input[value="copy"]'));
            });
        }

        openModal('copy-modal');
    }

    function initCopy() {
        var form = $('#copy-form');
        if (!form) {
            return;
        }

        $('#copy-modes').addEventListener('change', function () {
            $all('.mode-item').forEach(function (item) {
                var input = item.querySelector('input');
                item.classList.toggle('active', input.checked);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var errorBox = $('#copy-form-error');
            var button = $('#copy-submit');
            errorBox.classList.add('hidden');
            setBusy(button, true, 'Создаём задание…');

            api('copy_start', {
                method: 'POST',
                data: {
                    source_owner: state.copy.source.owner,
                    source_repo: state.copy.source.name,
                    target_name: $('#copy-name').value.trim(),
                    mode: currentFormMode(),
                    private: $('#copy-private').checked,
                    overwrite: $('#copy-overwrite').checked
                }
            }).then(function (data) {
                setBusy(button, false);
                state.copy.job = data.job;
                $('#copy-form').classList.add('hidden');
                $('#copy-progress').classList.remove('hidden');
                $('#copy-progress-title').textContent = 'Копирование ' + state.copy.source.owner + '/' + state.copy.source.name;
                renderCopySteps(data.job);
                renderCopyResult(data.job);
                if (data.job.status === 'done') {
                    toast('Копия создана: ' + data.job.result.full_name);
                    refreshRateLimit();
                    loadRepos();
                } else if (data.job.status === 'error') {
                    $('#copy-retry').classList.remove('hidden');
                    if (data.job.error && data.job.error.code === 'name_taken') {
                        $('#copy-retry').dataset.action = 'overwrite';
                        $('#copy-retry').textContent = 'Перезаписать существующий';
                    }
                } else {
                    runCopyJob();
                }
            }).catch(function (error) {
                setBusy(button, false);
                errorBox.textContent = error.message;
                errorBox.classList.remove('hidden');
            });
        });

        $('#copy-cancel').addEventListener('click', function () {
            if (!state.copy.job) {
                return;
            }
            api('copy_cancel', { method: 'POST', data: { job_id: state.copy.job.id } })
                .then(function (data) {
                    state.copy.job = data.job;
                    renderCopySteps(data.job);
                    renderCopyResult(data.job);
                })
                .catch(handleApiError);
        });

        $('#copy-retry').addEventListener('click', function () {
            if (!state.copy.job) {
                return;
            }
            if (this.dataset.action === 'overwrite') {
                this.dataset.action = 'retry';
                this.textContent = 'Повторить шаг';
                api('copy_start', {
                    method: 'POST',
                    data: {
                        source_owner: state.copy.source.owner,
                        source_repo: state.copy.source.name,
                        target_name: $('#copy-name').value.trim() || state.copy.source.name + '-copy',
                        mode: currentFormMode(),
                        private: $('#copy-private').checked,
                        overwrite: true
                    }
                }).then(function (data) {
                    state.copy.job = data.job;
                    renderCopySteps(data.job);
                    renderCopyResult(data.job);
                    if (data.job.status === 'pending' || data.job.status === 'running') {
                        runCopyJob();
                    }
                }).catch(handleApiError);
                return;
            }

            state.copy.retryMode = true;
            runCopyJob();
        });

        $('#copy-close').addEventListener('click', function () {
            closeModal('copy-modal');
            loadRepos();
        });

        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-reload]');
            if (link) {
                closeModal('copy-modal');
                activateTab('tab-repositories');
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Копии                                                              */
    /* ------------------------------------------------------------------ */

    function modeTitle(mode) {
        var found = state.modes.filter(function (item) {
            return item.id === mode;
        })[0];
        return found ? found.title : mode;
    }

    function renderCopies() {
        var host = $('#copies-list');
        var empty = $('#copies-empty');

        if (!state.copies.length) {
            host.innerHTML = '';
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');

        host.innerHTML = state.copies.map(function (copy) {
            var origin = copy.origin === 'github'
                ? '<span class="badge">github</span>'
                : '<span class="badge badge-copy">через приложение</span>';
            var stats = [];
            if (copy.branches) {
                stats.push(copy.branches + ' ' + plural(copy.branches, 'ветка', 'ветки', 'веток'));
            }
            if (copy.commits) {
                stats.push(copy.commits + ' ' + plural(copy.commits, 'коммит', 'коммита', 'коммитов'));
            }
            if (copy.size_human) {
                stats.push(copy.size_human);
            }
            if (copy.private) {
                stats.push('приватный');
            }

            return '' +
                '<div class="copy-item">' +
                    '<div class="copy-main">' +
                        '<div class="copy-title">' +
                            '<a href="' + escapeHtml(safeUrl(copy.html_url) || '#') + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(copy.full_name) + '</a>' +
                            ' ' + origin +
                            ' <span class="badge badge-mode">' + escapeHtml(modeTitle(copy.mode)) + '</span>' +
                        '</div>' +
                        (copy.source ? '<p class="muted small">источник: <a href="' + escapeHtml(safeUrl(copy.source_url) || '#') + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(copy.source) + '</a></p>' : '') +
                        '<p class="muted small">' + escapeHtml(copy.created_human || '') + (stats.length ? ' • ' + escapeHtml(stats.join(' • ')) : '') + '</p>' +
                    '</div>' +
                    '<div class="copy-actions">' +
                        '<button class="btn-secondary btn-compact" data-copy-owner="' + escapeHtml(copy.full_name.split('/')[0] || '') + '" data-copy-name="' + escapeHtml(copy.full_name.split('/')[1] || '') + '" data-action="branches">Ветки</button>' +
                        (state.user && copy.full_name.split('/')[0].toLowerCase() === String(state.user.login).toLowerCase()
                            ? '<button class="btn-ghost btn-danger-text" data-copy-owner="' + escapeHtml(copy.full_name.split('/')[0] || '') + '" data-copy-name="' + escapeHtml(copy.full_name.split('/')[1] || '') + '" data-action="delete">Удалить</button>'
                            : '') +
                    '</div>' +
                '</div>';
        }).join('');
    }

    function loadCopies() {
        var errorBox = $('#copies-error');
        errorBox.classList.add('hidden');
        $('#copies-list').innerHTML = '<div class="loading">Загрузка…</div>';

        return api('copies')
            .then(function (data) {
                state.copies = data.items || [];
                renderCopies();
            })
            .catch(function (error) {
                $('#copies-list').innerHTML = '';
                errorBox.textContent = handleApiError(error);
                errorBox.classList.remove('hidden');
            });
    }

    function initCopies() {
        var list = $('#copies-list');
        if (!list) {
            return;
        }

        $('#copies-refresh').addEventListener('click', function () {
            loadCopies();
        });

        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]');
            if (!button) {
                return;
            }
            var owner = button.getAttribute('data-copy-owner');
            var name = button.getAttribute('data-copy-name');
            if (button.getAttribute('data-action') === 'branches') {
                openBranches(owner, name);
            } else if (button.getAttribute('data-action') === 'delete') {
                openDelete(owner, name);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Состояние                                                          */
    /* ------------------------------------------------------------------ */

    function statusValue(value) {
        if (typeof value === 'boolean') {
            return value
                ? '<span class="ok">✓ да</span>'
                : '<span class="bad">✕ нет</span>';
        }
        if (value === null || value === undefined || value === '') {
            return '<span class="muted">—</span>';
        }
        return escapeHtml(value);
    }

    function renderStatus(data) {
        var host = $('#status-body');
        var sections = [];

        var gitOk = data.git && data.git.available;
        sections.push({
            title: 'Утилита git',
            rows: [
                ['Доступна', gitOk],
                ['Версия', data.git ? data.git.version : ''],
                ['Бинарник', data.git ? data.git.binary : '']
            ]
        });

        sections.push({
            title: 'PHP',
            rows: [
                ['Версия', data.php.version],
                ['SAPI', data.php.sapi],
                ['memory_limit', data.php.memory],
                ['max_execution_time', data.php['max_execution_time'] + ' сек'],
                ['proc_open', data.php.functions.proc_open],
                ['exec', data.php.functions.exec],
                ['curl', data.php.extensions.curl],
                ['openssl', data.php.extensions.openssl],
                ['zlib', data.php.extensions.zlib],
                ['ZipArchive', data.php.extensions.zip],
                ['mbstring', data.php.extensions.mbstring]
            ]
        });

        sections.push({
            title: 'Сеть',
            rows: [
                ['GitHub API', data.network.api_base],
                ['Хост git', data.network.git_host],
                ['CA bundle', data.network.ca_bundle],
                ['Проверка TLS отключена', data.network.insecure_tls]
            ]
        });

        var storageRows = [
            ['Каталог данных', data.storage.data_dir],
            ['Доступен для записи', data.storage.writable],
            ['Свободно на диске', data.storage.free_space]
        ];
        sections.push({ title: 'Хранилище', rows: storageRows });

        if (data.rate_limit) {
            var reset = data.rate_limit.reset ? new Date(data.rate_limit.reset * 1000).toLocaleTimeString() : '—';
            sections.push({
                title: 'Лимиты GitHub API',
                rows: [
                    ['Лимит', data.rate_limit.limit],
                    ['Осталось', data.rate_limit.remaining],
                    ['Сброс', reset]
                ]
            });
        }

        host.innerHTML = sections.map(function (section) {
            var rows = section.rows.map(function (row) {
                return '<div class="status-row"><span class="status-key">' + escapeHtml(row[0]) + '</span><span class="status-value">' + statusValue(row[1]) + '</span></div>';
            }).join('');
            return '<div class="status-card"><h4>' + escapeHtml(section.title) + '</h4>' + rows + '</div>';
        }).join('') +
        '<div class="status-card">' +
            '<h4>Обслуживание</h4>' +
            '<p class="muted small">Удаляет временные файлы и старые задания копирования (безопасно в любой момент).</p>' +
            '<button class="btn-secondary" id="cleanup-btn">Очистить временные файлы</button>' +
        '</div>';

        var cleanup = $('#cleanup-btn');
        if (cleanup) {
            cleanup.addEventListener('click', function () {
                var button = this;
                setBusy(button, true, 'Очищаем…');
                api('cleanup', { method: 'POST' })
                    .then(function (result) {
                        setBusy(button, false);
                        toast('Удалено заданий: ' + result.removed_jobs);
                    })
                    .catch(function (error) {
                        setBusy(button, false);
                        handleApiError(error);
                    });
            });
        }
    }

    function loadStatus() {
        $('#status-body').innerHTML = '<div class="loading">Собираем диагностику…</div>';
        return api('status')
            .then(renderStatus)
            .catch(function (error) {
                $('#status-body').innerHTML = '<div class="error-inline">' + escapeHtml(error.message) + '</div>';
            });
    }

    /* ------------------------------------------------------------------ */
    /* Удаление репозитория                                               */
    /* ------------------------------------------------------------------ */

    function openDelete(owner, repo) {
        state.delete.owner = owner;
        state.delete.repo = repo;
        $('#delete-name').textContent = owner + '/' + repo;
        $('#delete-confirm').value = '';
        $('#delete-error').classList.add('hidden');
        openModal('delete-modal');
    }

    function initDelete() {
        var button = $('#delete-submit');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var errorBox = $('#delete-error');
            var confirm = $('#delete-confirm').value.trim();
            errorBox.classList.add('hidden');
            setBusy(button, true, 'Удаляем…');

            api('delete_repo', {
                method: 'POST',
                data: { owner: state.delete.owner, repo: state.delete.repo, confirm: confirm }
            }).then(function () {
                setBusy(button, false);
                closeModal('delete-modal');
                toast('Репозиторий удалён: ' + state.delete.owner + '/' + state.delete.repo);
                loadRepos();
                state.copies = [];
            }).catch(function (error) {
                setBusy(button, false);
                errorBox.textContent = error.message;
                errorBox.classList.remove('hidden');
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Лимиты, выход, прочее                                              */
    /* ------------------------------------------------------------------ */

    function refreshRateLimit() {
        var chip = $('#rate-chip');
        if (!chip) {
            return;
        }
        api('rate_limit')
            .then(function (data) {
                chip.textContent = 'API ' + data.remaining + '/' + data.limit;
                chip.classList.toggle('rate-low', data.limit > 0 && data.remaining / data.limit < 0.1);
                chip.title = 'Остаток запросов к GitHub API: ' + data.remaining + ' из ' + data.limit;
            })
            .catch(function () {
                chip.textContent = '—';
            });
    }

    function initChrome() {
        var themeToggle = $('#theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                applyTheme(current === 'light' ? 'dark' : 'light');
            });
        }

        var logout = $('#logout-btn');
        if (logout) {
            logout.addEventListener('click', function () {
                if (!window.confirm('Выйти из аккаунта? Токен будет удалён из сессии.')) {
                    return;
                }
                api('logout', { method: 'POST' })
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(handleApiError);
            });
        }

        $all('[data-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal(this.getAttribute('data-close'));
            });
        });

        $all('.modal').forEach(function (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.classList.remove('modal-open');
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                $all('.modal.active').forEach(function (modal) {
                    modal.classList.remove('active');
                });
                document.body.classList.remove('modal-open');
            }
        });

        var branchSearch = $('#branch-search');
        if (branchSearch) {
            branchSearch.addEventListener('input', function () {
                state.branchFilter = this.value.trim();
                renderBranches();
            });
        }
    }

    function initGestures() {
        var touchStartX = 0;
        var touchStartY = 0;

        document.addEventListener('touchstart', function (event) {
            var touch = event.changedTouches[0];
            touchStartX = touch.screenX;
            touchStartY = touch.screenY;
        }, { passive: true });

        document.addEventListener('touchend', function (event) {
            var touch = event.changedTouches[0];
            var deltaX = touchStartX - touch.screenX;
            var deltaY = Math.abs(touchStartY - touch.screenY);

            if (Math.abs(deltaX) < 60 || deltaY > 60) {
                return;
            }
            if (document.querySelector('.modal.active')) {
                return;
            }
            var buttons = $all('.tab-btn');
            var current = buttons.map(function (button) {
                return button.classList.contains('active');
            }).indexOf(true);
            if (current < 0) {
                return;
            }
            var next = deltaX > 0 ? current + 1 : current - 1;
            if (next >= 0 && next < buttons.length) {
                buttons[next].click();
            }
        }, { passive: true });
    }

    function initNetworkState() {
        window.addEventListener('offline', function () {
            toast('Нет подключения к интернету', 'error');
        });
        window.addEventListener('online', function () {
            toast('Подключение восстановлено');
        });
        window.addEventListener('unhandledrejection', function (event) {
            if (event.reason && event.reason.message) {
                console.error('[GHM]', event.reason);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Старт                                                              */
    /* ------------------------------------------------------------------ */

    function init() {
        initChrome();
        initAuth();

        if (!state.authorized) {
            return;
        }

        initTabs();
        initRepos();
        initCopy();
        initCopies();
        initDelete();
        initGestures();
        initNetworkState();

        loadRepos();
        refreshRateLimit();

        // Мелочь: подсказка о фильтре, если репозиториев много.
        setInterval(function () {
            if (!document.hidden && state.authorized) {
                refreshRateLimit();
            }
        }, 300000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
