<?php
session_start();
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

$baseUrl = rtrim(!empty($config['base_url']) ? $config['base_url'] : preg_replace('#/plugins/[^/]+/[^/]+$#', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
$pluginUrl = $baseUrl . '/plugins/updownbored';
$apiUrl = $pluginUrl . '/api.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
} else {
    $pdo = new PDO('sqlite:' . ($config['db_path'] ?? __DIR__ . '/../../data/database.sqlite'));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function updownbored_validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function updownbored_post_scores($pdo, $postIds) {
    if (empty($postIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = $pdo->prepare("
        SELECT post_id,
               SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) AS up,
               SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) AS down
        FROM post_votes
        WHERE post_id IN ($placeholders)
        GROUP BY post_id
    ");
    $stmt->execute($postIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $scores = [];
    foreach ($rows as $row) {
        $up = (int)$row['up'];
        $down = (int)$row['down'];
        $scores[(int)$row['post_id']] = [
            'score' => $up - $down,
            'up' => $up,
            'down' => $down,
        ];
    }
    return $scores;
}

function updownbored_user_votes($pdo, $userId, $postIds) {
    if (empty($postIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $params = array_merge([$userId], $postIds);
    $stmt = $pdo->prepare("
        SELECT post_id, vote FROM post_votes
        WHERE user_id = ? AND post_id IN ($placeholders)
    ");
    $stmt->execute($params);
    $votes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $votes[(int)$row['post_id']] = (int)$row['vote'];
    }
    return $votes;
}

if ($method === 'GET') {
    $raw = $_GET['post_ids'] ?? '';
    $postIds = array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; });

    if (empty($postIds)) {
        echo json_encode(['success' => true, 'scores' => [], 'my_votes' => []]);
        exit;
    }

    $scores = updownbored_post_scores($pdo, $postIds);
    $myVotes = updownbored_user_votes($pdo, (int)$_SESSION['user_id'], $postIds);

    echo json_encode([
        'success' => true,
        'scores' => $scores,
        'my_votes' => $myVotes,
    ]);
    exit;
}

if ($method === 'POST') {
    if (!updownbored_validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'vote') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $vote = (int)($_POST['vote'] ?? 0);

        if ($postId <= 0 || ($vote !== 1 && $vote !== -1 && $vote !== 0)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid vote']);
            exit;
        }

        $check = $pdo->prepare("SELECT vote FROM post_votes WHERE post_id = ? AND user_id = ?");
        $check->execute([$postId, $_SESSION['user_id']]);
        $existing = $check->fetchColumn();

        if ($existing === false) {
            if ($vote !== 0) {
                $pdo->prepare("INSERT INTO post_votes (post_id, user_id, vote) VALUES (?, ?, ?)")
                    ->execute([$postId, $_SESSION['user_id'], $vote]);
            }
        } else {
            if ($vote === 0) {
                $pdo->prepare("DELETE FROM post_votes WHERE post_id = ? AND user_id = ?")
                    ->execute([$postId, $_SESSION['user_id']]);
            } else {
                $pdo->prepare("UPDATE post_votes SET vote = ? WHERE post_id = ? AND user_id = ?")
                    ->execute([$vote, $postId, $_SESSION['user_id']]);
            }
        }

        $scores = updownbored_post_scores($pdo, [$postId]);
        $myVotes = updownbored_user_votes($pdo, (int)$_SESSION['user_id'], [$postId]);

        echo json_encode([
            'success' => true,
            'post_id' => $postId,
            'score' => $scores[$postId]['score'] ?? 0,
            'up' => $scores[$postId]['up'] ?? 0,
            'down' => $scores[$postId]['down'] ?? 0,
            'my_vote' => $myVotes[$postId] ?? 0,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
