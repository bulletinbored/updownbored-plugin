<?php
// Load the app bootstrap so the session uses the same save path / cookie
// settings as the rest of the site. Without it, session_start() here would
// create a brand new (empty) session and every vote request would 403.
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/helpers.php';

header('Content-Type: application/json');

$baseUrl = rtrim(!empty($config['base_url']) ? $config['base_url'] : preg_replace('#/plugins/[^/]+/[^/]+$#', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
$pluginUrl = $baseUrl . '/plugins/updownbored';
$apiUrl = $pluginUrl . '/api.php';

$method = $_SERVER['REQUEST_METHOD'];

// Allow thread_scores without login (public read-only data)
$isPublicAction = ($method === 'GET' && ($_GET['action'] ?? '') === 'thread_scores');

if (!isset($_SESSION['user_id']) && !$isPublicAction) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

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
    $action = $_GET['action'] ?? '';

    if ($action === 'thread_scores') {
        $raw = $_GET['thread_ids'] ?? '';
        $threadIds = array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; });

        if (empty($threadIds)) {
            echo json_encode(['success' => true, 'scores' => []]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $stmt = $pdo->prepare("
            SELECT p.thread_id,
                   SUM(CASE WHEN pv.vote = 1 THEN 1 ELSE 0 END) AS up,
                   SUM(CASE WHEN pv.vote = -1 THEN 1 ELSE 0 END) AS down,
                   SUM(pv.vote) AS score
            FROM post_votes pv
            INNER JOIN posts p ON p.id = pv.post_id
            WHERE p.thread_id IN ($placeholders)
            AND p.status = 'visible'
            GROUP BY p.thread_id
        ");
        $stmt->execute($threadIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int)$row['thread_id']] = [
                'score' => (int)$row['score'],
                'up' => (int)$row['up'],
                'down' => (int)$row['down'],
            ];
        }

        echo json_encode([
            'success' => true,
            'scores' => $scores,
        ]);
        exit;
    }

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

        // Only allow voting on posts that exist and are visible.
        $postCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND status = 'visible'");
        $postCheck->execute([$postId]);
        if (!$postCheck->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Post not found']);
            exit;
        }

        if (!rate_limit('updownbored_vote', 60, 300, (string)$_SESSION['user_id'])) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests']);
            exit;
        }

        $changed = false;
        if ($vote === 0) {
            $pdo->prepare("DELETE FROM post_votes WHERE post_id = ? AND user_id = ?")
                ->execute([$postId, $_SESSION['user_id']]);
        } else {
            // Atomic upsert: the (post_id, user_id) unique index prevents
            // duplicate rows under concurrent requests.
            if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
                $upsert = "INSERT INTO post_votes (post_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)";
            } else {
                $upsert = "INSERT INTO post_votes (post_id, user_id, vote) VALUES (?, ?, ?) ON CONFLICT(post_id, user_id) DO UPDATE SET vote = excluded.vote";
            }
            $pdo->prepare($upsert)->execute([$postId, $_SESSION['user_id'], $vote]);
            $changed = true;
        }

        // Notify the post author about the vote (updownbored owns this). Skip
        // self-votes and removals/un-votes.
        if ($changed && $vote !== 0) {
            $postStmt = $pdo->prepare("
                SELECT p.user_id, p.thread_id, t.title
                FROM posts p
                LEFT JOIN threads t ON p.thread_id = t.id
                WHERE p.id = ?
            ");
            $postStmt->execute([$postId]);
            $postInfo = $postStmt->fetch(PDO::FETCH_ASSOC);
            $voterName = $_SESSION['username'] ?? 'Someone';
            $threadId = (int)($postInfo['thread_id'] ?? 0);
            $threadTitle = $postInfo['title'] ?? '';
            $authorId = (int)($postInfo['user_id'] ?? 0);
            if ($authorId > 0 && $authorId !== (int)$_SESSION['user_id'] && $threadId > 0) {
                $voteLink = url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)], true);
                $voteType = $vote === 1 ? 'upvote' : 'downvote';
                $notifMsg = t('vote_notification', [
                    'voter' => escape($voterName),
                    'type' => $voteType,
                    'title' => escape($threadTitle),
                ]);
                create_notification($pdo, $authorId, 'vote', $notifMsg, $notifMsg, $voteLink);
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
