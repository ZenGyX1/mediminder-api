<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// === 跨域 (CORS) 绝对放行版 ===
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ==================================

// 1. 自动加载 Slim 4 框架环境
require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// 2. CORS 中间件，完美拦截并响应浏览器的 OPTIONS 预检请求
$app->add(function ($request, $handler) {
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withStatus(200);
    }
    $response = $handler->handle($request);
    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization');
});

// =========================================================================
// 【核心数据库连接配置】抽离出来，确保所有接口都用最正确的姿势连云端数据库
// =========================================================================
function getDbConnection() {
    $port = getenv('MYSQLPORT') ?: '3306';
    $dbname = getenv('MYSQLDATABASE') ?: 'railway'; 
    $dbuser = getenv('MYSQLUSER') ?: 'root';
    $dbpass = getenv('MYSQLPASSWORD') ?: '';
    
    // 关键修复：强制使用 127.0.0.1 走 TCP/IP，彻底消灭 Socket 报错！
    $dsn = "mysql:host=127.0.0.1;port=$port;dbname=$dbname;charset=utf8mb4";
    $db = new PDO($dsn, $dbuser, $dbpass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $db;
}

// 3. 【正式注册接口】
$app->post('/api/register', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? 'patient'; 
    $dob = $input['dob'] ?? null;        

    if (empty($name) || empty($email) || empty($password)) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "必填项不能为空"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400);
    }

    try {
        $db = getDbConnection(); // 使用统一修复的连接
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (name, email, password_hash, role, dob) VALUES (:name, :email, :password_hash, :role, :dob)";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':dob', $dob);
        $stmt->execute();
        $newUserId = $db->lastInsertId();

        $payload = json_encode([
            "status" => "success",
            "message" => "User registered in DB successfully!",
            "data" => ["user_id" => $newUserId, "name" => $name, "email" => $email]
        ], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withStatus(201);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "数据库错误: " . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(500);
    }
});

// 4. 【正式登录接口】
$app->post('/api/login', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "邮箱和密码不能为空"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400);
    }

    try {
        $db = getDbConnection(); // 使用统一修复的连接
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $payload = json_encode([
                "status" => "success",
                "message" => "Login verified by DB!",
                "role" => $user['role'],
                "data" => [
                    "token" => "generated_session_token_example",
                    "user" => [
                        "id" => $user['id'],
                        "name" => $user['name'],
                        "email" => $user['email'],
                        "role" => $user['role']
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
            $response->getBody()->write($payload);
            return $response->withStatus(200);
        } else {
            $response->getBody()->write(json_encode(["status" => "error", "message" => "邮箱或密码错误"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(401);
        }
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "数据库错误: " . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(500);
    }
});

// 5. 【获取今日吃药日程】
$app->get('/api/doses', function (Request $request, Response $response) {
    try {
        $db = getDbConnection(); // 使用统一修复的连接
        $stmt = $db->query("SELECT id, status, DATE_FORMAT(taken_at, '%h:%i %p') as takenAt FROM dose_logs");
        $realLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statusMap = [];
        foreach ($realLogs as $log) {
            $statusMap[$log['id']] = $log;
        }

        $schedule = [
            ["id" => 1, "time" => "7:00 AM", "medName" => "Panadol", "dose" => "500mg", "color" => "teal"],
            ["id" => 2, "time" => "8:00 AM", "medName" => "Amlodipine", "dose" => "5mg", "color" => "blue"],
            ["id" => 3, "time" => "12:00 PM", "medName" => "Lisinopril", "dose" => "10mg", "color" => "amber"],
            ["id" => 4, "time" => "9:00 PM", "medName" => "Atorvastatin", "dose" => "20mg", "color" => "red"]
        ];

        foreach ($schedule as &$dose) {
            if (isset($statusMap[$dose['id']])) {
                $dbStatus = $statusMap[$dose['id']]['status'];
                $dose['status'] = ($dbStatus === 'scheduled') ? 'upcoming' : $dbStatus;
                $dose['takenAt'] = $statusMap[$dose['id']]['takenAt'];
            } else {
                $dose['status'] = 'upcoming';
            }
        }
        $response->getBody()->write(json_encode(["status" => "success", "data" => $schedule], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// 6. 【处理吃药打卡】
$app->post('/api/doses/mark', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $id = $input['id'] ?? null;
    $status = $input['status'] ?? null;

    if (!$id || !$status) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "缺少必要参数"]));
        return $response->withStatus(400);
    }

    try {
        $db = getDbConnection(); // 使用统一修复的连接
        $taken_at = ($status === 'taken') ? date('Y-m-d H:i:s') : null;
        $sql = "UPDATE dose_logs SET status = :status, taken_at = :taken_at WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':taken_at', $taken_at);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $response->getBody()->write(json_encode(["status" => "success", "message" => "数据库更新成功！"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// 7. 【处理添加药物】
$app->post('/api/medications/add', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $payload = json_encode([
        "status" => "success",
        "message" => "Medication added successfully for Demo!",
        "data" => $input
    ], JSON_UNESCAPED_UNICODE);
    $response->getBody()->write($payload);
    return $response->withStatus(200);
});

$app->run();