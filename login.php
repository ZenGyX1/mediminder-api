<?php
// 1. 允许跨域（CORS），方便前端 Li Weiheng 访问你的接口
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 如果是预检请求，直接返回200
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 引入 Composer 自动加载（用于引入 JWT 库）
require __DIR__ . '/../vendor/autoload.php';
use \Firebase\JWT\JWT;

// 数据库配置
$dbhost = "127.0.0.1";
$dbuser = "root";
$dbpass = "";
$dbname = "mediminder_db";

try {
    $dbConnection = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

// 获取前端传过来的 JSON 数据
$data = json_decode(file_get_contents("php://input"), true);

if (!empty($data['email']) && !empty($data['password'])) {
    $email = $data['email'];
    $password = $data['password'];

    // 查询用户
    $query = "SELECT user_id, full_name, password_hash, role FROM users WHERE email = :email";
    $stmt = $dbConnection->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 验证用户存在，并使用 password_verify 验证加密后的密码
    if ($user && password_verify($password, $user['password_hash'])) {
        
        // --- 核心：生成 JWT Token ---
        $key = "YOUR_SECRET_KEY_12345"; // 你们团队自定义的加密密钥
        $issuedAt = time();
        $expirationTime = $issuedAt + 3600;  // Token 1小时后过期
        
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'user_id' => $user['user_id'],
            'role' => $user['role'] // 把角色带上，方便前端做路由权限控制
        ];

        $jwt = JWT::encode($payload, $key, 'HS256');

        // 登录成功：返回规范的 JSON 数据和 Token
        http_response_code(200);
        echo json_encode([
            "message" => "Login successful",
            "token" => $jwt,
            "user" => [
                "user_id" => $user['user_id'],
                "full_name" => $user['full_name'],
                "role" => $user['role']
            ]
        ]);
    } else {
        // 登录失败
        http_response_code(401);
        echo json_encode(["message" => "Invalid email or password"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["message" => "Incomplete data. Email and password are required."]);
}