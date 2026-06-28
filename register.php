<?php
// 1. 允许跨域（CORS），方便前端 Li Weiheng 访问你的注册接口
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 如果是预检请求，直接返回200
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

// 确保前端传全了所需字段
if (!empty($data['full_name']) && !empty($data['email']) && !empty($data['password']) && !empty($data['role'])) {
    
    $full_name = $data['full_name'];
    $email = $data['email'];
    $password = $data['password'];
    $role = $data['role']; // 'Patient', 'Caregiver', 'Clinic Staff', 'Admin'

    // --- 核心安全：对密码进行哈希加密 ---
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        // 检查邮箱是否已经被注册过
        $checkQuery = "SELECT user_id FROM users WHERE email = :email";
        $checkStmt = $dbConnection->prepare($checkQuery);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["message" => "Email already exists. Please use another one."]);
            exit();
        }

        // 插入新用户（注意：存入的是加密后的 $password_hash，列名记得和 Liu Changlin 数据库里的 password_hash 对上）
        $query = "INSERT INTO users (full_name, email, password_hash, role) VALUES (:full_name, :email, :password_hash, :role)";
        $stmt = $dbConnection->prepare($query);

        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash); // 存入加密后的密文
        $stmt->bindParam(':role', $role);

        if ($stmt->execute()) {
            http_response_code(201); // 201 Created 表示创建成功
            echo json_encode(["message" => "User was successfully registered."]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Unable to register user."]);
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["message" => "Database error: " . $e->getMessage()]);
    }

} else {
    http_response_code(400);
    echo json_encode(["message" => "Incomplete data. full_name, email, password, and role are required."]);
}