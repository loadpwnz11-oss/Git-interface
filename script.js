// Переключение вкладок
document.addEventListener('DOMContentLoaded', function() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // Убираем активный класс со всех кнопок и контента
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Добавляем активный класс текущей кнопке и контенту
            this.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
});

// Функция выхода
function logout() {
    if (confirm('Вы уверены, что хотите выйти?')) {
        // Очищаем сессию через AJAX запрос
        fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'logout=1'
        }).then(() => {
            location.reload();
        });
    }
}

// Просмотр веток репозитория
function viewBranches(owner, repo) {
    const modal = document.getElementById('branchesModal');
    const branchesList = document.getElementById('branchesList');
    
    modal.classList.add('active');
    branchesList.innerHTML = '<div class="loading">Загрузка...</div>';
    
    // Загружаем ветки через AJAX
    fetch(`index.php?get_branches=1&repo_owner=${encodeURIComponent(owner)}&repo_name=${encodeURIComponent(repo)}`)
        .then(response => response.json())
        .then(data => {
            if (data.branches && data.branches.length > 0) {
                let html = '';
                data.branches.forEach(branch => {
                    html += `
                        <div class="branch-item">
                            <svg class="branch-icon" viewBox="0 0 24 24" width="20" height="20">
                                <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12c0 4.42 2.87 8.17 6.84 9.5.5.08.66-.23.66-.5v-1.69c-2.77.6-3.36-1.34-3.36-1.34-.46-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.87 1.52 2.34 1.07 2.91.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.92 0-1.11.38-2.09 1.02-2.79-.1-.25-.45-1.29.1-2.64 0 0 .84-.27 2.75 1.02.79-.22 1.65-.33 2.5-.33.85 0 1.71.11 2.5.33 1.91-1.29 2.75-1.02 2.75-1.02.55 1.35.2 2.39.1 2.64.65.7 1.02 1.68 1.02 2.79 0 3.82-2.34 4.66-4.57 4.91.36.31.69.92.69 1.85V21c0 .27.16.59.67.5C19.14 20.16 22 16.42 22 12A10 10 0 0012 2z"/>
                            </svg>
                            <span class="branch-name">${escapeHtml(branch.name)}</span>
                            <a href="https://github.com/${escapeHtml(owner)}/${escapeHtml(repo)}/tree/${escapeHtml(branch.name)}" target="_blank" class="branch-link">Открыть →</a>
                        </div>
                    `;
                });
                branchesList.innerHTML = html;
            } else {
                branchesList.innerHTML = '<div class="empty-state"><p>Ветки не найдены</p></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            branchesList.innerHTML = '<div class="error-message">Ошибка при загрузке веток</div>';
        });
}

// Показать модальное окно для создания форка
function showForkModal(owner, repo) {
    const modal = document.getElementById('forkModal');
    const forkRepoOwner = document.getElementById('forkRepoOwner');
    const forkRepoName = document.getElementById('forkRepoName');
    const forkRepoInfo = document.getElementById('forkRepoInfo');
    
    forkRepoOwner.value = owner;
    forkRepoName.value = repo;
    forkRepoInfo.textContent = `${owner} / ${repo}`;
    
    modal.classList.add('active');
}

// Закрытие модального окна
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Закрытие модального окна по клику вне его области
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
});

// Экранирование HTML для безопасности
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Обработка отправки формы создания форка
document.addEventListener('DOMContentLoaded', function() {
    const forkForm = document.getElementById('forkForm');
    if (forkForm) {
        forkForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Создание...';
        });
    }
});

// Анимация карточек при скролле
if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.repo-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'all 0.5s ease';
        observer.observe(card);
    });
}

// Поддержка свайпов для мобильных устройств
let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].screenX;
}, false);

document.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
}, false);

function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) > swipeThreshold) {
        const tabBtns = document.querySelectorAll('.tab-btn');
        const activeTab = document.querySelector('.tab-btn.active');
        const currentIndex = Array.from(tabBtns).indexOf(activeTab);
        
        if (diff > 0 && currentIndex < tabBtns.length - 1) {
            // Свайп влево - следующая вкладка
            tabBtns[currentIndex + 1].click();
        } else if (diff < 0 && currentIndex > 0) {
            // Свайп вправо - предыдущая вкладка
            tabBtns[currentIndex - 1].click();
        }
    }
}

// PWA поддержка (опционально)
if ('serviceWorker' in navigator) {
    // Можно добавить service worker для офлайн работы
    // navigator.serviceWorker.register('sw.js');
}

// Проверка ориентации устройства
window.addEventListener('orientationchange', function() {
    // Адаптация под изменение ориентации
    setTimeout(() => {
        window.scrollTo(0, 0);
    }, 100);
});

// Предотвращение зума на iOS
document.addEventListener('gesturestart', function(e) {
    e.preventDefault();
});

// Оптимизация для Retina дисплеев
if (window.devicePixelRatio >= 2) {
    document.body.classList.add('retina');
}

// Ленивая загрузка изображений (если будут добавлены)
if ('loading' in HTMLImageElement.prototype) {
    const images = document.querySelectorAll('img[loading="lazy"]');
    images.forEach(img => {
        img.src = img.dataset.src;
    });
} else {
    // Fallback для браузеров без поддержки lazy loading
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/lazysizes/5.3.2/lazysizes.min.js';
    document.body.appendChild(script);
}

// Уведомления о действиях
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 15px 25px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 9999;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Добавляем стили для уведомлений
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Автосохранение данных формы (опционально)
const authForm = document.querySelector('.auth-form');
if (authForm) {
    const savedUsername = localStorage.getItem('github_username');
    if (savedUsername) {
        document.getElementById('username').value = savedUsername;
    }
    
    authForm.addEventListener('submit', function() {
        const username = document.getElementById('username').value;
        localStorage.setItem('github_username', username);
    });
}

// Обработка ошибок сети
window.addEventListener('offline', () => {
    showNotification('Нет подключения к интернету', 'error');
});

window.addEventListener('online', () => {
    showNotification('Подключение восстановлено', 'success');
});
