<?php
session_start();

// Конфигурация
$github_token = ''; // Токен можно передать через форму или установить здесь
$github_username = ''; // Имя пользователя GitHub

// Обработка авторизации и выхода
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['token'])) {
        $_SESSION['github_token'] = $_POST['token'];
        $_SESSION['github_username'] = $_POST['username'];
        header('Location: index.php');
        exit;
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$token = $_SESSION['github_token'] ?? '';
$username = $_SESSION['github_username'] ?? '';

// Функция для выполнения запросов к GitHub API
function githubRequest($url, $token = '') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: PHP-GitHub-Client',
        $token ? 'Authorization: token ' . $token : ''
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$repositories = [];
$branches = [];
$error = '';

if ($token && $username) {
    // Получение репозиториев пользователя
    $repos_url = "https://api.github.com/users/{$username}/repos?per_page=100";
    $repositories = githubRequest($repos_url, $token);
    
    if (isset($repositories['message']) && $repositories['message'] === 'Bad credentials') {
        $error = 'Неверный токен доступа';
        $repositories = [];
    } elseif (!is_array($repositories)) {
        $repositories = [];
    }
}

// Обработка получения веток
if (isset($_GET['get_branches']) && $token) {
    $repo_owner = $_GET['repo_owner'];
    $repo_name = $_GET['repo_name'];
    
    $branches_url = "https://api.github.com/repos/{$repo_owner}/{$repo_name}/branches?per_page=100";
    $branches = githubRequest($branches_url, $token);
    
    header('Content-Type: application/json');
    echo json_encode(['branches' => is_array($branches) ? $branches : []]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GitHub Manager</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php if (!$token || !$username): ?>
            <!-- Форма авторизации -->
            <div class="auth-container">
                <div class="logo">
                    <svg viewBox="0 0 24 24" width="64" height="64">
                        <path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-6.315 0-1.395.495-2.545 1.305-3.465-.255-.405-.57-1.29.12-2.67 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.69 1.38.375 2.265.12 2.67.81.915 1.305 2.055 1.305 3.465 0 4.995-2.805 6.015-5.475 6.315.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
                    </svg>
                </div>
                <h1>GitHub Manager</h1>
                <p class="subtitle">Управляйте своими репозиториями</p>
                
                <?php if ($error): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" class="auth-form">
                    <div class="form-group">
                        <label for="username">Имя пользователя GitHub</label>
                        <input type="text" id="username" name="username" required placeholder="Ваш логин">
                    </div>
                    <div class="form-group">
                        <label for="token">GitHub Token</label>
                        <input type="password" id="token" name="token" required placeholder="Ваш токен доступа">
                        <small>Создайте токен в настройках GitHub → Developer settings → Personal access tokens</small>
                    </div>
                    <button type="submit" class="btn-primary">Войти</button>
                </form>
            </div>
        <?php else: ?>
            <!-- Основной интерфейс -->
            <header class="header">
                <div class="header-content">
                    <h1>GitHub Manager</h1>
                    <button class="btn-logout" onclick="logout()">Выйти</button>
                </div>
            </header>
            
            <?php if (isset($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <main class="main-content">
                <!-- Вкладки -->
                <div class="tabs">
                    <button class="tab-btn active" data-tab="repositories">Репозитории</button>
                </div>
                
                <!-- Секция репозиториев -->
                <section id="repositories" class="tab-content active">
                    <h2>Ваши репозитории</h2>
                    <?php if (empty($repositories)): ?>
                        <div class="empty-state">
                            <p>Репозитории не найдены</p>
                        </div>
                    <?php else: ?>
                        <div class="repo-grid">
                            <?php foreach ($repositories as $repo): ?>
                                <div class="repo-card" data-owner="<?php echo htmlspecialchars($repo['owner']['login']); ?>" data-name="<?php echo htmlspecialchars($repo['name']); ?>">
                                    <div class="repo-header">
                                        <svg class="repo-icon" viewBox="0 0 24 24" width="20" height="20">
                                            <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12c0 4.42 2.87 8.17 6.84 9.5.5.08.66-.23.66-.5v-1.69c-2.77.6-3.36-1.34-3.36-1.34-.46-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.87 1.52 2.34 1.07 2.91.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.92 0-1.11.38-2.09 1.02-2.79-.1-.25-.45-1.29.1-2.64 0 0 .84-.27 2.75 1.02.79-.22 1.65-.33 2.5-.33.85 0 1.71.11 2.5.33 1.91-1.29 2.75-1.02 2.75-1.02.55 1.35.2 2.39.1 2.64.65.7 1.02 1.68 1.02 2.79 0 3.82-2.34 4.66-4.57 4.91.36.31.69.92.69 1.85V21c0 .27.16.59.67.5C19.14 20.16 22 16.42 22 12A10 10 0 0012 2z"/>
                                        </svg>
                                        <h3><?php echo htmlspecialchars($repo['name']); ?></h3>
                                    </div>
                                    <p class="repo-description"><?php echo htmlspecialchars($repo['description'] ?? 'Описание отсутствует'); ?></p>
                                    <div class="repo-meta">
                                        <span class="language" style="background-color: <?php echo getRandomColor($repo['language']); ?>">
                                            <?php echo htmlspecialchars($repo['language'] ?? 'Unknown'); ?>
                                        </span>
                                        <span class="stars">⭐ <?php echo $repo['stargazers_count']; ?></span>
                                        <span class="forks">🍴 <?php echo $repo['forks_count']; ?></span>
                                    </div>
                                    <div class="repo-actions">
                                        <a href="<?php echo htmlspecialchars($repo['html_url']); ?>" target="_blank" class="btn-secondary">Открыть</a>
                                        <button class="btn-view-branches" onclick="viewBranches('<?php echo htmlspecialchars($repo['owner']['login']); ?>', '<?php echo htmlspecialchars($repo['name']); ?>')">Ветки</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
            
            <!-- Модальное окно для веток -->
            <div id="branchesModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Ветки репозитория</h3>
                        <button class="modal-close" onclick="closeModal('branchesModal')">&times;</button>
                    </div>
                    <div class="modal-body" id="branchesList">
                        <div class="loading">Загрузка...</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="script.js"></script>
</body>
</html>

<?php
function getRandomColor($language) {
    $colors = [
        'JavaScript' => '#f1e05a',
        'Python' => '#3572A5',
        'Java' => '#b07219',
        'PHP' => '#4F5D95',
        'HTML' => '#e34c26',
        'CSS' => '#563d7c',
        'TypeScript' => '#2b7489',
        'Ruby' => '#701516',
        'Go' => '#00ADD8',
        'Rust' => '#dea584',
        'Swift' => '#ffac45',
        'Kotlin' => '#A97BFF',
    ];
    return $colors[$language] ?? '#888';
}
?>
