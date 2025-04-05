<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    session_start();
}

include 'logins.php';

// Определяем URL API
define('API_URL_ACCESS_TOKEN', 'https://api-eu.syrve.live/api/1/access_token');
define('API_URL_ORGANIZATIONS', 'https://api-eu.syrve.live/api/1/organizations');
define('API_URL_ORDERS_BY_PHONE', 'https://api-eu.syrve.live/api/1/deliveries/by_delivery_date_and_phone');

/**
 * Массив переводов для ключевых полей.
 * Можно дополнять при необходимости.
 */
$fieldTranslations = [
    'phone'                => 'Номер телефона',
    'deliveryPoint'        => 'Точка доставки',
    'status'               => 'Статус',
    'courierInfo'          => 'Информация о курьере',
    'completeBefore'       => 'Должен быть выполнен до',
    'whenCreated'          => 'Когда создан',
    'whenConfirmed'        => 'Когда подтверждён',
    'whenPrinted'          => 'Когда напечатан',
    'whenCookingCompleted' => 'Когда приготовлен',
    'whenSended'           => 'Когда отправлен',
    'whenDelivered'        => 'Когда доставлен',
    'comment'              => 'Комментарий',
    'problem'              => 'Проблема',
    'operator'             => 'Оператор',
    'marketingSource'      => 'Источник рекламы',
    'deliveryDuration'     => 'Длительность доставки (мин)',
    'cookingStartTime'     => 'Время начала приготовления',
    'isDeleted'            => 'Удалён?',
    'sum'                  => 'Сумма заказа',
    'number'               => 'Номер заказа',
    'sourceKey'            => 'Источник',
    'whenBillPrinted'      => 'Когда счёт напечатан',
    'whenClosed'           => 'Когда заказ закрыт',
    'conception'           => 'Концепция',
    'guestsInfo'           => 'Информация о гостях',
    'items'                => 'Позиции заказа',
    'payments'             => 'Оплаты',
    'discounts'            => 'Скидки',
    'orderType'            => 'Тип заказа',
    'terminalGroupId'      => 'Терминальная группа',
    'processedPaymentsSum' => 'Сумма принятых оплат',
    'loyaltyInfo'          => 'Информация о лояльности',
    'customer'             => 'Покупатель',
];

/**
 * Helper function для безопасного вывода значений
 */
function safe($value) {
    if (is_null($value)) {
        return '';
    }
    // Строка
    if (is_string($value)) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    // Массив или любой другой тип
    return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

/**
 * Функция рендеринга (с учётом переводов и вложенных структур)
 */
function renderOrderDetails(array $order, array $fieldTranslations) {
    $html = '<table class="table table-bordered table-sm">';
    $html .= '<thead><tr><th style="width: 30%;">Параметр</th><th>Значение</th></tr></thead><tbody>';

    foreach ($order as $key => $value) {
        // Пропускаем пустые значения
        if ($value === null || $value === '') {
            continue;
        }

        // Переводим название поля, если оно есть в словаре
        $translatedKey = $fieldTranslations[$key] ?? $key;

        $html .= '<tr>';
        $html .= '<td>' . safe($translatedKey) . '</td>';
        $html .= '<td>' . renderValue($value, $fieldTranslations) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

/**
 * Универсальная функция для рекурсивного рендеринга значений:
 * - Если значение массив => строим вложенную таблицу
 * - Если значение JSON-строка => парсим и рендерим рекурсивно
 * - Иначе => выводим как текст
 */
function renderValue($value, array $fieldTranslations) {
    // Если это массив — рендерим вложенную таблицу
    if (is_array($value)) {
        // Массив может быть как ассоциативным (ключ=>значение), так и индексным (список).
        // Определим, как лучше выводить: если все ключи — числовые, считаем, что это список.
        $keys = array_keys($value);
        $isList = count(array_filter($keys, 'is_string')) === 0 ? true : false;

        // Если это список
        if ($isList) {
            // Просто выводим каждый элемент в свою строку (или строим подтаблицы)
            $html = '<ul class="list-group list-group-flush">';
            foreach ($value as $item) {
                $html .= '<li class="list-group-item">' . renderValue($item, $fieldTranslations) . '</li>';
            }
            $html .= '</ul>';
            return $html;
        } else {
            // Ассоциативный массив: делаем таблицу
            return renderOrderDetails($value, $fieldTranslations);
        }
    }

    // Если это строка и она является валидным JSON => рендерим как массив
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return renderValue($decoded, $fieldTranslations);
        } else {
            // Просто строка
            return safe($value);
        }
    }

    // Прочие типы (числа и т.д.)
    return safe($value);
}

/**
 * Функция для получения токена по API‑логину
 */
function getApiToken($apiLogin) {
    $requestData = json_encode(['apiLogin' => $apiLogin]);
    $ch = curl_init(API_URL_ACCESS_TOKEN);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        return null;
    }
    curl_close($ch);
    $data = json_decode($result, true);
    return $data['token'] ?? null;
}

