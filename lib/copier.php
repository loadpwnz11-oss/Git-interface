<?php
/**
 * Копирование репозиториев: «независимая копия» (git), «быстрая копия» (архив)
 * и обычный форк GitHub. Копирование разбито на шаги, чтобы каждый HTTP-запрос
 * был коротким и прогресс был виден пользователю.
 */

namespace Ghm;

final class Copier
{
    const MODE_COPY = 'copy';
    const MODE_ARCHIVE = 'archive';
    const MODE_FORK = 'fork';

    /** Порог «большого» репозитория (КБ), при котором предлагаем архивный режим. */
    const BIG_REPO_KB = 1048576;

    /** @var GitHub */
    private $gh;

    /** @var string */
    private $login;

    /** @var JobStore */
    private $jobs;

    /** @var GitClient */
    private $git;

    /** @var History */
    private $history;

    public function __construct(GitHub $gh, $login, JobStore $jobs, GitClient $git, History $history)
    {
        $this->gh = $gh;
        $this->login = (string) $login;
        $this->jobs = $jobs;
        $this->git = $git;
        $this->history = $history;
    }

    /** Описание режимов для интерфейса. */
    public static function modes()
    {
        return array(
            array(
                'id'          => self::MODE_COPY,
                'title'       => 'Независимая копия',
                'description' => 'Полная копия через git: все ветки, теги и история коммитов. Копия не связана с оригиналом.',
                'badge'       => 'рекомендуется',
            ),
            array(
                'id'          => self::MODE_ARCHIVE,
                'title'       => 'Быстрая копия (архив)',
                'description' => 'Скачивает архив последней версии и создаёт новый репозиторий с одним коммитом. Полезно для больших репозиториев.',
                'badge'       => '',
            ),
            array(
                'id'          => self::MODE_FORK,
                'title'       => 'Форк GitHub',
                'description' => 'Обычный форк средствами GitHub: мгновенно, но остаётся связанным с оригиналом и помечен как «fork».',
                'badge'       => '',
            ),
        );
    }

    public static function stepsFor($mode)
    {
        if ($mode === self::MODE_FORK) {
            return array(array('Подготовка и создание форка', 'prepare'));
        }

        $steps = array(
            array('Подготовка: создание репозитория', 'prepare'),
        );

        if ($mode === self::MODE_ARCHIVE) {
            $steps[] = array('Загрузка архива и сборка', 'archive');
        } else {
            $steps[] = array('Клонирование исходного репозитория', 'clone');
        }

        $steps[] = array('Отправка данных в ваш репозиторий', 'push');
        $steps[] = array('Завершение и проверка', 'finalize');

        return $steps;
    }

