<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// === 跨域 (CORS) 绝对放行版 ===
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require __DIR__ . '/vendor/autoload.php';
$app = AppFactory::create();

$app->add(function ($request, $handler) {
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return $response->withStatus(200);
    }
    $response = $handler->handle($request);
    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Access-Control-Allow-Origin', '*');
});

// ==========================================
// 🔗 核心：连接你的 Railway 真实云端数据库
// ==========================================
function getDbConnection() {
    $host = 'junction.proxy.rlwy.net';
    $port = '44083';
    $dbname = 'railway'; 
    $dbuser = 'root';
    $dbpass = 'xRSkNnnKkCvEjTdkebTrkTgLZDUlDzCd'; // 你的真实密码
    
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $db = new PDO($dsn, $dbuser, $dbpass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// ==========================================
// 🚀 0. 一键建表魔法 (包含所有所需数据表)
// ==========================================
$app->get('/api/setup-db', function ($request, $response) {
    try {
        $db = getDbConnection();
        // 用户表
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT 'patient'
        )");
        // 药物信息表
        $db->exec("CREATE TABLE IF NOT EXISTS medications (
            id INT AUTO_INCREMENT PRIMARY KEY, time VARCHAR(50), medName VARCHAR(100), dose VARCHAR(50), color VARCHAR(50) DEFAULT 'blue'
        )");
        // 吃药打卡表
        $db->exec("CREATE TABLE IF NOT EXISTS dose_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, med_id INT, status VARCHAR(50) DEFAULT 'scheduled', taken_at DATETIME NULL
        )");
        $response->getBody()->write(json_encode(["status" => "success", "message" => "太棒了！所有数据库表已全部自动创建并绑定完毕！"]));
        return $response->withStatus(200);
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["status" => "error", "message" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

// ==========================================
// 👤 1. 用户账号系统 (注册 & 登录)
// ==========================================
$app->post('/api/register', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    try {
        $db = getDbConnection();
        $hash = password_hash($input['password'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)");
        $stmt->execute([':name' => $input['name']??'', ':email' => $input['email'], ':hash' => $hash, ':role' => $input['role']??'patient']);
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(201);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

$app->post('/api/login', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $input['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($input['password'], $user['password_hash'])) {
            $response->getBody()->write(json_encode(["status" => "success", "role" => $user['role']]));
            return $response->withStatus(200);
        }
        $response->getBody()->write(json_encode(["status" => "error", "message" => "密码错误"]));
        return $response->withStatus(401);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

// ==========================================
// 💊 2. 药物管理系统 (增、删、查)
// ==========================================
// [查] 获取所有药物
$app->get('/api/medications', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        $stmt = $db->query("SELECT * FROM medications");
        $meds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode(["status" => "success", "data" => $meds]));
        return $response->withStatus(200);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

// [增] 添加新药 (并同步创建今天的打卡记录)
$app->post('/api/medications/add', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("INSERT INTO medications (time, medName, dose, color) VALUES (:time, :medName, :dose, :color)");
        $stmt->execute([':time'=>$input['time']??'', ':medName'=>$input['medName'], ':dose'=>$input['dose']??'', ':color'=>$input['color']??'blue']);
        
        $newMedId = $db->lastInsertId();
        // 同步给这个药加一个未吃 (scheduled) 的状态
        $db->exec("INSERT INTO dose_logs (med_id, status) VALUES ($newMedId, 'scheduled')");

        $response->getBody()->write(json_encode(["status" => "success", "message" => "真实写入数据库成功！"]));
        return $response->withStatus(200);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

// [删] 删除药物 (连同打卡记录一起删掉)
$app->post('/api/medications/delete', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("DELETE FROM medications WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        
        // 斩草除根：把它的打卡记录也删了
        $stmtLog = $db->prepare("DELETE FROM dose_logs WHERE med_id = :id");
        $stmtLog->execute([':id' => $input['id']]);

        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withStatus(200);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

// ==========================================
// ⏰ 3. 打卡系统 (获取日程、点击吃药)
// ==========================================
// 获取今日所有打卡日程
$app->get('/api/doses', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        // 联合查询：把药物信息和它的打卡状态拼在一起
        $sql = "SELECT m.id, m.time, m.medName, m.dose, m.color, d.status, DATE_FORMAT(d.taken_at, '%h:%i %p') as takenAt 
                FROM medications m 
                LEFT JOIN dose_logs d ON m.id = d.med_id";
        $stmt = $db->query($sql);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 如果 status 为空（说明刚加的药没配好状态），默认给 upcoming
        foreach ($schedule as &$dose) {
            $dose['status'] = ($dose['status'] === 'scheduled' || !$dose['status']) ? 'upcoming' : $dose['status'];
        }

        $response->getBody()->write(json_encode(["status" => "success", "data" => $schedule]));
        return $response->withStatus(200);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

// 点击打卡 (标记为 taken 或 missed)
$app->post('/api/doses/mark', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    try {
        $db = getDbConnection();
        $taken_at = ($input['status'] === 'taken') ? date('Y-m-d H:i:s') : null;
        
        $stmt = $db->prepare("UPDATE dose_logs SET status = :status, taken_at = :taken_at WHERE med_id = :id");
        $stmt->execute([':status' => $input['status'], ':taken_at' => $taken_at, ':id' => $input['id']]);

        $response->getBody()->write(json_encode(["status" => "success", "message" => "打卡状态已永久保存！"]));
        return $response->withStatus(200);
    } catch (PDOException $e) { return $response->withStatus(500); }
});

$app->run();