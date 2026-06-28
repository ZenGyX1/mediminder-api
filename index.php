<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
// === 跨域 (CORS) 万能通行证 ===
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// 处理浏览器的 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ==================================

// 1. 自动加载 Slim 4 框架环境
require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// 2. 【核心修复】：重新整顿 CORS 中间件，完美拦截并响应浏览器的 OPTIONS 预检请求
$app->add(function ($request, $handler) {
    // 如果是 OPTIONS 预检请求，直接在门口拦截，给浏览器返回 200 OK 和放行通行证，不让它往下走避免框架报错
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withStatus(200);
    }

    // 正常的 POST/GET 请求放行进入后面的路由
    $response = $handler->handle($request);
    
    // 统一为所有返回的数据戴上 JSON 格式帽子和跨域通行证
    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization');
});

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

    // 本地 Laragon 数据库配置（已确认带有下划线）
    $dbhost = 'localhost';
    $dbuser = 'root';
    $dbpass = '';
    $dbname = 'mediminder_db';

    try {
        $db = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            "data" => [
                "user_id" => $newUserId,
                "name" => $name,
                "email" => $email
            ]
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

    $dbhost = 'localhost';
    $dbuser = 'root';
    $dbpass = '';
    $dbname = 'mediminder_db';

    try {
        $db = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $payload = json_encode([
                "status" => "success",
                "message" => "Login verified by DB!",
                "role" => $user['role'], // 确保外层有这个关键字段供给前端权限判断
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
// 5. 【获取今日吃药日程】：合并 UI 数据与真实的数据库状态
$app->get('/api/doses', function (Request $request, Response $response) {
   $dbhost = getenv('MYSQLHOST') ?: 'localhost';
    $dbport = getenv('MYSQLPORT') ?: '3306';
    $dbuser = getenv('MYSQLUSER') ?: 'root';
    $dbpass = getenv('MYSQLPASSWORD') ?: '';
    $dbname = 'mediminder_db';

    try {
        $db = new PDO("mysql:host=$dbhost;port=$dbport;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 去真实的 dose_logs 表里查询今天的吃药状态
        $stmt = $db->query("SELECT id, status, DATE_FORMAT(taken_at, '%h:%i %p') as takenAt FROM dose_logs");
        $realLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 将数据库查询结果转换为以 ID 为键的数组，方便查找
        $statusMap = [];
        foreach ($realLogs as $log) {
            $statusMap[$log['id']] = $log;
        }

        // 构建完美适配前端 UI 的数据结构 (老师要求的 Panadol 场景)
        $schedule = [
            ["id" => 1, "time" => "7:00 AM", "medName" => "Panadol", "dose" => "500mg", "color" => "teal"],
            ["id" => 2, "time" => "8:00 AM", "medName" => "Amlodipine", "dose" => "5mg", "color" => "blue"],
            ["id" => 3, "time" => "12:00 PM", "medName" => "Lisinopril", "dose" => "10mg", "color" => "amber"],
            ["id" => 4, "time" => "9:00 PM", "medName" => "Atorvastatin", "dose" => "20mg", "color" => "red"]
        ];

        // 【核心魔法】把数据库里的真实状态，注入到 UI 数据中
        foreach ($schedule as &$dose) {
            if (isset($statusMap[$dose['id']])) {
                $dbStatus = $statusMap[$dose['id']]['status'];
                // 【翻译官】：如果数据库里是 scheduled，就翻译成前端认识的 upcoming
                $dose['status'] = ($dbStatus === 'scheduled') ? 'upcoming' : $dbStatus;
                $dose['takenAt'] = $statusMap[$dose['id']]['takenAt'];
            } else {
                $dose['status'] = 'upcoming'; // 数据库没查到就默认未吃
            }
        }

        $response->getBody()->write(json_encode(["status" => "success", "data" => $schedule], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200);

    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// 6. 【处理吃药打卡】：将前端的点击动作真实写入数据库
$app->post('/api/doses/mark', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $id = $input['id'] ?? null;
    $status = $input['status'] ?? null;

    if (!$id || !$status) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => "缺少必要参数"]));
        return $response->withStatus(400);
    }

    $dbhost = getenv('MYSQLHOST') ?: 'localhost';
    $dbport = getenv('MYSQLPORT') ?: '3306';
    $dbuser = getenv('MYSQLUSER') ?: 'root';
    $dbpass = getenv('MYSQLPASSWORD') ?: '';
    $dbname = 'mediminder_db';

    try {
        $db = new PDO("mysql:host=$dbhost;port=$dbport;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 如果是 taken，就记录当前真实时间，否则清空时间
        $taken_at = ($status === 'taken') ? date('Y-m-d H:i:s') : null;

        // 【真实操作】更新数据库表
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
// 7. 【处理添加药物】：接收前端新增的药物计划
$app->post('/api/medications/add', function (Request $request, Response $response) {
    // Demo 阶段：安全接收请求并返回 200，保证前端乐观更新不报错
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