    /**
     * Создание задания и выполнение первого шага.
     *
     * @param string $sid   Идентификатор сессии (для привязки задания)
     * @param array  $input source_owner, source_repo, target_name, private, overwrite, mode
     *
     * @return array Задание
     */
    public function start($sid, array $input)
    {
        $sourceOwner = isset($input['source_owner']) ? trim((string) $input['source_owner']) : '';
        $sourceRepo = isset($input['source_repo']) ? trim((string) $input['source_repo']) : '';
        $mode = isset($input['mode']) ? (string) $input['mode'] : self::MODE_COPY;
        $overwrite = !empty($input['overwrite']);
        $private = !empty($input['private']);

        if (!Security::isValidOwner($sourceOwner)) {
            throw new ApiException('Некорректный владелец исходного репозитория.', 422, 'bad_owner');
        }
        if (!Security::isValidRepoName($sourceRepo)) {
            throw new ApiException('Некорректное имя исходного репозитория.', 422, 'bad_repo');
        }
        if (!in_array($mode, array(self::MODE_COPY, self::MODE_ARCHIVE, self::MODE_FORK), true)) {
            throw new ApiException('Неизвестный режим копирования.', 422, 'bad_mode');
        }

        $rawTarget = isset($input['target_name']) ? trim((string) $input['target_name']) : '';
        if ($rawTarget !== '' && preg_match('#[/\\\\]|\.\.|\x00-\x1f#', $rawTarget)) {
            throw new ApiException(
                'Имя нового репозитория не может содержать «/», «\\», «..» и служебные символы.',
                422,
                'bad_target_name'
            );
        }

        $targetName = Security::slugifyRepoName($rawTarget !== '' ? $rawTarget : $sourceRepo . '-copy');

        if ($targetName === '' || !Security::isValidRepoName($targetName)) {
            throw new ApiException('Имя нового репозитория может содержать только латинские буквы, цифры, точку, дефис и подчёркивание.', 422, 'bad_target_name');
        }

        if ($mode === self::MODE_COPY && !$this->git->available()) {
            throw new ApiException(
                'Утилита git недоступна на сервере, полная копия невозможна. Выберите режим «Быстрая копия (архив)» или «Форк GitHub».',
                503,
                'git_missing'
            );
        }

        // Источник проверяем до создания задания: нет доступа или репозитория —
        // пользователь сразу получает понятную ошибку, а не «задание с ошибкой».
        $source = $this->gh->getRepo($sourceOwner, $sourceRepo);

        $job = $this->jobs->create(array(
            'sid'     => $this->jobs->sessionKey($sid),
            'mode'    => $mode,
            'login'   => $this->login,
            'source'  => array('owner' => $sourceOwner, 'repo' => $sourceRepo),
            'target'  => array(
                'owner'     => $this->login,
                'name'      => $targetName,
                'private'   => $private,
                'overwrite' => $overwrite,
            ),
            'warnings' => array(),
            'result'   => null,
        ));

        $steps = array();
        foreach (self::stepsFor($mode) as $definition) {
            $steps[] = array(
                'key'         => $definition[1],
                'title'       => $definition[0],
                'status'      => 'pending',
                'message'     => '',
                'started_at'  => 0,
                'finished_at' => 0,
            );
        }
        $job['steps'] = $steps;
        $job['progress'] = 0;

        $this->jobs->save($job);

        // Первый шаг выполняем сразу — он быстрый и сразу показывает ошибки.
        return $this->execute($sid, $job['id'], $steps[0]['key']);
    }

    /**
     * Выполнение шага задания.
     *
     * @return array Обновлённое задание
     */
    public function execute($sid, $jobId, $stepKey)
    {
        $jobId = (string) $jobId;
        $stepKey = (string) $stepKey;

        return $this->jobs->withLock($jobId, $sid, function (array $job) use ($stepKey) {
            $step = $this->jobs->step($job, $stepKey);
            if ($step === null) {
                throw new ApiException('Шаг «' . $stepKey . '» не найден в задании.', 400, 'bad_step');
            }

            if ($job['status'] === 'done' || $job['status'] === 'cancelled' || $step['status'] === 'done') {
                // Отменённое задание дальше не двигаем: шаги могли остаться
                // в статусе «ожидает» и без этой проверки выполнились бы.
                return $job;
            }

            $this->jobs->updateStep($job, $stepKey, array(
                'status'     => 'running',
                'started_at' => time(),
                'message'    => '',
            ));
            $job['status'] = 'running';
            $job['error'] = null;
            $this->recalcProgress($job);
            $this->jobs->save($job);

            try {
                switch ($stepKey) {
                    case 'prepare':
                        $this->stepPrepare($job);
                        break;
                    case 'clone':
                        $this->stepClone($job);
                        break;
                    case 'archive':
                        $this->stepArchive($job);
                        break;
                    case 'push':
                        $this->stepPush($job);
                        break;
                    case 'finalize':
                        $this->stepFinalize($job);
                        break;
                    default:
                        throw new ApiException('Неизвестный шаг: ' . $stepKey, 400, 'bad_step');
                }

                $this->jobs->updateStep($job, $stepKey, array(
                    'status'      => 'done',
                    'finished_at' => time(),
                ));
            } catch (\Exception $e) {
                $message = $e instanceof ApiException || $e instanceof GitHubException
                    ? $e->getMessage()
                    : 'Неожиданная ошибка: ' . $e->getMessage();

                $this->jobs->updateStep($job, $stepKey, array(
                    'status'      => 'error',
                    'message'     => mb_substr($message, 0, 300, 'UTF-8'),
                    'finished_at' => time(),
                ));
                $this->jobs->log($job, 'ОШИБКА: ' . $message);
                $job['status'] = 'error';
                $job['error'] = array(
                    'step'    => $stepKey,
                    'message' => $message,
                    'code'    => $e instanceof ApiException ? $e->getErrorCode() : ($e instanceof GitHubException ? 'github_error' : 'exception'),
                    'detail'  => $e instanceof GitHubException ? $e->getUserMessage() : '',
                );

                // Рабочий каталог больше не нужен — шаги умеют начинать заново.
                $this->cleanupWorkDir($job);
                $this->recalcProgress($job);
                $this->jobs->save($job);

                return $job;
            }

            $this->recalcProgress($job);
            $this->jobs->save($job);

            return $job;
        });
    }

