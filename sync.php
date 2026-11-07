<?php
/**
 * OZON Seller API -> MySQL
 * 1. При запуске получает список ПВЗ и пишет в БД.
 * 2. Получает список заказов за период, привязывает к ПВЗ (регион, город) и пишет в БД.
 *
 * Запуск: php sync.php
 * Требуется: PHP 7.4+, расширения curl, pdo_mysql.
 * Ключи: Client-Id и Api-Key личного кабинета OZON Seller.
 */

define('CLIENT_ID', getenv('OZON_CLIENT_ID') ?: 'ВАШ_CLIENT_ID');
define('API_KEY',   getenv('OZON_API_KEY')   ?: 'ВАШ_API_KEY');
define('API_BASE',  'https://api-seller.ozon.ru');

define('DB_DSN',  getenv('DB_DSN')  ?: 'mysql:host=localhost;dbname=ozon_sync;charset=utf8mb4');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

/* ---------- БД ---------- */

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS pvz (
            id            BIGINT UNSIGNED PRIMARY KEY,
            name          VARCHAR(255),
            region        VARCHAR(255),
            city          VARCHAR(255),
            address       VARCHAR(512),
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            posting_number VARCHAR(64) PRIMARY KEY,
            order_id       BIGINT UNSIGNED,
            status         VARCHAR(64),
            region         VARCHAR(255),
            city           VARCHAR(255),
            pvz_name       VARCHAR(255),
            created_at_ozon DATETIME,
            synced_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }
    return $pdo;
}

/* ---------- API ---------- */

function api(string $path, array $body = []): array {
    $ch = curl_init(API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Client-Id: ' . CLIENT_ID,
            'Api-Key: '   . API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new RuntimeException("OZON API $path -> HTTP $code: $resp");
    }
    return json_decode($resp, true) ?: [];
}

/* ---------- 1. ПВЗ ---------- */

function syncPvz(): int {
    $data = api('/v1/delivery-method/list', ['filter' => ['status' => 'active'], 'limit' => 1000, 'offset' => 0]);
    $count = 0;
    $stmt = db()->prepare("INSERT INTO pvz (id, name, region, city, address)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), region=VALUES(region), city=VALUES(city), address=VALUES(address)");
    foreach ($data['delivery_methods'] ?? [] as $m) {
        $pvz = $m['pvz'] ?? $m;
        $stmt->execute([
            $pvz['id'] ?? 0,
            $pvz['name'] ?? '',
            $pvz['region']['name'] ?? $pvz['region'] ?? '',
            $pvz['city'] ?? '',
            $pvz['address'] ?? '',
        ]);
        $count++;
    }
    return $count;
}

/* ---------- 2. Заказы за период ---------- */

function syncOrders(string $since, string $to): int {
    $count = 0;
    $stmt = db()->prepare("INSERT INTO orders (posting_number, order_id, status, region, city, pvz_name, created_at_ozon)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status=VALUES(status), region=VALUES(region), city=VALUES(city), pvz_name=VALUES(pvz_name)");
    $offset = 0;
    do {
        $data = api('/v3/posting/fbs/list', [
            'dir'    => 'ASC',
            'filter' => ['since' => $since, 'to' => $to],
            'limit'  => 100,
            'offset' => $offset,
            'with'   => ['analytics_data' => true],
        ]);
        $postings = $data['result']['postings'] ?? [];
        foreach ($postings as $p) {
            $analytics = $p['analytics_data'] ?? [];
            $delivery  = $p['delivery_method'] ?? [];
            $stmt->execute([
                $p['posting_number'] ?? '',
                $p['order_id'] ?? 0,
                $p['status'] ?? '',
                $analytics['region'] ?? '',
                $analytics['city'] ?? '',
                $delivery['name'] ?? ($delivery['tpl_provider'] ?? ''),
                isset($p['in_process_at']) ? date('Y-m-d H:i:s', strtotime($p['in_process_at'])) : null,
            ]);
            $count++;
        }
        $offset += count($postings);
        $hasNext = $data['result']['has_next'] ?? false;
    } while ($hasNext && $postings);
    return $count;
}

/* ---------- main ---------- */

try {
    echo '[' . date('H:i:s') . "] Синхронизация ПВЗ...\n";
    $pvz = syncPvz();
    echo "ПВЗ записано/обновлено: $pvz\n";

    $since = date('c', strtotime('-30 days'));
    $to    = date('c');
    echo '[' . date('H:i:s') . "] Заказы за период $since .. $to\n";
    $orders = syncOrders($since, $to);
    echo "Заказов записано/обновлено: $orders\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