/**
 * Функция для получения организаций
 */
function getOrganizations($token, $organizationIds = null, $returnAdditionalInfo = true, $includeDisabled = true, $returnExternalData = null) {
    $requestData = [
        'organizationIds' => $organizationIds,
        'returnAdditionalInfo' => $returnAdditionalInfo,
        'includeDisabled' => $includeDisabled,
        'returnExternalData' => $returnExternalData
    ];
    $ch = curl_init(API_URL_ORGANIZATIONS);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        return null;
    }
    curl_close($ch);
    $data = json_decode($result, true);
    return $data['organizations'] ?? [];
}

/**
 * Функция для поиска заказов по номеру телефона и дате
 */
function searchOrders($token, $phone, $deliveryDateFrom, $deliveryDateTo, $organizationIds, $startRevision = 0, $sourceKeys = null, $rowsCount = null) {
    $requestData = [
        'phone' => $phone,
        'deliveryDateFrom' => $deliveryDateFrom,
        'deliveryDateTo' => $deliveryDateTo,
        'organizationIds' => $organizationIds,
        'startRevision' => $startRevision,
        'sourceKeys' => $sourceKeys,
        'rowsCount' => $rowsCount
    ];
    $ch = curl_init(API_URL_ORDERS_BY_PHONE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        return null;
    }
    curl_close($ch);
    return json_decode($result, true);
}

/**
 * Форматирование даты для API (формат: гггг-ММ-дд ЧЧ:мм:сс.ффф)
 */
function formatDateForApi($date) {
    $dt = new DateTime($date);
    return $dt->format('Y-m-d H:i:s.v');
}