    /** @return array */
    public function job($sid, $jobId)
    {
        $job = $this->jobs->load($jobId, $sid);
        if ($job === null) {
            throw new ApiException('Задание не найдено или устарело.', 404, 'job_not_found');
        }

        return $job;
    }

    /** Отмена задания: удаляем рабочую копию, помечаем задание отменённым. */
    public function cancel($sid, $jobId)
    {
        return $this->jobs->withLock($jobId, $sid, function (array $job) {
            if ($job['status'] === 'done') {
                return $job;
            }

            $this->cleanupWorkDir($job);
            foreach ($job['steps'] as $index => $step) {
                if ($step['status'] === 'pending' || $step['status'] === 'running') {
                    $job['steps'][$index]['status'] = $step['status'] === 'running' ? 'error' : 'skipped';
                    if ($job['steps'][$index]['status'] === 'error') {
                        $job['steps'][$index]['message'] = 'Операция отменена пользователем.';
                    }
                }
            }
            $job['status'] = 'cancelled';
            $this->jobs->log($job, 'Задание отменено пользователем.');
            $this->recalcProgress($job);
            $this->jobs->save($job);

            return $job;
        });
    }

    /* --------------------------------------------------------------------- */
    /* Шаги задания                                                          */
    /* --------------------------------------------------------------------- */

    private function stepPrepare(array &$job)
    {
        $sourceOwner = $job['source']['owner'];
        $sourceRepo = $job['source']['repo'];

        // Источник уже проверен в start(); здесь читаем свежие метаданные.
        $source = $this->gh->getRepo($sourceOwner, $sourceRepo);
        $sourceView = RepoView::fromApi($source);

        $job['source'] = array_merge($job['source'], array(
            'full_name'      => $sourceView['full_name'],
            'html_url'       => $sourceView['html_url'],
            'clone_url'      => isset($source['clone_url']) ? (string) $source['clone_url'] : '',
            'private'        => $sourceView['private'],
            'default_branch' => $sourceView['default_branch'],
            'size_kb'        => $sourceView['size_kb'],
            'size_human'     => $sourceView['size_human'],
            'description'    => $sourceView['description'],
            'empty'          => $sourceView['is_empty'],
        ));

        $this->jobs->log($job, 'Источник: ' . $sourceView['full_name'] . ' (' . $sourceView['size_human'] . ', ветка по умолчанию: ' . $sourceView['default_branch'] . ')');

        if ($sourceView['size_kb'] > self::BIG_REPO_KB && $job['mode'] === self::MODE_COPY) {
            $job['warnings'][] = 'Репозиторий большой (' . $sourceView['size_human'] . ') — клонирование может занять продолжительное время.';
        }

        if ($job['mode'] === self::MODE_FORK) {
            $this->prepareFork($job, $sourceView);
            $job['status'] = 'done';

            return;
        }

        if (!$this->git->available() && $job['mode'] === self::MODE_COPY) {
            throw new ApiException('git недоступен на сервере — выберите архивный режим.', 503, 'git_missing');
        }

        $this->prepareTargetRepo($job, $sourceView);
        $this->ensureWorkDir($job);

        $this->jobs->log($job, 'Целевой репозиторий: ' . $job['target']['owner'] . '/' . $job['target']['name']);
    }

