<?php
/**
 * GitHub Manager — веб-интерфейс.
 *
 * Данные загружаются через api.php; вся серверная логика — в каталоге lib/.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use Ghm\App;
use Ghm\Config;
use Ghm\Copier;
use Ghm\Security;

$app = App::boot();
$authorized = $app->isAuthorized();
$user = $app->user();
$csrf = Security::csrfToken();
$demo = Config::isDemo();

function e($value)
{
    echo Security::e($value);
}

$boot = array(
    'csrf'     => $csrf,
    'demo'     => $demo,
    'version'  => Config::version(),
    'auth'     => $authorized,
    'user'     => $user,
    'modes'    => Copier::modes(),
    'steps'    => array(
        Copier::MODE_COPY    => Copier::stepsFor(Copier::MODE_COPY),
        Copier::MODE_ARCHIVE => Copier::stepsFor(Copier::MODE_ARCHIVE),
        Copier::MODE_FORK    => Copier::stepsFor(Copier::MODE_FORK),
    ),
    'api'      => 'api.php',
);
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <meta name="referrer" content="no-referrer">
    <title><?php e(Config::appName()); ?> — управление репозиториями GitHub</title>
    <link rel="stylesheet" href="style.css?v=<?php e(Config::version()); ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%236366f1' d='M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-6.315 0-1.395.495-2.545 1.305-3.465-.255-.405-.57-1.29.12-2.67 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.69 1.38.375 2.265.12 2.67.81.915 1.305 2.055 1.305 3.465 0 4.995-2.805 6.015-5.475 6.315.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z'/%3E%3C/svg%3E">
</head>
<body>
<div class="app-container">
<?php if (!$authorized): ?>
    <!-- ==================== Экран входа ==================== -->
    <div class="auth-container">
        <div class="logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="64" height="64">
                <path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-6.315 0-1.395.495-2.545 1.305-3.465-.255-.405-.57-1.29.12-2.67 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.69 1.38.375 2.265.12 2.67.81.915 1.305 2.055 1.305 3.465 0 4.995-2.805 6.015-5.475 6.315.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
            </svg>
        </div>
        <h1>GitHub Manager</h1>
        <p class="subtitle">Управляйте репозиториями и создавайте независимые копии</p>

        <?php if ($demo): ?>
            <div class="notice notice-info">
                <strong>Демо-режим.</strong> Подключён локальный макет GitHub API, реальные запросы не выполняются.
                Вход выполняется автоматически.
            </div>
        <?php endif; ?>

        <div id="auth-error" class="error-message hidden" role="alert"></div>

        <form id="auth-form" class="auth-form" autocomplete="off">
            <div class="form-group">
                <label for="username">Имя пользователя GitHub <span class="muted">(необязательно)</span></label>
                <input type="text" id="username" name="username" placeholder="Ваш логин" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="token">Personal Access Token</label>
                <div class="input-row">
                    <input type="password" id="token" name="token" required placeholder="ghp_…" autocomplete="current-password">
                    <button type="button" class="btn-icon" id="toggle-token" title="Показать токен" aria-label="Показать токен">👁</button>
                </div>
                <small>
                    Токен нужен со scope <code>repo</code> (и <code>delete_repo</code>, если хотите удалять репозитории).
                    Создать: <a href="https://github.com/settings/tokens/new?scopes=repo,delete_repo&amp;description=GitHub%20Manager" target="_blank" rel="noopener noreferrer">github.com/settings/tokens</a>.
                    Токен сохраняется только в сессии PHP на этом сервере и никогда не передаётся в браузер.
                </small>
            </div>
            <button type="submit" class="btn-primary" id="auth-submit">Войти</button>
        </form>
    </div>
<?php else: ?>
    <!-- ==================== Основной интерфейс ==================== -->
    <header class="header">
        <div class="header-content">
            <div class="brand">
                <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
                    <path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-6.315 0-1.395.495-2.545 1.305-3.465-.255-.405-.57-1.29.12-2.67 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.69 1.38.375 2.265.12 2.67.81.915 1.305 2.055 1.305 3.465 0 4.995-2.805 6.015-5.475 6.315.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
                </svg>
                <div>
                    <h1>GitHub Manager</h1>
                    <p class="brand-sub">
                        <?php if ($demo): ?><span class="badge badge-demo">демо-режим</span><?php endif; ?>
                        <span class="muted">v<?php e(Config::version()); ?></span>
                    </p>
                </div>
            </div>

            <div class="header-actions">
                <span class="rate-chip" id="rate-chip" title="Остаток лимита GitHub API">—</span>
                <button type="button" class="btn-icon" id="theme-toggle" title="Переключить тему" aria-label="Переключить тему">🌗</button>
                <div class="user-chip">
                    <?php if (!empty($user['avatar_url'])): ?>
                        <img src="<?php e($user['avatar_url']); ?>" alt="" width="28" height="28" loading="lazy" referrerpolicy="no-referrer">
                    <?php endif; ?>
                    <div class="user-meta">
                        <a href="<?php e($user['html_url'] ?: 'https://github.com/' . $user['login']); ?>" target="_blank" rel="noopener noreferrer" class="user-login">@<?php e($user['login']); ?></a>
                        <?php if (!empty($user['name'])): ?><span class="muted small"><?php e($user['name']); ?></span><?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-logout" id="logout-btn">Выйти</button>
            </div>
        </div>
    </header>

    <?php if ($demo): ?>
        <div class="notice notice-info">
            <strong>Демо-режим.</strong> Приложение работает с локальным макетом GitHub API: список репозиториев
            и создание копий выполняются на сервере в песочнице, интернет не используется.
        </div>
    <?php endif; ?>

    <nav class="tabs" role="tablist" aria-label="Разделы">
        <button class="tab-btn active" data-tab="tab-repositories" role="tab" aria-selected="true">Репозитории</button>
        <button class="tab-btn" data-tab="tab-copies" role="tab" aria-selected="false">Мои форки</button>
        <button class="tab-btn" data-tab="tab-status" role="tab" aria-selected="false">Состояние</button>
    </nav>

    <main class="main-content">
        <!-- Репозитории -->
        <section id="tab-repositories" class="tab-content active" role="tabpanel">
            <div class="toolbar">
                <div class="toolbar-search">
                    <input type="search" id="repo-search" placeholder="Поиск по названию, описанию, языку…" aria-label="Поиск репозиториев">
                </div>
                <div class="toolbar-filters" role="group" aria-label="Фильтр">
                    <button class="chip active" data-filter="all">Все</button>
                    <button class="chip" data-filter="public">Публичные</button>
                    <button class="chip" data-filter="private">Приватные</button>
                    <button class="chip" data-filter="fork">Форки</button>
                    <button class="chip" data-filter="copy">Копии</button>
                </div>
                <div class="toolbar-actions">
                    <select id="repo-sort" aria-label="Сортировка">
                        <option value="updated">По обновлению</option>
                        <option value="pushed">По последнему push</option>
                        <option value="name">По имени</option>
                        <option value="stars">По звёздам</option>
                    </select>
                    <button class="btn-secondary" id="repo-refresh">Обновить</button>
                </div>
            </div>

            <div id="repos-error" class="error-message hidden" role="alert"></div>
            <div id="repos-grid" class="repo-grid" aria-live="polite"></div>
            <div id="repos-empty" class="empty-state hidden">
                <p>Репозитории не найдены.</p>
            </div>
            <div class="load-more-wrap">
                <button class="btn-secondary hidden" id="repos-more">Показать ещё</button>
            </div>
        </section>

        <!-- Копии -->
        <section id="tab-copies" class="tab-content" role="tabpanel">
            <div class="toolbar">
                <p class="toolbar-text">
                    Здесь собраны ваши форки GitHub и независимые копии, созданные этим приложением.
                </p>
                <div class="toolbar-actions">
                    <button class="btn-secondary" id="copies-refresh">Обновить</button>
                </div>
            </div>
            <div id="copies-error" class="error-message hidden" role="alert"></div>
            <div id="copies-list" class="copy-list" aria-live="polite"></div>
            <div id="copies-empty" class="empty-state hidden">
                <p>Пока пусто. Откройте вкладку «Репозитории» и нажмите «Создать форк».</p>
            </div>
        </section>

        <!-- Состояние -->
        <section id="tab-status" class="tab-content" role="tabpanel">
            <div class="toolbar">
                <p class="toolbar-text">Диагностика сервера и подключения к GitHub API.</p>
                <div class="toolbar-actions">
                    <button class="btn-secondary" id="status-refresh">Обновить</button>
                </div>
            </div>
            <div id="status-body" class="status-grid"></div>
        </section>
    </main>

    <!-- Модальное окно: ветки -->
    <div id="branches-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="branches-title">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="branches-title">Ветки репозитория</h3>
                <button class="modal-close" data-close="branches-modal" aria-label="Закрыть">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-subhead">
                    <input type="search" id="branch-search" placeholder="Фильтр по имени ветки…" aria-label="Фильтр веток">
                    <span class="muted small" id="branches-count"></span>
                </div>
                <div id="branches-list">
                    <div class="loading">Загрузка…</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно: создание копии -->
    <div id="copy-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="copy-title">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <h3 id="copy-title">Создать форк или независимую копию</h3>
                <button class="modal-close" data-close="copy-modal" aria-label="Закрыть">&times;</button>
            </div>
            <div class="modal-body">
                <div id="copy-source" class="copy-source"></div>

                <form id="copy-form" class="copy-form">
                    <div class="form-group">
                        <label for="copy-name">Имя нового репозитория</label>
                        <input type="text" id="copy-name" required pattern="[A-Za-z0-9._\-]+" maxlength="100">
                        <small>Латиница, цифры, точка, дефис, подчёркивание. Репозиторий будет создан в вашем аккаунте.</small>
                    </div>

                    <div class="form-group">
                        <label>Режим копирования</label>
                        <div class="mode-list" id="copy-modes">
                            <?php foreach (Copier::modes() as $index => $mode): ?>
                                <label class="mode-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <input type="radio" name="mode" value="<?php e($mode['id']); ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                                    <span class="mode-body">
                                        <span class="mode-head">
                                            <strong><?php e($mode['title']); ?></strong>
                                            <?php if ($mode['badge'] !== ''): ?><span class="badge"><?php e($mode['badge']); ?></span><?php endif; ?>
                                        </span>
                                        <span class="muted small"><?php e($mode['description']); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <label class="checkbox">
                            <input type="checkbox" id="copy-private" checked>
                            <span>Приватный репозиторий</span>
                        </label>
                        <label class="checkbox">
                            <input type="checkbox" id="copy-overwrite">
                            <span>Перезаписать, если имя занято</span>
                        </label>
                    </div>

                    <div id="copy-form-error" class="error-message hidden" role="alert"></div>

                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" data-close="copy-modal">Отмена</button>
                        <button type="submit" class="btn-primary" id="copy-submit">Начать копирование</button>
                    </div>
                </form>

                <div id="copy-progress" class="copy-progress hidden">
                    <h4 id="copy-progress-title">Копирование…</h4>
                    <ol class="steps" id="copy-steps"></ol>
                    <div class="progress-bar"><span id="copy-progress-bar" style="width:0%"></span></div>
                    <pre class="log" id="copy-log" aria-live="polite"></pre>
                    <div id="copy-result" class="copy-result hidden"></div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary hidden" id="copy-cancel">Отменить</button>
                        <button type="button" class="btn-secondary hidden" id="copy-close">Закрыть</button>
                        <button type="button" class="btn-primary hidden" id="copy-retry">Повторить шаг</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно: удаление репозитория -->
    <div id="delete-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="delete-title">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="delete-title">Удалить репозиторий</h3>
                <button class="modal-close" data-close="delete-modal" aria-label="Закрыть">&times;</button>
            </div>
            <div class="modal-body">
                <p class="danger-text">
                    Репозиторий <strong id="delete-name"></strong> будет удалён без возможности восстановления
                    (нужен scope <code>delete_repo</code>).
                </p>
                <div class="form-group">
                    <label for="delete-confirm">Введите имя репозитория для подтверждения</label>
                    <input type="text" id="delete-confirm" autocomplete="off" spellcheck="false">
                </div>
                <div id="delete-error" class="error-message hidden" role="alert"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-close="delete-modal">Отмена</button>
                    <button type="button" class="btn-danger" id="delete-submit">Удалить</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <span><?php e(Config::appName()); ?> v<?php e(Config::version()); ?></span>
        <span class="muted">•</span>
        <a href="https://github.com/loadpwnz11-oss/Git-interface" target="_blank" rel="noopener noreferrer">Исходный код</a>
        <span class="muted">•</span>
        <a href="README.md" target="_blank" rel="noopener noreferrer">Документация</a>
    </footer>
<?php endif; ?>

    <div id="toasts" class="toasts" aria-live="polite" aria-atomic="true"></div>
</div>

<script>window.GHM_BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="script.js?v=<?php e(Config::version()); ?>"></script>
</body>
</html>
