<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Только POST запросы']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$token = $data['token'] ?? '';
$sourceOwner = $data['sourceOwner'] ?? '';
$sourceRepo = $data['sourceRepo'] ?? '';
$newName = $data['newName'] ?? '';

if (!$token || !$sourceOwner || !$sourceRepo || !$newName) {
    echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
    exit;
}

// Очистка имени репозитория (только буквы, цифры, дефис, подчеркивание)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $newName)) {
    echo json_encode(['success' => false, 'message' => 'Недопустимое имя репозитория']);
    exit;
}

$tempDir = sys_get_temp_dir() . '/gh_copy_' . uniqid();
$sourceUrl = "https://${token}@github.com/${sourceOwner}/${sourceRepo}.git";
$newRepoUrl = "https://${token}@github.com/${sourceOwner}/${newName}.git";

try {
    // 1. Создаем пустой репозиторий через API GitHub
    $ch = curl_init('https://api.github.com/user/repos');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $newName, 'private' => false]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $token,
        'Content-Type: application/json',
        'User-Agent: PHP-Script'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201 && $httpCode !== 200) {
        $error = json_decode($result, true);
        $msg = $error['message'] ?? 'Ошибка создания репозитория';
        // Если репо уже существует с таким именем
        if (strpos($msg, 'already exists') !== false) {
             throw new Exception('Репозиторий с таким именем уже существует у вас!');
        }
        throw new Exception($msg);
    }

    // 2. Клонируем исходный репозиторий во временную папку
    mkdir($tempDir);
    $cloneCmd = "git clone --depth 1 ${sourceUrl} ${tempDir}/src 2>&1";
    exec($cloneCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        throw new Exception('Ошибка клонирования исходника: ' . implode("\n", $output));
    }

    // 3. Инициализируем новый репо в папке клона, меняем remote и пушим
    // Удаляем старую .git директорию из клона
    exec("rm -rf ${tempDir}/src/.git");
    
    // Инициализируем новую git
    exec("cd ${tempDir}/src && git init 2>&1", $output, $returnVar);
    if ($returnVar !== 0) throw new Exception('Ошибка git init');

    // Добавляем файлы
    exec("cd ${tempDir}/src && git add . 2>&1", $output, $returnVar);
    
    // Коммит
    exec("cd ${tempDir}/src && git config user.email 'bot@example.com' && git config user.name 'Bot'");
    exec("cd ${tempDir}/src && git commit -m 'Initial copy from ${sourceOwner}/${sourceRepo}' 2>&1", $output, $returnVar);
    
    // Ветка main
    exec("cd ${tempDir}/src && git branch -M main 2>&1");

    // Добавляем remote нового репо
    exec("cd ${tempDir}/src && git remote add origin ${newRepoUrl} 2>&1", $output, $returnVar);

    // Пуш
    $pushCmd = "cd ${tempDir}/src && git push -u origin main 2>&1";
    exec($pushCmd, $output, $returnVar);
    
    if ($returnVar !== 0) {
        // Иногда пуш падает, если репо только что создан и GitHub требует мастер/main синхронизацию, 
        // но обычно для нового пустого репо это работает.
        // Попробуем форсированный пуш если обычный не вышел
        if (strpos(implode($output), 'failed') !== false) {
             exec("cd ${tempDir}/src && git push -f -u origin main 2>&1", $output, $returnVar);
             if ($returnVar !== 0) {
                 throw new Exception('Ошибка пуша: ' . implode("\n", $output));
             }
        }
    }

    // Уборка
    exec("rm -rf ${tempDir}");

    echo json_encode(['success' => true, 'message' => 'Копия создана успешно']);

} catch (Exception $e) {
    // Уборка в случае ошибки
    if (file_exists($tempDir)) {
        exec("rm -rf ${tempDir}");
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