    private function prepareFork(array &$job, array $sourceView)
    {
        $name = $job['target']['name'];
        $existing = $this->gh->findRepo($this->login, $name);
        if ($existing) {
            throw new ApiException(
                'Репозиторий ' . $this->login . '/' . $name . ' уже существует — выберите другое имя.',
                409,
                'name_taken',
                array('full_name' => $this->login . '/' . $name, 'html_url' => isset($existing['html_url']) ? $existing['html_url'] : '')
            );
        }

        $fork = $this->gh->createFork($job['source']['owner'], $job['source']['repo'], $name);
        $forkView = RepoView::fromApi($fork);

        $job['target']['full_name'] = $forkView['full_name'];
        $job['target']['html_url'] = $forkView['html_url'];
        $job['target']['clone_url'] = isset($fork['clone_url']) ? (string) $fork['clone_url'] : '';
        $job['target']['created'] = true;
        $job['target']['name'] = $forkView['name'];

        $this->jobs->log($job, 'Создан форк GitHub: ' . $forkView['full_name']);
        $job['warnings'][] = 'Форк остаётся связанным с оригиналом: изменения оригинала можно подтягивать, а в названии остаётся пометка «fork».';

        $job['result'] = $this->buildResult($job, array(
            'branches' => 0,
            'tags'     => 0,
            'commits'  => 0,
            'repo'     => $forkView,
        ));
        $this->history->add($this->login, array(
            'full_name'      => $forkView['full_name'],
            'html_url'       => $forkView['html_url'],
            'source'         => $sourceView['full_name'],
            'source_url'     => $sourceView['html_url'],
            'mode'           => self::MODE_FORK,
            'private'        => $forkView['private'],
            'default_branch' => $forkView['default_branch'],
            'size_human'     => $forkView['size_human'],
        ));
        $this->jobs->log($job, 'Форк готов: ' . $forkView['html_url']);
    }

    private function prepareTargetRepo(array &$job, array $sourceView)
    {
        $name = $job['target']['name'];
        $existing = $this->gh->findRepo($this->login, $name);

        if ($existing && empty($job['target']['overwrite'])) {
            $existingView = RepoView::fromApi($existing);
            throw new ApiException(
                'Репозиторий ' . $existingView['full_name'] . ' уже существует. Включите перезапись или укажите другое имя.',
                409,
                'name_taken',
                array('full_name' => $existingView['full_name'], 'html_url' => $existingView['html_url'])
            );
        }

        if ($existing) {
            $existingView = RepoView::fromApi($existing);
            $job['target']['full_name'] = $existingView['full_name'];
            $job['target']['html_url'] = $existingView['html_url'];
            $job['target']['clone_url'] = isset($existing['clone_url']) ? (string) $existing['clone_url'] : '';
            $job['target']['created'] = false;
            $job['target']['overwrite'] = true;
            $job['target']['name'] = $existingView['name'];
            $job['warnings'][] = 'Существующий репозиторий будет перезаписан: ветки и теги с теми же именами получат новую историю.';
            $this->jobs->log($job, 'Используем существующий репозиторий ' . $existingView['full_name'] . ' (перезапись).');

            return;
        }

        $description = $sourceView['description'] !== ''
            ? mb_substr($sourceView['description'], 0, 250, 'UTF-8')
            : 'Копия ' . $sourceView['full_name'];

        $created = $this->gh->createRepo(array(
            'name'        => $name,
            'description' => $description,
            'private'     => !empty($job['target']['private']),
            'auto_init'   => false,
            'has_issues'  => true,
            'has_wiki'    => false,
        ));

        $createdView = RepoView::fromApi($created);
        $job['target']['full_name'] = $createdView['full_name'];
        $job['target']['html_url'] = $createdView['html_url'];
        $job['target']['clone_url'] = isset($created['clone_url']) ? (string) $created['clone_url'] : '';
        $job['target']['created'] = true;
        $job['target']['name'] = $createdView['name'];

        $this->jobs->log($job, 'Создан пустой репозиторий ' . $createdView['full_name'] . ($createdView['private'] ? ' (приватный)' : ''));
    }

