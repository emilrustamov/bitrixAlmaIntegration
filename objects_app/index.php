<?php
/**
 * Alma Objects Management App
 * Отдельное приложение для управления объектами Alma
 */

// Включаем отображение ошибок для диагностики
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../Core/Config/Config.php';
    require_once __DIR__ . '/../Core/Logger.php';
    require_once __DIR__ . '/../Core/Config/DatabaseConfig.php';
    require_once('AlmaObjectsManager.php');

    Config::load();

    $manager = new AlmaObjectsManager();
} catch (Exception $e) {
    echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
    echo "<h3 style='color: #c00;'>Ошибка инициализации:</h3>";
    echo "<pre style='color: #800;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre style='font-size: 12px; color: #666;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    exit;
}

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // Отладочный вывод
    error_log("POST request received. Action: " . $action);
    
    try {
        switch ($action) {
            case 'load_data':
                $categorized = $manager->getCategorizedObjectsWithBitrixCached();
                $total = 0;
                foreach ($categorized as $objects) {
                    $total += count($objects);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $categorized,
                    'total' => $total,
                    'message' => $total > 0 ? "" : "База данных пуста. Запустите синхронизацию."
                ]);
                break;
                
            case 'load_data_with_bitrix':
                $categorized = $manager->getCategorizedObjectsWithBitrixCached();
                $total = 0;
                foreach ($categorized as $objects) {
                    $total += count($objects);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $categorized,
                    'total' => $total,
                    'message' => $total > 0 ? "Данные загружены с информацией из Bitrix24 (кэшированные)" : "База данных пуста. Запустите синхронизацию."
                ]);
                break;
                
            case 'sync_data':
                $result = $manager->syncFromApi();
                echo json_encode($result);
                break;
                
            case 'update_single':
                $objectId = $_POST['object_id'] ?? '';
                if (empty($objectId)) {
                    echo json_encode(['success' => false, 'error' => 'Object ID required']);
                    break;
                }
                
                // Получаем информацию об объекте
                $obj = $manager->getExistingObject($objectId);
                
                if (!$obj) {
                    echo json_encode(['success' => false, 'error' => 'Object not found']);
                    break;
                }
                
                // Обновляем детали этого объекта
                $isRoom = (bool)$obj['is_room'];
                $endpoint = $isRoom ? "realty/rooms/$objectId/" : "realty/units/$objectId/";
                $details = $manager->makeApiCall($endpoint);
                
                if ($details && !isset($details['error'])) {
                    $manager->saveObjectDetails($details);
                    echo json_encode(['success' => true, 'message' => "Объект $objectId обновлен"]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to get object details']);
                }
                break;
                
            case 'test':
                echo json_encode(['success' => true, 'message' => 'Test endpoint works']);
                break;
                
            case 'force_sync_data':
                // Принудительная синхронизация всех объектов
                error_log("Starting force_sync_data");
                
                // Увеличиваем лимиты времени выполнения
                set_time_limit(300); // 5 минут
                ini_set('max_execution_time', 300);
                
                // Отправляем заголовки для предотвращения таймаута браузера
                header('Content-Type: application/json');
                
                try {
                    $pdo = new PDO(
                        "mysql:host=" . Config::get('DB_HOST') . ";dbname=" . Config::get('DB_NAME') . ";charset=" . Config::get('DB_CHARSET'),
                        Config::get('DB_USER'),
                        Config::get('DB_PASS'),
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    
                    // Для тестирования синхронизируем только первые 100 объектов
                    $stmt = $pdo->query('SELECT alma_id, is_room FROM alma_objects ORDER BY alma_id LIMIT 100');
                    $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $updated = 0;
                    $errors = 0;
                    
                    foreach ($objects as $obj) {
                        $objectId = $obj['alma_id'];
                        $isRoom = (bool)$obj['is_room'];
                        
                        try {
                            $endpoint = $isRoom ? 'realty/rooms/' . $objectId . '/' : 'realty/units/' . $objectId . '/';
                            
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => Config::get('ALMA_API_URL') . $endpoint,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_HTTPHEADER => ['Api-Key: ' . Config::get('ALMA_API_KEY'), 'Accept: application/json'],
                                CURLOPT_TIMEOUT => 10,
                                CURLOPT_SSL_VERIFYPEER => false
                            ]);
                            
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($httpCode === 200) {
                                $apiData = json_decode($response, true);
                                
                                $updateStmt = $pdo->prepare('
                                    INSERT INTO alma_object_details (
                                        alma_id, project_name, building_name, status, is_archived, parent_unit_id, updated_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE
                                        project_name = VALUES(project_name),
                                        building_name = VALUES(building_name),
                                        status = VALUES(status),
                                        is_archived = VALUES(is_archived),
                                        parent_unit_id = VALUES(parent_unit_id),
                                        updated_at = NOW()
                                ');
                                
                                $projectName = $apiData['project']['name'] ?? null;
                                $buildingName = $apiData['building']['name'] ?? null;
                                $status = $apiData['status'] ?? 'unknown';
                                $isArchived = $apiData['is_archived'] ? 1 : 0;
                                $parentUnitId = $apiData['parent_unit']['id'] ?? null;
                                
                                $updateStmt->execute([
                                    $objectId,
                                    $projectName,
                                    $buildingName,
                                    $status,
                                    $isArchived,
                                    $parentUnitId
                                ]);
                                
                                $updated++;
                            } else {
                                $errors++;
                            }
                            
                            usleep(50000); // 50ms пауза
                            
                        } catch (Exception $e) {
                            $errors++;
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "Принудительная синхронизация завершена. Обновлено: {$updated}, ошибок: {$errors}",
                        'stats' => [
                            'total_processed' => count($objects),
                            'updated_objects' => $updated,
                            'errors' => $errors
                        ]
                    ]);
                    
                } catch (Exception $e) {
                    // Логируем ошибку
                    error_log("Force sync error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    
                    echo json_encode([
                        'success' => false,
                        'error' => 'Ошибка принудительной синхронизации: ' . $e->getMessage()
                    ]);
                }
                break;
                
            case 'get_bitrix_stats':
                // Получаем кэшированную статистику
                $result = $manager->getCachedBitrixStats();
                echo json_encode($result);
                break;
                
            case 'update_bitrix_stats':
                // Обновляем статистику из Bitrix и сохраняем в базу
                $result = $manager->updateBitrixStats();
                echo json_encode($result);
                break;
                
            case 'get_details':
                $objectId = $_POST['object_id'] ?? '';
                $isRoom = $_POST['is_room'] ?? false;
                
                if (empty($objectId)) {
                    echo json_encode(['success' => false, 'error' => 'Object ID required']);
                    break;
                }
                
                $details = $manager->getObjectDetails($objectId, $isRoom);
                echo json_encode($details);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        Logger::error("Action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alma Objects Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'alma-primary': '#667eea',
                        'alma-secondary': '#764ba2',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="bg-gray-50 font-sans">
    <div class="max-w-full mx-auto bg-white rounded-lg shadow-lg overflow-hidden" style="margin: 10px;">
        <!-- Header -->
        <div class="bg-gradient-to-r from-alma-primary to-alma-secondary text-white p-6 text-center">
            <h1 class="text-3xl font-light">🏢 Alma Objects Manager</h1>
        </div>
        
        <!-- Controls -->
        <div class="p-4 border-b border-gray-200 flex flex-wrap items-center gap-4">
            <button 
                class="main-btn w-12 h-12 rounded-lg transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center text-xl" 
                onclick="loadData()"
                id="loadBtn"
                title="Загрузить данные">
                📊
            </button>
            <button 
                class="main-btn w-12 h-12 rounded-lg transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center text-xl" 
                onclick="syncData()"
                id="syncBtn"
                title="Синхронизировать">
                🔄
            </button>
            
            <!-- Обновить один объект -->
            <div class="flex items-center gap-2 ml-4">
                <div class="text-lg">⚡</div>
                <input 
                    type="number" 
                    id="singleObjectId" 
                    placeholder="ID объекта" 
                    class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent w-24"
                >
                <button 
                    class="px-3 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors" 
                    onclick="updateSingleObject()"
                    id="updateSingleBtn">
                    Обновить
                </button>
            </div>
            
            <!-- Поиск -->
            <div class="flex items-center gap-2 ml-4">
                <div class="text-lg">🔍</div>
                <input 
                    type="text" 
                    id="searchInput" 
                    placeholder="Поиск по названию..." 
                    class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    oninput="handleSearch()"
                    style="min-width: 200px;">
                <button 
                    onclick="clearSearch()" 
                    class="text-gray-500 hover:text-gray-700 px-2 py-2 rounded"
                    title="Очистить поиск"
                    id="clearSearchBtn"
                    style="display: none;">
                    ✕
                </button>
            </div>
            
            <!-- Количество элементов на странице -->
            <div class="flex items-center gap-2 ml-4">
                <div class="text-sm text-gray-600">Показать:</div>
                <select 
                    id="itemsPerPageSelect" 
                    onchange="changeItemsPerPage()"
                    class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                    <option value="20">20</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                </select>
            </div>
            
            <span class="text-gray-600 italic hidden" id="loading">⏳ Загрузка...</span>
            
            <!-- Stats -->
            <div class="ml-auto flex gap-2 hidden" id="stats">
                <!-- Общая статистика -->
                <div class="stat-filter text-center p-2 bg-gray-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('all')" 
                     data-filter="all"
                     title="Показать все объекты">
                    <div class="text-lg mb-1">📊</div>
                    <div class="text-xl font-bold text-alma-primary" id="totalCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Всего</div>
                </div>
                
                <!-- По типам -->
                <div class="stat-filter text-center p-2 bg-blue-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('units')" 
                     data-filter="units"
                     title="Показать только юниты">
                    <div class="text-lg mb-1">🏢</div>
                    <div class="text-xl font-bold text-blue-600" id="apartmentCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Юниты</div>
                </div>
                <div class="stat-filter text-center p-2 bg-purple-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('rooms')" 
                     data-filter="rooms"
                     title="Показать только комнаты">
                    <div class="text-lg mb-1">🚪</div>
                    <div class="text-xl font-bold text-purple-600" id="roomCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Комнаты</div>
                </div>
                
                <!-- По проектам -->
                <div class="stat-filter text-center p-2 bg-red-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('Hong Kong')" 
                     data-filter="Hong Kong"
                     title="Показать объекты Гонконга">
                    <div class="text-lg mb-1">🏙️</div>
                    <div class="text-xl font-bold text-red-600" id="hongKongCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Гонконг</div>
                </div>
                <div class="stat-filter text-center p-2 bg-blue-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('Dubai')" 
                     data-filter="Dubai"
                     title="Показать объекты Дубая">
                    <div class="text-lg mb-1">🏜️</div>
                    <div class="text-xl font-bold text-blue-600" id="dubaiCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Дубай</div>
                </div>
                
                <!-- По статусам -->
                <div class="stat-filter text-center p-2 bg-green-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('available')" 
                     data-filter="available"
                     title="Показать доступные объекты">
                    <div class="text-lg mb-1">🟢</div>
                    <div class="text-xl font-bold text-green-600" id="availableCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Доступно</div>
                </div>
                <div class="stat-filter text-center p-2 bg-orange-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('rented')" 
                     data-filter="rented"
                     title="Показать сданные объекты">
                    <div class="text-lg mb-1">🟠</div>
                    <div class="text-xl font-bold text-orange-600" id="rentedCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Сдано</div>
                </div>
                <div class="stat-filter text-center p-2 bg-red-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('blocked')" 
                     data-filter="blocked"
                     title="Показать заблокированные объекты">
                    <div class="text-lg mb-1">🚫</div>
                    <div class="text-xl font-bold text-red-600" id="blockedCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Заблок.</div>
                </div>
                <div class="stat-filter text-center p-2 bg-gray-100 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                     onclick="applyStatFilter('archived')" 
                     data-filter="archived"
                     title="Показать архивные объекты">
                    <div class="text-lg mb-1">📦</div>
                    <div class="text-xl font-bold text-gray-600" id="archivedCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Архив</div>
                </div>
                <div class="text-center p-2 bg-yellow-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg stat-filter" 
                     onclick="applyStatFilter('invalid_id')" 
                     data-filter="invalid_id"
                     title="Показать объекты с неправильными external_id">
                    <div class="text-lg mb-1">⚠️</div>
                    <div class="text-xl font-bold text-yellow-600" id="invalidIdCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Неправ. ID</div>
                </div>
                <div class="text-center p-2 bg-red-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg stat-filter" 
                     onclick="applyStatFilter('status_mismatch')" 
                     data-filter="status_mismatch"
                     title="Показать объекты с несовпадающими статусами Alma и Bitrix">
                    <div class="text-lg mb-1">🔴</div>
                    <div class="text-xl font-bold text-red-600" id="statusMismatchCount">0</div>
                    <div class="text-xs text-gray-600 uppercase tracking-wide">Несовп. статус</div>
                </div>
            </div>
            
            <!-- Статистика Bitrix Dubai -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-3">📊 Статистика Bitrix24 Dubai</h3>
                <div class="flex flex-wrap gap-2">
                    <div class="text-center p-2 bg-blue-50 rounded-lg min-w-16">
                        <div class="text-lg mb-1">🏢</div>
                        <div class="text-xl font-bold text-blue-600" id="bitrixApartmentCount">-</div>
                        <div class="text-xs text-gray-600 uppercase tracking-wide">Юниты</div>
                    </div>
                    <div class="text-center p-2 bg-purple-50 rounded-lg min-w-16">
                        <div class="text-lg mb-1">🚪</div>
                        <div class="text-xl font-bold text-purple-600" id="bitrixRoomCount">-</div>
                        <div class="text-xs text-gray-600 uppercase tracking-wide">Комнаты</div>
                    </div>
                    <div class="text-center p-2 bg-gray-50 rounded-lg min-w-16">
                        <div class="text-lg mb-1">📋</div>
                        <div class="text-xl font-bold text-gray-600" id="bitrixTotalCount">-</div>
                        <div class="text-xs text-gray-600 uppercase tracking-wide">Всего</div>
                    </div>
                    <button 
                        class="text-center p-2 bg-green-50 rounded-lg min-w-16 cursor-pointer transition-all duration-300 hover:shadow-lg" 
                        onclick="loadBitrixStats()"
                        id="loadBitrixStatsBtn"
                        title="Загрузить статистику из Bitrix24 Dubai">
                        <div class="text-lg mb-1">🔄</div>
                        <div class="text-xs text-gray-600 uppercase tracking-wide">Обновить</div>
                    </button>
                </div>
                
                <!-- Отладочная информация -->
                <div id="bitrixDebugInfo" class="mt-3 p-3 bg-gray-50 rounded-lg text-xs text-gray-600 hidden">
                    <strong>Отладка:</strong>
                    <div id="bitrixDebugContent"></div>
                </div>
            </div>
        </div>
        
        <!-- Messages -->
        <div class="hidden mx-6 mt-4 p-4 rounded-lg" id="message"></div>
        
        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
            <div class="bg-white rounded-lg p-8 max-w-md mx-4 text-center shadow-2xl">
                <div class="mb-4">
                    <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                </div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2" id="loadingTitle">Синхронизация</h3>
                <p class="text-gray-600" id="loadingMessage">Обновляем данные из Alma...</p>
                <div class="mt-4 text-sm text-gray-500">
                    Пожалуйста, не закрывайте страницу
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div id="content">
            <div class="text-center py-16 text-gray-600">
                <div class="text-6xl mb-4">📊</div>
                <h3 class="text-xl font-medium mb-2">Нажмите "Загрузить данные"</h3>
                <p>для просмотра объектов из базы данных</p>
            </div>
        </div>
    </div>

    <script>
        let currentData = null;
        let syncTimeout = null; // Для debounce
        
        function showMessage(text, type = 'success') {
            const message = document.getElementById('message');
            message.textContent = text;
            
            if (type === 'success') {
                message.className = 'block mx-6 mt-4 p-4 rounded-lg bg-green-100 text-green-800 border border-green-200';
            } else if (type === 'warning') {
                message.className = 'block mx-6 mt-4 p-4 rounded-lg bg-yellow-100 text-yellow-800 border border-yellow-200';
            } else {
                message.className = 'block mx-6 mt-4 p-4 rounded-lg bg-red-100 text-red-800 border border-red-200';
            }
            
            setTimeout(() => {
                message.className = 'hidden mx-6 mt-4 p-4 rounded-lg';
            }, 8000);
        }
        
        function showLoading(show) {
            const loading = document.getElementById('loading');
            if (show) {
                loading.classList.remove('hidden');
                loading.classList.add('inline');
            } else {
                loading.classList.add('hidden');
                loading.classList.remove('inline');
            }
        }
        
        function showLoadingOverlay(show, title = 'Загрузка', message = 'Пожалуйста, подождите...') {
            const overlay = document.getElementById('loadingOverlay');
            const titleEl = document.getElementById('loadingTitle');
            const messageEl = document.getElementById('loadingMessage');
            
            if (show) {
                titleEl.textContent = title;
                messageEl.textContent = message;
                overlay.classList.remove('hidden');
                // Блокируем прокрутку страницы
                document.body.style.overflow = 'hidden';
            } else {
                overlay.classList.add('hidden');
                // Разблокируем прокрутку страницы
                document.body.style.overflow = '';
            }
        }
        
        function updateStats(data) {
            let totalCount = 0;
            let apartmentCount = 0;
            let roomCount = 0;
            let hongKongCount = 0;
            let dubaiCount = 0;
            let availableCount = 0;
            let rentedCount = 0;
            let blockedCount = 0;
            let archivedCount = 0;
            let invalidIdCount = 0;
            let statusMismatchCount = 0;
            
            Object.entries(data).forEach(([projectKey, objects]) => {
                objects.forEach(obj => {
                    totalCount++;
                    
                    // Подсчет по типам
                    if (obj.is_room === true || obj.is_room === 1) {
                        roomCount++;
                    } else {
                        apartmentCount++;
                    }
                    
                    // Подсчет по проектам
                    if (projectKey === 'Hong Kong') {
                        hongKongCount++;
                    } else if (projectKey === 'Dubai') {
                        dubaiCount++;
                    }
                    
                    // Подсчет по статусам
                    if (obj.is_archived === true || obj.is_archived === 1 || obj.is_archived === '1') {
                        archivedCount++;
                    } else {
                        const lowerCaseStatus = obj.status ? obj.status.toLowerCase() : '';
                        if (lowerCaseStatus === 'available') {
                            availableCount++;
                        } else if (lowerCaseStatus === 'rented') {
                            rentedCount++;
                        } else if (lowerCaseStatus === 'blocked') {
                            blockedCount++;
                        }
                    }
                    
                    // Подсчет неправильных external_id
                    if (obj.invalid_external_id) {
                        invalidIdCount++;
                    }
                    
                    // Подсчет несовпадающих статусов
                    if (obj.status_mismatch) {
                        statusMismatchCount++;
                    }
                });
            });
            
            document.getElementById('totalCount').textContent = totalCount;
            document.getElementById('apartmentCount').textContent = apartmentCount;
            document.getElementById('roomCount').textContent = roomCount;
            document.getElementById('hongKongCount').textContent = hongKongCount;
            document.getElementById('dubaiCount').textContent = dubaiCount;
            document.getElementById('availableCount').textContent = availableCount;
            document.getElementById('rentedCount').textContent = rentedCount;
            document.getElementById('blockedCount').textContent = blockedCount;
            document.getElementById('archivedCount').textContent = archivedCount;
            document.getElementById('invalidIdCount').textContent = invalidIdCount;
            document.getElementById('statusMismatchCount').textContent = statusMismatchCount;
            
            const stats = document.getElementById('stats');
            stats.classList.remove('hidden');
            stats.classList.add('flex');
        }
        
        async function loadData() {
            showLoadingOverlay(true, 'Загрузка данных', 'Получаем объекты из базы данных...');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=load_data'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    currentData = result.data;
                    renderData(result.data);
                    updateStats(result.data);
                    
                    if (result.message && result.message.trim()) {
                        showMessage(result.message);
                    }
                } else {
                    showMessage('Ошибка загрузки: ' + result.error, 'error');
                }
            } catch (error) {
                showMessage('Ошибка сети: ' + error.message, 'error');
            } finally {
                showLoadingOverlay(false);
            }
        }
        
        
        // Синхронизация с debounce (защита от случайных двойных кликов)
        function syncData() {
            // Очищаем предыдущий таймер
            if (syncTimeout) {
                clearTimeout(syncTimeout);
            }
            
            // Устанавливаем новый таймер на 500ms
            syncTimeout = setTimeout(async () => {
                await performSync();
            }, 500);
        }
        
        async function performSync() {
            showLoadingOverlay(true, 'Синхронизация данных', 'Обновляем измененные объекты из Alma API...');
            document.getElementById('syncBtn').disabled = true;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=sync_data'
                });
                
                // Проверяем статус ответа
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                // Получаем текст ответа для отладки
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                // Пытаемся распарсить JSON
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Response was:', responseText.substring(0, 500));
                    throw new Error('Сервер вернул некорректный ответ. Проверьте логи сервера.');
                }
                
                if (result.success) {
                    showMessage(result.message);
                    // Автоматически перезагружаем данные после синхронизации
                    setTimeout(() => {
                        loadData();
                    }, 1000);
                } else {
                    showMessage('Ошибка синхронизации: ' + result.error, 'error');
                }
            } catch (error) {
                showMessage('Ошибка сети: ' + error.message, 'error');
            } finally {
                showLoadingOverlay(false);
                document.getElementById('syncBtn').disabled = false;
            }
        }
        
        // Обновление одного объекта
        async function updateSingleObject() {
            const objectId = document.getElementById('singleObjectId').value;
            if (!objectId) {
                showMessage('Введите ID объекта', 'error');
                return;
            }
            
            document.getElementById('updateSingleBtn').disabled = true;
            showMessage('Обновляем объект ' + objectId + '...');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=update_single&object_id=' + objectId
                });

                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message);
                    // Перезагружаем данные
                    setTimeout(() => {
                        loadData();
                    }, 1000);
                } else {
                    showMessage('Ошибка: ' + result.error, 'error');
                }
            } catch (error) {
                showMessage('Ошибка сети: ' + error.message, 'error');
            } finally {
                document.getElementById('updateSingleBtn').disabled = false;
            }
        }
        
        // Загрузка кэшированной статистики Bitrix
        async function loadCachedBitrixStats() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_bitrix_stats'
                });

                const result = await response.json();
                
                if (result.success) {
                    const stats = result.data;
                    document.getElementById('bitrixApartmentCount').textContent = stats.apartments || 0;
                    document.getElementById('bitrixRoomCount').textContent = stats.rooms || 0;
                    document.getElementById('bitrixTotalCount').textContent = stats.total || 0;
                }
            } catch (error) {
                console.error('Error loading cached Bitrix stats:', error);
            }
        }
        
        // Обновление статистики Bitrix из API
        async function loadBitrixStats() {
            document.getElementById('loadBitrixStatsBtn').disabled = true;
            showMessage('Обновляем статистику из Bitrix24 Dubai... Это может занять несколько минут.');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=update_bitrix_stats'
                });

                const result = await response.json();
                
                if (result.success) {
                    const stats = result.data;
                    document.getElementById('bitrixApartmentCount').textContent = stats.apartments || 0;
                    document.getElementById('bitrixRoomCount').textContent = stats.rooms || 0;
                    document.getElementById('bitrixTotalCount').textContent = stats.total || 0;
                    
                    // Показываем отладочную информацию в отдельном блоке
                    if (result.debug && result.debug.roomDetails) {
                        let debugHtml = `Найдено <strong>${result.debug.roomRecords}</strong> записей с комнатами:<br>`;
                        result.debug.roomDetails.forEach(room => {
                            debugHtml += `• ID <strong>${room.id}</strong>: ${room.unitsCount} комнат<br>`;
                        });
                        debugHtml += `<br>Ожидалось: <strong>${result.debug.expected}</strong>, Получилось: <strong>${result.debug.actual}</strong>, Разница: <span class="text-red-600">+${result.debug.difference}</span>`;
                        
                        document.getElementById('bitrixDebugContent').innerHTML = debugHtml;
                        document.getElementById('bitrixDebugInfo').classList.remove('hidden');
                    } else {
                        document.getElementById('bitrixDebugInfo').classList.add('hidden');
                    }
                    
                    showMessage(result.message);
                } else {
                    showMessage('Ошибка обновления статистики: ' + result.error, 'error');
                }
            } catch (error) {
                showMessage('Ошибка сети: ' + error.message, 'error');
            } finally {
                document.getElementById('loadBitrixStatsBtn').disabled = false;
            }
        }
        
        let currentFilter = 'all';
        let currentTypeFilter = 'all';
        let currentStatusFilter = 'all';
        let currentSearchQuery = '';
        let currentSort = { column: 'id', direction: 'asc' };
        let currentPage = 1;
        let itemsPerPage = parseInt(localStorage.getItem('itemsPerPage')) || 50;
        let allData = {};
        
        function renderData(data) {
            allData = data;
            const content = document.getElementById('content');
            
            const projects = [
                { key: 'Hong Kong', name: 'Гонконг' },
                { key: 'Dubai', name: 'Дубай' },
                { key: 'Unknown', name: 'Неизвестный проект' }
            ];
            
            let html = `
                <div class="p-4">
                    
                    <!-- Общая таблица -->
                    <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm">
                        <div class="bg-gray-800 text-white p-4">
                            <h3 class="text-lg font-semibold">
                                ${currentFilter === 'all' ? 'Все объекты' : getProjectName(currentFilter)}
                                <span class="ml-2 text-sm opacity-75" id="filteredCount"></span>
                            </h3>
                        </div>
                        <div id="tableContainer">
                            ${renderFilteredTable()}
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
            updateFilteredCount();
            updateStatFilterStyles();
        }
        
        
        function getProjectName(key) {
            const names = {
                'Hong Kong': 'Гонконг',
                'Dubai': 'Дубай', 
                'West Tower': 'West Tower',
                'Unknown': 'Неизвестный проект'
            };
            return names[key] || key;
        }
        
        function applyStatFilter(filterValue) {
            // Сбрасываем все фильтры
            currentFilter = 'all';
            currentTypeFilter = 'all';
            currentStatusFilter = 'all';
            currentSearchQuery = '';
            currentPage = 1;
            
            // Очищаем поле поиска
            document.getElementById('searchInput').value = '';
            document.getElementById('clearSearchBtn').style.display = 'none';
            
            // Применяем выбранный фильтр
            if (filterValue === 'all') {
                // Показать все
            } else if (filterValue === 'units') {
                currentTypeFilter = 'units';
            } else if (filterValue === 'rooms') {
                currentTypeFilter = 'rooms';
            } else if (filterValue === 'Hong Kong' || filterValue === 'Dubai') {
                currentFilter = filterValue;
            } else if (['available', 'rented', 'blocked', 'archived'].includes(filterValue)) {
                currentStatusFilter = filterValue;
            } else if (filterValue === 'invalid_id') {
                // Специальный фильтр для неправильных external_id
                currentFilter = 'invalid_id';
            } else if (filterValue === 'status_mismatch') {
                // Специальный фильтр для несовпадающих статусов
                currentFilter = 'status_mismatch';
            }
            
            // Обновляем визуальное состояние фильтров
            updateStatFilterStyles();
            
            // Обновляем таблицу
            document.getElementById('tableContainer').innerHTML = renderFilteredTable();
            updateFilteredCount();
        }
        
        function updateStatFilterStyles() {
            // Убираем активное состояние со всех фильтров
            document.querySelectorAll('.stat-filter').forEach(filter => {
                filter.classList.remove('active');
            });
            
            // Добавляем активное состояние к текущему фильтру
            let activeFilter = 'all';
            if (currentTypeFilter !== 'all') {
                activeFilter = currentTypeFilter;
            } else if (currentFilter !== 'all') {
                activeFilter = currentFilter;
            } else if (currentStatusFilter !== 'all') {
                activeFilter = currentStatusFilter;
            }
            
            const activeElement = document.querySelector(`[data-filter="${activeFilter}"]`);
            if (activeElement) {
                activeElement.classList.add('active');
            }
        }
        
        let searchTimeout;
        function handleSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchInput = document.getElementById('searchInput');
                const clearBtn = document.getElementById('clearSearchBtn');
                
                currentSearchQuery = searchInput.value.trim();
                currentPage = 1; // Сбрасываем на первую страницу
                
                // Показываем/скрываем кнопку очистки
                if (currentSearchQuery) {
                    clearBtn.style.display = 'block';
                } else {
                    clearBtn.style.display = 'none';
                }
                
                // Обновляем таблицу
                document.getElementById('tableContainer').innerHTML = renderFilteredTable();
                updateFilteredCount();
                updateStatFilterStyles();
            }, 300); // Debounce 300ms
        }
        
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('clearSearchBtn').style.display = 'none';
            currentSearchQuery = '';
            currentPage = 1;
            
            // Обновляем таблицу
            document.getElementById('tableContainer').innerHTML = renderFilteredTable();
            updateFilteredCount();
            updateStatFilterStyles();
        }
        
        function renderFilteredTable() {
            let objects = [];
            
            if (currentFilter === 'all') {
                // Показываем все объекты
                Object.values(allData).forEach(projectObjects => {
                    objects = objects.concat(projectObjects);
                });
            } else if (currentFilter === 'invalid_id') {
                // Показываем только объекты с неправильными external_id
                Object.values(allData).forEach(projectObjects => {
                    projectObjects.forEach(obj => {
                        if (obj.invalid_external_id) {
                            objects.push(obj);
                        }
                    });
                });
            } else if (currentFilter === 'status_mismatch') {
                // Показываем только объекты с несовпадающими статусами
                Object.values(allData).forEach(projectObjects => {
                    projectObjects.forEach(obj => {
                        if (obj.status_mismatch) {
                            objects.push(obj);
                        }
                    });
                });
            } else {
                // Показываем только выбранный проект
                objects = allData[currentFilter] || [];
            }
            
            // Применяем фильтр по типу
            if (currentTypeFilter !== 'all') {
                objects = objects.filter(obj => {
                    if (currentTypeFilter === 'rooms') {
                        return obj.is_room === true || obj.is_room === 1;
                    } else if (currentTypeFilter === 'units') {
                        return obj.is_room === false || obj.is_room === 0;
                    }
                    return true;
                });
            }
            
            // Применяем фильтр по статусу
            if (currentStatusFilter !== 'all') {
                objects = objects.filter(obj => {
                    if (currentStatusFilter === 'archived') {
                        return obj.is_archived === true || obj.is_archived === 1 || obj.is_archived === '1';
                    } else {
                        return obj.status && obj.status.toLowerCase() === currentStatusFilter;
                    }
                });
            }
            
            // Применяем поиск по названию
            if (currentSearchQuery) {
                objects = objects.filter(obj => {
                    const searchText = currentSearchQuery.toLowerCase();
                    const objectName = (obj.name || '').toLowerCase();
                    const objectHeader = (obj.header || '').toLowerCase();
                    
                    return objectName.includes(searchText) || objectHeader.includes(searchText);
                });
            }
            
            // Применяем поиск по названию
            if (currentSearchQuery) {
                objects = objects.filter(obj => {
                    const searchText = currentSearchQuery.toLowerCase();
                    const objectName = (obj.name || '').toLowerCase();
                    const objectHeader = (obj.header || '').toLowerCase();
                    
                    return objectName.includes(searchText) || objectHeader.includes(searchText);
                });
            }
            
            // Применяем сортировку
            objects = sortObjects(objects);
            
            // Применяем пагинацию
            const totalItems = objects.length;
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedObjects = objects.slice(startIndex, endIndex);
            
            return renderTable(paginatedObjects) + renderPagination(totalItems);
        }
        
        function updateFilteredCount() {
            let objects = [];
            
            if (currentFilter === 'all') {
                Object.values(allData).forEach(projectObjects => {
                    objects = objects.concat(projectObjects);
                });
            } else if (currentFilter === 'invalid_id') {
                // Подсчитываем только объекты с неправильными external_id
                Object.values(allData).forEach(projectObjects => {
                    projectObjects.forEach(obj => {
                        if (obj.invalid_external_id) {
                            objects.push(obj);
                        }
                    });
                });
            } else if (currentFilter === 'status_mismatch') {
                // Подсчитываем только объекты с несовпадающими статусами
                Object.values(allData).forEach(projectObjects => {
                    projectObjects.forEach(obj => {
                        if (obj.status_mismatch) {
                            objects.push(obj);
                        }
                    });
                });
            } else {
                objects = allData[currentFilter] || [];
            }
            
            // Применяем фильтр по типу
            if (currentTypeFilter !== 'all') {
                objects = objects.filter(obj => {
                    if (currentTypeFilter === 'rooms') {
                        return obj.is_room === true || obj.is_room === 1;
                    } else if (currentTypeFilter === 'units') {
                        return obj.is_room === false || obj.is_room === 0;
                    }
                    return true;
                });
            }
            
            const countElement = document.getElementById('filteredCount');
            if (countElement) {
                countElement.textContent = `(${objects.length} объектов)`;
            }
        }
        
        function getSortIcon(column) {
            if (currentSort.column === column) {
                return currentSort.direction === 'asc' ? '↑' : '↓';
            }
            return '↕️';
        }
        
        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }
            
            // Обновляем таблицу
            document.getElementById('tableContainer').innerHTML = renderFilteredTable();
            updateFilteredCount();
        }
        
        function sortObjects(objects) {
            return objects.sort((a, b) => {
                let aVal, bVal;
                
                switch (currentSort.column) {
                    case 'id':
                        aVal = parseInt(a.alma_id || a.id) || 0;
                        bVal = parseInt(b.alma_id || b.id) || 0;
                        break;
                    case 'name':
                        aVal = (a.name || '').toLowerCase();
                        bVal = (b.name || '').toLowerCase();
                        break;
                    case 'type':
                        aVal = a.is_room ? 'комната' : 'юнит';
                        bVal = b.is_room ? 'комната' : 'юнит';
                        break;
                    case 'project':
                        // Нормализуем названия проектов для сортировки
                        let aProject = (a.project_name || 'неизвестно').toLowerCase();
                        let bProject = (b.project_name || 'неизвестно').toLowerCase();
                        
                        // Downtown Towers и West Tower считаем как Dubai для сортировки
                        if (aProject.includes('downtown') && aProject.includes('towers')) aProject = 'dubai';
                        if (bProject.includes('downtown') && bProject.includes('towers')) bProject = 'dubai';
                        if (aProject.includes('west') && aProject.includes('tower')) aProject = 'dubai';
                        if (bProject.includes('west') && bProject.includes('tower')) bProject = 'dubai';
                        
                        aVal = aProject;
                        bVal = bProject;
                        break;
                    case 'status':
                        aVal = a.status || 'неизвестно';
                        bVal = b.status || 'неизвестно';
                        break;
                    case 'external_id':
                        aVal = (a.external_id || '').toLowerCase();
                        bVal = (b.external_id || '').toLowerCase();
                        break;
                    default:
                        return 0;
                }
                
                if (currentSort.direction === 'asc') {
                    return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
                } else {
                    return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
                }
            });
        }
        
        function renderTable(objects) {
            if (objects.length === 0) {
                return '<div class="text-center py-10 text-gray-500">Нет объектов для отображения</div>';
            }
            
            let html = '<div class="overflow-x-auto"><table class="w-full border-collapse">';
            html += `
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('id')">
                            ID ${getSortIcon('id')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('name')">
                            Название ${getSortIcon('name')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('type')">
                            Тип ${getSortIcon('type')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('project')">
                            Проект ${getSortIcon('project')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('status')">
                            Статус ${getSortIcon('status')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b cursor-pointer hover:bg-gray-100" onclick="sortTable('external_id')">
                            External ID ${getSortIcon('external_id')}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b">Bitrix</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 border-b">Действия</th>
                    </tr>
                </thead>
                <tbody>
            `;
            
            objects.forEach(obj => {
                const typeBadge = obj.is_room ? 
                    '<span class="inline-block px-2 py-1 text-xs font-bold text-blue-800 bg-blue-100 rounded-full uppercase">🚪 Комната</span>' : 
                    '<span class="inline-block px-2 py-1 text-xs font-bold text-purple-800 bg-purple-100 rounded-full uppercase">🏢 Юнит</span>';
                
                let statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-gray-600 bg-gray-100 rounded-full uppercase">-</span>';
                
                if (obj.status) {
                    const status = obj.status.toLowerCase();
                    if (obj.is_archived === true || obj.is_archived === 1 || obj.is_archived === '1') {
                        statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-red-800 bg-red-100 rounded-full uppercase">📦 Архив</span>';
                    } else if (status === 'rented') {
                        statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-orange-800 bg-orange-100 rounded-full uppercase">🟠 Сдано</span>';
                    } else if (status === 'available') {
                        statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-green-800 bg-green-100 rounded-full uppercase">🟢 Доступно</span>';
                    } else if (status === 'blocked') {
                        statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-red-600 bg-red-50 rounded-full uppercase">🚫 Заблокирован</span>';
                    } else if (obj.status.trim() !== '') {
                        // Показываем любой другой статус как есть
                        statusBadge = `<span class="inline-block px-2 py-1 text-xs font-bold text-blue-800 bg-blue-100 rounded-full uppercase">${obj.status}</span>`;
                    }
                } else if (obj.is_archived === true || obj.is_archived === 1 || obj.is_archived === '1') {
                    statusBadge = '<span class="inline-block px-2 py-1 text-xs font-bold text-red-800 bg-red-100 rounded-full uppercase">📦 Архив</span>';
                }
                
                const projectName = obj.project_name || 'Неизвестно';
                let projectBadge = '<span class="inline-block px-2 py-1 text-xs text-gray-600 bg-gray-100 rounded">Неизвестно</span>';
                
                if (projectName.toLowerCase().includes('hong') || projectName.toLowerCase().includes('kong')) {
                    projectBadge = '<span class="inline-block px-2 py-1 text-xs text-red-800 bg-red-100 rounded">Гонконг</span>';
                } else if (projectName.toLowerCase().includes('dubai') || 
                          (projectName.toLowerCase().includes('downtown') && projectName.toLowerCase().includes('towers')) ||
                          (projectName.toLowerCase().includes('west') && projectName.toLowerCase().includes('tower'))) {
                    projectBadge = '<span class="inline-block px-2 py-1 text-xs text-blue-800 bg-blue-100 rounded">Дубай</span>';
                } else if (projectName !== 'Неизвестно') {
                    projectBadge = `<span class="inline-block px-2 py-1 text-xs text-gray-800 bg-gray-100 rounded">${projectName}</span>`;
                }
                
                // Создаем ссылку на объект в Alma
                const objectId = obj.alma_id || obj.id;
                const objectType = obj.is_room ? 'rooms' : 'units';
                const almaUrl = `https://colife.alma-app.com/${objectType}/${objectId}`;
                // Для комнат используем header, для юнитов - name
                const displayName = obj.is_room ? (obj.header || obj.name || '-') : (obj.name || '-');
                const nameLink = `<a href="${almaUrl}" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline font-medium" title="Открыть в Alma">${displayName} <span class="text-xs opacity-60">🔗</span></a>`;
                
                // Информация из Bitrix
                let bitrixInfo = '';
                if (obj.bitrix_status === 'found') {
                    bitrixInfo = '<span class="inline-block px-2 py-1 text-xs font-bold text-green-800 bg-green-100 rounded-full">✓ Найден</span>';
                    
                    // Показываем stage для объектов Дубая
                    if (obj.bitrix_stage && projectBadge.includes('Дубай')) {
                        const stageColors = {
                            'Available': 'text-green-600',
                            'Rented': 'text-orange-600', 
                            'Blocked': 'text-red-600',
                            'Ex-Apartments': 'text-gray-600'
                        };
                        const stageColor = stageColors[obj.bitrix_stage] || 'text-gray-600';
                        bitrixInfo += `<br><small class="${stageColor}"><strong>Stage:</strong> ${obj.bitrix_stage}</small>`;
                    }
                    
                    if (obj.bitrix_apartment_name && obj.bitrix_apartment_id) {
                        // Определяем базовый URL Bitrix в зависимости от проекта
                        const bitrixBaseUrl = projectName === 'Hong Kong' 
                            ? 'https://colifepacific.bitrix24.com' 
                            : 'https://colifeae.bitrix24.eu';
                        const apartmentUrl = `${bitrixBaseUrl}/crm/type/144/details/${obj.bitrix_apartment_id}/`;
                        bitrixInfo += `<br><small class="text-gray-600"><a href="${apartmentUrl}" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline" title="Открыть в Bitrix24">${obj.bitrix_apartment_name} 🔗</a></small>`;
                    } else if (obj.bitrix_apartment_name) {
                        bitrixInfo += `<br><small class="text-gray-600">${obj.bitrix_apartment_name}</small>`;
                    }
                    
                    if (obj.bitrix_unit_name && obj.bitrix_unit_id) {
                        // Определяем базовый URL Bitrix в зависимости от проекта
                        const bitrixBaseUrl = projectName === 'Hong Kong' 
                            ? 'https://colifepacific.bitrix24.com' 
                            : 'https://colifeae.bitrix24.eu';
                        const unitUrl = `${bitrixBaseUrl}/crm/type/167/details/${obj.bitrix_unit_id}/`;
                        bitrixInfo += `<br><small class="text-blue-600">Юнит: <a href="${unitUrl}" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline" title="Открыть в Bitrix24">${obj.bitrix_unit_name} 🔗</a></small>`;
                    } else if (obj.bitrix_unit_name) {
                        bitrixInfo += `<br><small class="text-blue-600">Юнит: ${obj.bitrix_unit_name}</small>`;
                    }
                    
                    if (obj.bitrix_rent_type) {
                        const rentTypeText = obj.bitrix_rent_type === 'rooms' ? 'Шеринг' : 'Целиком';
                        bitrixInfo += `<br><small class="text-purple-600">${rentTypeText}</small>`;
                    }
                    
                } else if (obj.bitrix_status === 'not_found') {
                    bitrixInfo = '<span class="inline-block px-2 py-1 text-xs font-bold text-orange-800 bg-orange-100 rounded-full">⚠️ Не найден</span>';
                } else if (obj.bitrix_status === 'error') {
                    bitrixInfo = '<span class="inline-block px-2 py-1 text-xs font-bold text-red-800 bg-red-100 rounded-full">❌ Ошибка</span>';
                    if (obj.bitrix_error) {
                        bitrixInfo += `<br><small class="text-red-600">${obj.bitrix_error}</small>`;
                    }
                } else {
                    bitrixInfo = '<span class="text-gray-400 text-xs">Нет данных</span>';
                }
                
                // Добавляем предупреждение о несоответствии статусов
                if (obj.status_mismatch) {
                    bitrixInfo += '<br><span class="inline-block px-1 py-0.5 text-xs font-bold text-red-800 bg-red-200 rounded">⚠️ Несоответствие</span>';
                }

                // Определяем класс подсветки строки
                let rowClass = 'hover:bg-gray-50 transition-colors';
                if (obj.invalid_external_id) {
                    rowClass += ' bg-yellow-50 border-l-4 border-yellow-400'; // Желтая подсветка для неправильных external_id
                } else if (obj.status_mismatch) {
                    rowClass += ' bg-red-50 border-l-4 border-red-400'; // Красная подсветка для несоответствия статусов
                }

                html += `
                    <tr class="${rowClass}">
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${objectId}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${nameLink}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${typeBadge}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${projectBadge}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${statusBadge}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">
                            ${obj.external_id || '-'}
                            ${obj.invalid_external_id ? '<br><span class="inline-block px-1 py-0.5 text-xs font-bold text-yellow-800 bg-yellow-200 rounded">⚠️ Неправильный ID</span>' : ''}
                        </td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">${bitrixInfo}</td>
                        <td class="px-4 py-3 text-sm border-b border-gray-200">
                            <button onclick="loadObjectDetail(${objectId}, ${obj.is_room})" 
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs">
                                📄 Детали
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            return html;
        }
        
        async function loadObjectDetail(objectId, isRoom) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_details&object_id=${objectId}&is_room=${isRoom ? 1 : 0}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showObjectDetailsModal(result.data);
                } else {
                    showMessage('Ошибка загрузки деталей: ' + result.error, 'error');
                }
            } catch (error) {
                showMessage('Ошибка сети: ' + error.message, 'error');
            }
        }
        
        function showObjectDetailsModal(details) {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 max-h-96 overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">📄 Детали объекта: ${details.name}</h3>
                        <button onclick="this.closest('.fixed').remove()" class="text-gray-500 hover:text-gray-700">✕</button>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div><strong>ID:</strong> ${details.id}</div>
                        <div><strong>External ID:</strong> ${details.external_id || '-'}</div>
                        <div><strong>Проект:</strong> ${details.project?.name || '-'}</div>
                        <div><strong>Здание:</strong> ${details.building?.name || '-'}</div>
                        <div><strong>Статус:</strong> ${details.status || '-'}</div>
                        <div><strong>Архив:</strong> ${details.is_archived ? 'Да' : 'Нет'}</div>
                        <div><strong>Площадь:</strong> ${details.internal_area || 0} м²</div>
                        <div><strong>Цена аренды:</strong> ${details.goal_rent_cost || 0} AED</div>
                        <div><strong>Спальни:</strong> ${details.number_of_bedrooms || 0}</div>
                        <div><strong>Ванные:</strong> ${details.number_of_baths || 0}</div>
                        <div><strong>Этаж:</strong> ${details.floor || '-'}</div>
                        <div><strong>Адрес:</strong> ${details.address || '-'}</div>
                    </div>
                    <div class="mt-4">
                        <button onclick="this.closest('.fixed').remove()" 
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">
                            Закрыть
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        function renderPagination(totalItems) {
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            if (totalPages <= 1) return '';
            
            let html = '<div class="flex justify-between items-center mt-6 p-4 bg-gray-50 rounded-lg">';
            
            // Информация о текущих результатах
            const startItem = (currentPage - 1) * itemsPerPage + 1;
            const endItem = Math.min(currentPage * itemsPerPage, totalItems);
            html += `<div class="text-sm text-gray-600">Показано ${startItem}-${endItem} из ${totalItems} объектов</div>`;
            
            // Кнопки пагинации
            html += '<div class="flex gap-2">';
            
            // Предыдущая страница
            if (currentPage > 1) {
                html += `<button onclick="changePage(${currentPage - 1})" class="px-3 py-1 bg-white border rounded hover:bg-gray-100">← Назад</button>`;
            }
            
            // Номера страниц
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const isActive = i === currentPage;
                const btnClass = isActive 
                    ? 'px-3 py-1 bg-blue-500 text-white rounded' 
                    : 'px-3 py-1 bg-white border rounded hover:bg-gray-100';
                html += `<button onclick="changePage(${i})" class="${btnClass}">${i}</button>`;
            }
            
            // Следующая страница
            if (currentPage < totalPages) {
                html += `<button onclick="changePage(${currentPage + 1})" class="px-3 py-1 bg-white border rounded hover:bg-gray-100">Вперед →</button>`;
            }
            
            html += '</div></div>';
            
            return html;
        }
        
        function changePage(page) {
            currentPage = page;
            document.getElementById('tableContainer').innerHTML = renderFilteredTable();
            updateFilteredCount();
            updateStatFilterStyles();
            
            // Прокручиваем наверх страницы
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPageSelect');
            itemsPerPage = parseInt(select.value);
            
            // Сохраняем в localStorage
            localStorage.setItem('itemsPerPage', itemsPerPage);
            
            // Сбрасываем на первую страницу
            currentPage = 1;
            
            // Обновляем таблицу
            document.getElementById('tableContainer').innerHTML = renderFilteredTable();
            updateFilteredCount();
            updateStatFilterStyles();
        }
        
        // Автозагрузка при открытии страницы
        document.addEventListener('DOMContentLoaded', function() {
            // Устанавливаем сохраненное значение в селектор
            const select = document.getElementById('itemsPerPageSelect');
            if (select) {
                select.value = itemsPerPage;
            }
            
            loadData();
            loadCachedBitrixStats(); // Загружаем кэшированную статистику Bitrix
        });
    </script>
</body>
</html>