// Определяем шаг работы приложения (login, search, results)
$step = $_GET['step'] ?? 'login';

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'login') {
        // Обработка выбора API‑логина
        $selectedApiLogin = $_POST['apiLogin'] ?? '';
        if ($selectedApiLogin && array_key_exists($selectedApiLogin, $apiLogins)) {
            $token = getApiToken($selectedApiLogin);
            if ($token) {
                $_SESSION['token'] = $token;
                $_SESSION['apiLogin'] = $selectedApiLogin;
                header("Location: ?step=search");
                exit;
            } else {
                $error = "Не удалось получить токен по выбранному API логину.";
            }
        } else {
            $error = "Выберите корректный API логин.";
        }
    } elseif ($step === 'search') {
        // Обработка формы поиска
        $phone           = $_POST['phone']           ?? '';
        $deliveryDateFrom= $_POST['deliveryDateFrom']?? '';
        $deliveryDateTo  = $_POST['deliveryDateTo']  ?? '';
        $organizationId  = $_POST['organizationId']  ?? '';
        
        if (empty($phone) || empty($deliveryDateFrom) || empty($deliveryDateTo) || empty($organizationId)) {
            $error = "Пожалуйста, заполните все поля.";
        } else {
            $deliveryDateFromFormatted = formatDateForApi($deliveryDateFrom);
            $deliveryDateToFormatted   = formatDateForApi($deliveryDateTo);
            $token                     = $_SESSION['token'] ?? '';
            if (!$token) {
                $error = "Сессия истекла. Повторите вход.";
            } else {
                $ordersData = searchOrders($token, $phone, $deliveryDateFromFormatted, $deliveryDateToFormatted, [$organizationId]);
                $_SESSION['ordersData'] = $ordersData;
                header("Location: ?step=results");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Поиск заказов</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <style>
        body {
            padding-top: 20px;
        }
        .container {
            max-width: 900px;
        }
        .order-details {
            margin-top: 20px;
        }
        /* Ограничиваем высоту модального окна, чтобы текст не вылезал за границы */
        .modal-body {
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 70vh;
            overflow-y: auto;
        }
        /* Для вложенных таблиц */
        .table-nested {
            background-color: #f9f9f9;
            margin-bottom: 0;
        }
    </style>
</head>
<body>
<div class="container">
    <h1 class="mb-4">Поиск заказов</h1>
    <!-- Кнопка для смены API логина -->
    <?php if ($step !== 'login'): ?>
        <a href="?logout=1" class="btn btn-warning mb-3">Сменить API логин</a>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>
    
    <?php if ($step === 'login'): ?>
        <!-- Шаг 1: выбор заведения (API‑логина) -->
        <form method="POST" action="?step=login">
            <div class="form-group">
                <label for="apiLogin">Выберите заведение (API логин):</label>
                <select name="apiLogin" id="apiLogin" class="form-control">
                    <option value="">-- Выберите заведение --</option>
                    <?php foreach ($apiLogins as $key => $name): ?>
                        <option value="<?= safe($key) ?>"><?= safe($name) ?> (<?= safe($key) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Получить токен и продолжить</button>
        </form>
        
    <?php elseif ($step === 'search'): 
        // Шаг 2: Форма поиска заказов
        $token = $_SESSION['token'] ?? '';
        $organizations = getOrganizations($token);
        ?>
        <form method="POST" action="?step=search">
            <div class="form-group">
                <label for="organizationId">Выберите организацию:</label>
                <select name="organizationId" id="organizationId" class="form-control">
                    <option value="">-- Выберите организацию --</option>
                    <?php if ($organizations): ?>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= safe($org['id']) ?>"><?= safe($org['name']) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">Организации не найдены</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="phone">Номер телефона:</label>
                <input type="text" name="phone" id="phone" class="form-control" placeholder="+48123456789" required>
            </div>
            <div class="form-group">
                <label for="deliveryDateFrom">Дата доставки (с):</label>
                <input type="datetime-local" name="deliveryDateFrom" id="deliveryDateFrom" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="deliveryDateTo">Дата доставки (до):</label>
                <input type="datetime-local" name="deliveryDateTo" id="deliveryDateTo" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Найти заказ</button>
        </form>
        
    <?php elseif ($step === 'results'): 
        // Шаг 3: Отображение результатов поиска
        $ordersData = $_SESSION['ordersData'] ?? null;
        ?>
        <h2>Результаты поиска</h2>
        <?php if ($ordersData): ?>
            <?php if (!empty($ordersData['ordersByOrganizations'])): ?>
                <?php foreach ($ordersData['ordersByOrganizations'] as $orgOrders): ?>
                    <div class="order-details card mb-3">
                        <div class="card-header">
                            Организация: <?= safe($orgOrders['organizationId']) ?>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($orgOrders['orders'])): ?>
                                <?php foreach ($orgOrders['orders'] as $order): ?>
                                    <div class="mb-3">
                                        <h5>Заказ ID: <?= safe($order['id']) ?></h5>
                                        <p><strong>POS ID:</strong> <?= safe($order['posId'] ?? 'N/A') ?></p>
                                        <p><strong>Внешний номер:</strong> <?= safe($order['externalNumber'] ?? 'N/A') ?></p>
                                        <p><strong>Статус создания:</strong> <?= safe($order['creationStatus']) ?></p>
                                        <p><strong>Временная метка:</strong> <?= safe($order['timestamp']) ?></p>
                                        
                                        <!-- Кнопка для отображения подробных данных заказа в модальном окне -->
                                        <button class="btn btn-info" type="button" data-toggle="modal" data-target="#orderModal<?= safe($order['id']) ?>">
                                            Показать детали заказа
                                        </button>
                                        
                                        <!-- Модальное окно для деталей заказа -->
                                        <div class="modal fade" id="orderModal<?= safe($order['id']) ?>" tabindex="-1" role="dialog" aria-labelledby="orderModalLabel<?= safe($order['id']) ?>" aria-hidden="true">
                                          <div class="modal-dialog modal-lg" role="document">
                                            <div class="modal-content">
                                              <div class="modal-header">
                                                <h5 class="modal-title" id="orderModalLabel<?= safe($order['id']) ?>">
                                                    Детали заказа ID: <?= safe($order['id']) ?>
                                                </h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                  <span aria-hidden="true">&times;</span>
                                                </button>
                                              </div>
                                              <div class="modal-body">
                                                <?php 
                                                  if (!empty($order['order'])) {
                                                      echo renderOrderDetails($order['order'], $fieldTranslations);
                                                  } else {
                                                      echo '<p>Детали заказа отсутствуют.</p>';
                                                  }
                                                ?>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Закрыть</button>
                                              </div>
                                            </div>
                                          </div>
                                        </div>
                                    </div>
                                    <hr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Заказы не найдены.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Заказы не найдены.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>Нет данных для отображения.</p>
        <?php endif; ?>
        <a href="?step=search" class="btn btn-secondary">Новый поиск</a>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