    private function stepClone(array &$job)
    {
        $this->ensureWorkDir($job);

        $cloneUrl = isset($job['source']['clone_url']) ? (string) $job['source']['clone_url'] : '';
        if ($cloneUrl === '') {
            throw new ApiException('GitHub не вернул URL для клонирования.', 502, 'no_clone_url');
        }

        $target = $job['work_dir'] . '/source.git';
        JobStore::removeDir($target);

        $this->jobs->log($job, 'Клонирование ' . $job['source']['full_name'] . ' — это может занять время…');
        $result = $this->git->cloneMirror($cloneUrl, $target);

        if (!$result['ok']) {
            JobStore::removeDir($target);
            throw new ApiException(GitClient::errorMessage($result), 502, 'clone_failed');
        }

        $refs = $this->git->refs($target);
        $job['work']['source_git'] = $target;
        $job['source']['default_branch'] = $this->pickDefaultBranch($job, $refs);

        $this->jobs->log($job, 'Склонировано: веток — ' . count($refs['heads']) . ', тегов — ' . count($refs['tags']) . '.');
    }

    private function stepArchive(array &$job)
    {
        $this->ensureWorkDir($job);

        $ref = isset($job['source']['default_branch']) ? (string) $job['source']['default_branch'] : '';
        if (!Security::isValidRef($ref)) {
            throw new ApiException('Некорректное имя ветки по умолчанию у исходного репозитория.', 502, 'bad_ref');
        }

        $archive = $job['work_dir'] . '/source.tar.gz';
        $tree = $job['work_dir'] . '/tree';
        JobStore::removeDir($tree);
        @unlink($archive);

        $this->jobs->log($job, 'Скачивание архива ветки ' . $ref . '…');
        $download = $this->gh->downloadTarball($job['source']['owner'], $job['source']['repo'], $ref, $archive);

        if ((int) $download['status'] === 404) {
            throw new ApiException('У исходного репозитория нет содержимого в ветке ' . $ref . '.', 409, 'empty_source');
        }
        if ((int) $download['status'] < 200 || (int) $download['status'] >= 300) {
            throw new ApiException('GitHub вернул HTTP ' . (int) $download['status'] . ' при скачивании архива.', 502, 'archive_failed');
        }
        if (!is_file($archive) || filesize($archive) === 0) {
            throw new ApiException('Архив репозитория пуст.', 502, 'archive_empty');
        }

        Config::ensureDir($tree);
        $this->extractArchive($archive, $tree);
        $this->jobs->log($job, 'Архив распакован (' . Format::bytes((int) round(filesize($archive) / 1024)) . ').');

        $result = $this->git->initAndCommit($tree, 'Импорт из ' . $job['source']['full_name'] . ' (' . date('d.m.Y') . ')');
        if (!$result['ok']) {
            $message = GitClient::errorMessage($result);
            if (stripos($message, 'nothing to commit') !== false) {
                $job['warnings'][] = 'В исходном репозитории нет файлов — создан пустой репозиторий.';
                $job['work']['tree'] = $tree;
                $job['work']['empty'] = true;

                return;
            }
            throw new ApiException('Не удалось собрать репозиторий: ' . $message, 500, 'archive_build_failed');
        }

        $job['work']['tree'] = $tree;
        $job['work']['empty'] = false;
        @unlink($archive);
    }

