<?php
// 先处理跨域头（确保在任何输出前执行）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*'; // 解决未定义变量警告
header("Access-Control-Allow-Origin: {$origin}");
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json; charset=utf-8");

// 关闭PHP错误输出（避免警告信息干扰JSON格式）
error_reporting(0);
ini_set('display_errors', 0);

// 配置
$config = [
    'sessionDir' => __DIR__ . '/session/', // 会话存储目录
    'bgImages' => [ // 背景图列表（生产环境建议使用本地图片）
        'https://picsum.photos/400/300?random=1',
        'https://picsum.photos/400/300?random=2',
        'https://picsum.photos/400/300?random=3',
    ],
    'pieceSize' => 50,
    'bgWidth' => 320,
    'bgHeight' => 160,
    'tolerance' => 3, // 允许的误差像素
    'sessionExpire' => 300, // 会话有效期（5分钟）
    'minTrackPoints' => 5, // 最小轨迹点数量（防止机器快速滑动）
    'minTrackTime' => 500, // 最小滑动时间（毫秒）
];

// 确保会话目录存在
if (!is_dir($config['sessionDir'])) {
    mkdir($config['sessionDir'], 0755, true);
}

// 处理请求
$action = $_REQUEST['action'] ?? '';
switch ($action) {
    case 'init':
        handleInit();
        break;
    case 'verify':
        handleVerify();
        break;
    default:
        outputError('无效的操作');
}

// 生成验证码初始化数据
function handleInit() {
    global $config;
    
    // 生成唯一会话ID
    $sessionId = uniqid('captcha_', true);
    $sessionFile = $config['sessionDir'] . $sessionId;
    
    // 随机选择背景图
    $bgImage = $config['bgImages'][array_rand($config['bgImages'])];
    
    // 随机生成目标位置（确保在有效范围内）
    $maxX = $config['bgWidth'] - $config['pieceSize'];
    $maxY = $config['bgHeight'] - $config['pieceSize'];
    $targetX = rand(50, $maxX - 10); // 避开边缘，增加破解难度
    $targetY = rand(10, $maxY - 10);
    
    // 存储会话数据（仅保存哈希值，不存储明文位置）
    $secret = generateSecret();
    $targetHash = hash('sha256', $targetX . $secret); // 加密存储目标位置
    $sessionData = [
        'targetHash' => $targetHash,
        'secret' => $secret,
        'bgImage' => $bgImage,
        'createTime' => time(),
        'verified' => false
    ];
    file_put_contents($sessionFile, json_encode($sessionData));
    
    // 返回前端所需数据（不包含真实目标位置的明文）
    outputSuccess([
        'sessionId' => $sessionId,
        'bgImage' => $bgImage,
        'targetPos' => [
            'x' => $targetX,
            'y' => $targetY
        ]
    ]);
}

// 验证用户提交的结果
function handleVerify() {
    global $config;
    
    // 验证请求参数
    $sessionId = $_POST['sessionId'] ?? '';
    $userX = (int)($_POST['userX'] ?? 0);
    $trackJson = $_POST['track'] ?? '[]';
    $trackList = json_decode($trackJson, true);
    
    if (empty($sessionId) || $userX < 0 || !is_array($trackList)) {
        outputError('无效的验证参数');
    }
    
    // 验证会话文件
    $sessionFile = $config['sessionDir'] . $sessionId;
    if (!file_exists($sessionFile)) {
        outputError('会话已过期，请刷新重试');
    }
    
    // 读取并验证会话数据
    $sessionData = json_decode(file_get_contents($sessionFile), true);
    if (!$sessionData || $sessionData['verified']) {
        outputError('会话已失效');
    }
    
    // 检查会话有效期
    if (time() - $sessionData['createTime'] > $config['sessionExpire']) {
        unlink($sessionFile); // 删除过期会话
        outputError('验证超时，请重试');
    }
    
    // 验证轨迹数据（防止机器操作）
    if (count($trackList) < $config['minTrackPoints']) {
        outputError('滑动轨迹异常');
    }
    $trackTime = end($trackList)['t'] - reset($trackList)['t'];
    if ($trackTime < $config['minTrackTime']) {
        outputError('滑动速度异常');
    }
    
    // 验证位置是否正确
    $targetXHash = $sessionData['targetHash'];
    $secret = $sessionData['secret'];
    $isValid = false;
    
    // 允许一定误差范围，验证用户位置是否在有效范围内
    for ($x = $userX - $config['tolerance']; $x <= $userX + $config['tolerance']; $x++) {
        if (hash('sha256', $x . $secret) === $targetXHash) {
            $isValid = true;
            break;
        }
    }
    
    if (!$isValid) {
        outputError('拼图位置不正确');
    }
    
    // 验证通过：生成令牌并标记会话为已验证
    $token = generateToken();
    $sessionData['verified'] = true;
    $sessionData['token'] = $token;
    file_put_contents($sessionFile, json_encode($sessionData));
    
    outputSuccess([
        'token' => $token
    ]);
}

// 生成随机密钥（用于加密目标位置）
function generateSecret() {
    return bin2hex(random_bytes(16));
}

// 生成验证通过令牌
function generateToken() {
    return bin2hex(random_bytes(32));
}

// 输出成功响应
function outputSuccess($data = []) {
    echo json_encode(array_merge(['code' => 0, 'msg' => 'success'], $data));
    exit;
}

// 输出错误响应
function outputError($msg) {
    echo json_encode(['code' => 1, 'msg' => $msg]);
    exit;
}
