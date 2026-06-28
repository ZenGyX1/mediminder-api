<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(200); }

require __DIR__ . '/vendor/autoload.php';
$app = AppFactory::create();

function getDbConnection() {
    $host = 'junction.proxy.rlwy.net';
    $port = '44083';
    $dbname = 'railway';
    $dbuser = 'root';
    $dbpass = 'xRSkNnnKkCvEjTdkebTrkTgLZDUlDzCd';
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $db = new PDO($dsn, $dbuser, $dbpass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// 1. 系统初始化
$app->get('/api/setup-db', function ($request, $response) {
    $db = getDbConnection();
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT 'patient')");
    $db->exec("CREATE TABLE IF NOT EXISTS medications (id INT AUTO_INCREMENT PRIMARY KEY, time VARCHAR(50), medName VARCHAR(100), dose VARCHAR(50), color VARCHAR(50) DEFAULT 'blue')");
    $db->exec("CREATE TABLE IF NOT EXISTS dose_logs (id INT AUTO_INCREMENT PRIMARY KEY, med_id INT, status VARCHAR(50), taken_at DATETIME NULL)");
    $response->getBody()->write(json_encode(["status" => "success"]));
    return $response;
});

// 2. 账号系统
$app->post('/api/login', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $input['email'] ?? '']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($input['password'] ?? '', $user['password_hash'])) {
        $response->getBody()->write(json_encode(["status" => "success", "role" => $user['role']]));
        return $response->withStatus(200);
    }
    $response->getBody()->write(json_encode(["status" => "error", "message" => "登录失败"]));
    return $response->withStatus(401);
});

$app->post('/api/register', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $db = getDbConnection();
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)");
    $stmt->execute([':name' => $input['name'], ':email' => $input['email'], ':hash' => password_hash($input['password'], PASSWORD_BCRYPT)]);
    $response->getBody()->write(json_encode(["status" => "success"]));
    return $response->withStatus(201);
});

// 3. 药物管理
$app->get('/api/medications', function ($request, $response) {
    $db = getDbConnection();
    $meds = $db->query("SELECT * FROM medications")->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode(["status" => "success", "data" => $meds]));
    return $response;
});

$app->post('/api/medications/add', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $db = getDbConnection();
    $stmt = $db->prepare("INSERT INTO medications (time, medName, dose, color) VALUES (:t, :n, :d, :c)");
    $stmt->execute([':t' => $input['time'], ':n' => $input['medName'], ':d' => $input['dose'], ':c' => $input['color']]);
    $response->getBody()->write(json_encode(["status" => "success"]));
    return $response;
});

// 4. 打卡系统
$app->get('/api/doses', function ($request, $response) {
    $db = getDbConnection();
    $data = $db->query("SELECT m.*, d.status, d.taken_at FROM medications m LEFT JOIN dose_logs d ON m.id = d.med_id")->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode(["status" => "success", "data" => $data]));
    return $response;
});

$app->post('/api/doses/mark', function (Request $request, Response $response) {
    $input = json_decode($request->getBody(), true);
    $db = getDbConnection();
    $stmt = $db->prepare("REPLACE INTO dose_logs (med_id, status, taken_at) VALUES (:id, :s, NOW())");
    $stmt->execute([':id' => $input['id'], ':s' => $input['status']]);
    $response->getBody()->write(json_encode(["status" => "success"]));
    return $response;
});

$app->run();