    private function stepPush(array &$job)
    {
        $cloneUrl = isset($job['target']['clone_url']) ? (string) $job['target']['clone_url'] : '';
        if ($cloneUrl === '') {
            $repo = $this->gh->getRepo($job['target']['owner'], $job['target']['name']);
            $cloneUrl = isset($repo['clone_url']) ? (string) $repo['clone_url'] : '';
        }
        if ($cloneUrl === '') {
            throw new ApiException('Не удалось получить URL целевого репозитория.', 502, 'no_clone_url');
        }

        if ($job['mode'] === self::MODE_ARCHIVE) {
            if (!empty($job['work']['empty'])) {
                $this->jobs->log($job, 'Пустой репозиторий — отправка не требуется.');

                return;
            }
            $tree = isset($job['work']['tree']) ? (string) $job['work']['tree'] : '';
            if ($tree === '' || !is_dir($tree)) {
                throw new ApiException('Рабочий каталог потерян, повторите шаг сборки архива.', 409, 'work_dir_missing');
            }

            $branch = $this->pickDefaultBranch($job, array('heads' => array(), 'tags' => array()));
            $this->jobs->log($job, 'Отправка ветки ' . $branch . ' в ' . $job['target']['full_name'] . '…');
            $result = $this->git->pushBranch($tree, $cloneUrl, $branch);
        } else {
            $sourceGit = isset($job['work']['source_git']) ? (string) $job['work']['source_git'] : '';
            if ($sourceGit === '' || !is_dir($sourceGit)) {
                throw new ApiException('Локальная копия потеряна, повторите шаг клонирования.', 409, 'work_dir_missing');
            }

            $this->jobs->log($job, 'Отправка всех веток и тегов в ' . $job['target']['full_name'] . '…');
            $result = $this->git->pushAll($sourceGit, $cloneUrl);
        }

        if (!$result['ok']) {
            throw new ApiException('Не удалось отправить данные: ' . GitClient::errorMessage($result), 502, 'push_failed');
        }

        $this->jobs->log($job, 'Данные отправлены.');
    }

    private function stepFinalize(array &$job)
    {
        $owner = $job['target']['owner'];
        $name = $job['target']['name'];
        $desired = $this->pickDefaultBranch($job, array());

        // Пытаемся выставить ветку по умолчанию как в оригинале.
        if ($desired !== '') {
            try {
                $this->gh->updateRepo($owner, $name, array('default_branch' => $desired));
            } catch (GitHubException $e) {
                $job['warnings'][] = 'Не удалось сразу переключить ветку по умолчанию: ' . $e->getUserMessage();
            }
        }

        $repo = $this->gh->getRepo($owner, $name);
        $repoView = RepoView::fromApi($repo);

        if ($desired !== '' && $repoView['default_branch'] !== $desired) {
            // GitHub не переключил ветку (бывает у некоторых аккаунтов) — оставляем его ветку,
            // но сообщаем об этом, чтобы пользователь знал, что открывать.
            $job['warnings'][] = 'GitHub оставил ветку по умолчанию «' . $repoView['default_branch']
                . '». Содержимое скопировано, ветка «' . $desired . '» тоже на месте — её можно назначить вручную в настройках репозитория.';
        }

        $branches = 0;
        $tags = 0;
        $commits = 0;

        if ($job['mode'] === self::MODE_ARCHIVE) {
            $branches = !empty($job['work']['empty']) ? 0 : 1;
            $commits = !empty($job['work']['empty']) ? 0 : 1;
        } else {
            $sourceGit = isset($job['work']['source_git']) ? (string) $job['work']['source_git'] : '';
            if ($sourceGit !== '' && is_dir($sourceGit)) {
                $refs = $this->git->refs($sourceGit);
                $branches = count($refs['heads']);
                $tags = count($refs['tags']);
                $commits = $this->git->commitCount($sourceGit);
            }
        }

        $job['result'] = $this->buildResult($job, array(
            'branches' => $branches,
            'tags'     => $tags,
            'commits'  => $commits,
            'repo'     => $repoView,
        ));

        $this->history->add($this->login, array(
            'full_name'      => $repoView['full_name'],
            'html_url'       => $repoView['html_url'],
            'source'         => $job['source']['full_name'],
            'source_url'     => isset($job['source']['html_url']) ? $job['source']['html_url'] : '',
            'mode'           => $job['mode'],
            'private'        => $repoView['private'],
            'default_branch' => $repoView['default_branch'],
            'branches'       => $branches,
            'commits'        => $commits,
            'size_human'     => $repoView['size_human'],
        ));

        $this->jobs->log($job, 'Готово: ' . $repoView['html_url']);
        $this->cleanupWorkDir($job);
        $job['status'] = 'done';
    }

    /* --------------------------------------------------------------------- */
    /* Вспомогательные методы                                                */
    /* --------------------------------------------------------------------- */

