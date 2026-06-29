<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// =========================================================================
// 🚨 终极 CORS 防线：拦截 OPTIONS 请求，直接阻断 500 报错！
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    http_response_code(200);
    exit();
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/vendor/autoload.php';
$app = AppFactory::create();

// 添加 Slim 内部的错误中间件（方便在后台看报错排雷）
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// =========================================================================
// 🔗 数据库连接配置 (密码已校准，公网直连)
// =========================================================================
function getDbConnection() {
    // 🚨 终极绝杀：使用 getenv() 直接从 Railway 服务器内存中读取最新配置！
    // 无论后台密码怎么刷新、大小写多复杂，它抓取的绝对是 100% 匹配的当前真实密码！
    $host = getenv('MYSQLHOST') ?: 'junction.proxy.rlwy.net';
    $port = getenv('MYSQLPORT') ?: '44083';
    $dbname = getenv('MYSQLDATABASE') ?: 'railway';
    $dbuser = getenv('MYSQLUSER') ?: 'root';
    $dbpass = getenv('MYSQLPASSWORD') ?: 'xRSkNnnKkCvEjTdkebTrkTgLZDUlDzCd';
    
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $db = new PDO($dsn, $dbuser, $dbpass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// =========================================================================
// ⚙️ 0. 一键初始化建表 (访问 /api/setup-db 触发)
// =========================================================================
$app->get('/api/setup-db', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT 'patient')");
        $db->exec("CREATE TABLE IF NOT EXISTS medications (id INT AUTO_INCREMENT PRIMARY KEY, time VARCHAR(50), medName VARCHAR(100), dose VARCHAR(50), color VARCHAR(50) DEFAULT 'blue')");
        $db->exec("CREATE TABLE IF NOT EXISTS dose_logs (id INT AUTO_INCREMENT PRIMARY KEY, medication_id INT, status VARCHAR(50) DEFAULT 'scheduled', taken_at DATETIME NULL)");
        $response->getBody()->write(json_encode(["status" => "success", "message" => "所有表已就绪！"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// =========================================================================
// 👤 1. 账号系统模块
// =========================================================================
// [登录]
$app->post('/api/login', function (Request $request, Response $response) {
    try {
        $input = json_decode($request->getBody(), true);
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $input['email'] ?? '']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($input['password'] ?? '', $user['password_hash'])) {
            $response->getBody()->write(json_encode(["status" => "success", "role" => $user['role']]));
            return $response->withStatus(200);
        }
        $response->getBody()->write(json_encode(["status" => "error", "message" => "邮箱或密码错误"]));
        return $response->withStatus(401);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// [注册]
$app->post('/api/register', function (Request $request, Response $response) {
    try {
        $input = json_decode($request->getBody(), true);
        $db = getDbConnection();
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)");
        $stmt->execute([
            ':name' => $input['name'] ?? 'User', 
            ':email' => $input['email'], 
            ':hash' => password_hash($input['password'], PASSWORD_BCRYPT), 
            ':role' => 'patient'
        ]);
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(201);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "注册失败，可能是邮箱已存在"]));
        return $response->withStatus(500);
    }
});

// =========================================================================
// 💊 2. 药物管理模块
// =========================================================================
// [获取列表]
$app->get('/api/medications', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        $meds = $db->query("SELECT * FROM medications")->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode(["status" => "success", "data" => $meds]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// [添加药物]
$app->post('/api/medications/add', function (Request $request, Response $response) {
    try {
        $input = json_decode($request->getBody(), true);
        $db = getDbConnection();
        $stmt = $db->prepare("INSERT INTO medications (time, medName, dose, color) VALUES (:t, :n, :d, :c)");
        $stmt->execute([
            ':t' => $input['time'] ?? '08:00 AM', 
            ':n' => $input['medName'], 
            ':d' => $input['dose'] ?? '', 
            ':c' => $input['color'] ?? 'blue'
        ]);
        
        $newMedId = $db->lastInsertId();
        // 添加药后自动给它创建一条未打卡的记录
        $db->exec("INSERT INTO dose_logs (medication_id, status) VALUES ($newMedId, 'scheduled')");
        
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// [删除药物]
$app->post('/api/medications/delete', function (Request $request, Response $response) {
    try {
        $input = json_decode($request->getBody(), true);
        $db = getDbConnection();
        $stmt = $db->prepare("DELETE FROM medications WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        
        // 斩草除根，连同打卡记录一起删
        $stmtLog = $db->prepare("DELETE FROM dose_logs WHERE medication_id = :id");
        $stmtLog->execute([':id' => $input['id']]);

        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// =========================================================================
// ⏰ 3. 打卡日程模块
// =========================================================================
// [获取今日吃药日程]
$app->get('/api/doses', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        $sql = "SELECT m.id, m.time, m.medName, m.dose, m.color, d.status, DATE_FORMAT(d.taken_at, '%h:%i %p') as takenAt 
                FROM medications m 
                LEFT JOIN dose_logs d ON m.id = d.medication_id";
        $schedule = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($schedule as &$dose) {
            $dose['status'] = ($dose['status'] === 'scheduled' || !$dose['status']) ? 'upcoming' : $dose['status'];
        }

        $response->getBody()->write(json_encode(["status" => "success", "data" => $schedule]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// [点击打卡 / 取消打卡]
$app->post('/api/doses/mark', function (Request $request, Response $response) {
    try {
        $input = json_decode($request->getBody(), true);
        $db = getDbConnection();
        $taken_at = ($input['status'] === 'taken') ? date('Y-m-d H:i:s') : null;
        
        $stmt = $db->prepare("UPDATE dose_logs SET status = :status, taken_at = :taken_at WHERE medication_id = :id");
        $stmt->execute([
            ':status' => $input['status'], 
            ':taken_at' => $taken_at, 
            ':id' => $input['id']
        ]);

        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

$app->run();