    private function buildResult(array &$job, array $stats)
    {
        return array(
            'mode'           => $job['mode'],
            'mode_title'     => $this->modeTitle($job['mode']),
            'source'         => $job['source']['full_name'],
            'source_url'     => isset($job['source']['html_url']) ? $job['source']['html_url'] : '',
            'full_name'      => $job['target']['full_name'],
            'html_url'       => $job['target']['html_url'],
            'private'        => !empty($job['target']['private']),
            'default_branch' => isset($stats['repo']['default_branch']) ? $stats['repo']['default_branch'] : '',
            'branches'       => (int) $stats['branches'],
            'tags'           => (int) $stats['tags'],
            'commits'        => (int) $stats['commits'],
            'size_human'     => isset($stats['repo']['size_human']) ? $stats['repo']['size_human'] : '',
            'warnings'       => array_values($job['warnings']),
            'repo'           => isset($stats['repo']) ? $stats['repo'] : null,
            'finished_at'    => time(),
        );
    }

    private function modeTitle($mode)
    {
        foreach (self::modes() as $definition) {
            if ($definition['id'] === $mode) {
                return $definition['title'];
            }
        }

        return $mode;
    }

    /** Ветка по умолчанию: сначала из API, иначе из локальных ссылок. */
    private function pickDefaultBranch(array $job, array $refs)
    {
        $desired = isset($job['source']['default_branch']) ? (string) $job['source']['default_branch'] : '';
        if ($desired !== '' && Security::isValidRef($desired)) {
            return $desired;
        }

        if (!empty($refs['heads'])) {
            return in_array('main', $refs['heads'], true) ? 'main' : (string) $refs['heads'][0];
        }

        return 'main';
    }

    /** Рабочий каталог задания. */
    private function ensureWorkDir(array &$job)
    {
        $dir = isset($job['work_dir']) ? (string) $job['work_dir'] : '';
        if ($dir === '' || !is_dir($dir)) {
            $dir = Config::workDir() . '/' . $job['id'];
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ApiException('Не удалось создать рабочий каталог на сервере.', 500, 'work_dir_failed');
            }
            $job['work_dir'] = $dir;
        }

        if (!isset($job['work']) || !is_array($job['work'])) {
            $job['work'] = array();
        }

        return $dir;
    }

    private function cleanupWorkDir(array &$job)
    {
        if (!empty($job['work_dir'])) {
            JobStore::removeDir($job['work_dir']);
        }
        $job['work'] = array();
    }

    private function recalcProgress(array &$job)
    {
        $total = count($job['steps']);
        $done = 0;
        foreach ($job['steps'] as $step) {
            if ($step['status'] === 'done' || $step['status'] === 'skipped') {
                $done++;
            }
        }
        $job['progress'] = $total > 0 ? (int) round($done / $total * 100) : 0;
    }

    /** Распаковка .tar.gz архивным способом (tar), иначе средствами PHP. */
    private function extractArchive($archive, $target)
    {
        $tar = Process::run(array(
            Config::tarBinary(), '-xzf', $archive, '-C', $target, '--strip-components=1',
        ), array('timeout' => 300, 'max_output' => 65536));

        if ($tar['rc'] === 0) {
            return;
        }

        // Резервный вариант: PharData (не требует внешних программ).
        try {
            $phar = new \PharData($archive);
            $phar->extractTo($target, null, true);
            $items = @scandir($target);
            $root = null;
            foreach ((array) $items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if ($root === null && is_dir($target . '/' . $item)) {
                    $root = $target . '/' . $item;
                } else {
                    $root = null;
                    break;
                }
            }
            if ($root !== null) {
                foreach ((array) @scandir($root) as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    @rename($root . '/' . $item, $target . '/' . $item);
                }
                @rmdir($root);
            }

            return;
        } catch (\Exception $e) {
            throw new ApiException(
                'Не удалось распаковать архив: ' . GitClient::errorMessage($tar) . '. Проверьте, что на сервере доступна утилита tar.',
                500,
                'extract_failed'
            );
        }
    }
}
