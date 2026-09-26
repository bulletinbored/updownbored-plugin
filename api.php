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

// All GET endpoints expose only public read-only vote counts/ordering data;
// casting a vote (POST) still requires a logged-in user.
$isPublicAction = ($method === 'GET');

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

/**
 * Combined thread score = opening-post votes (thread_votes) + votes on every
 * visible reply (post_votes). This is what "sort by votes" ranks on.
 */
function updownbored_thread_scores($pdo, $threadIds) {
    if (empty($threadIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $scores = [];

    $opStmt = $pdo->prepare("
        SELECT thread_id,
               SUM(vote) AS score,
               SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) AS up,
               SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) AS down
        FROM thread_votes
        WHERE thread_id IN ($placeholders)
        GROUP BY thread_id
    ");
    $opStmt->execute($threadIds);
    foreach ($opStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scores[(int)$row['thread_id']] = [
            'score' => (int)$row['score'],
            'up' => (int)$row['up'],
            'down' => (int)$row['down'],
        ];
    }

    $replyStmt = $pdo->prepare("
        SELECT p.thread_id,
               SUM(pv.vote) AS score,
               SUM(CASE WHEN pv.vote = 1 THEN 1 ELSE 0 END) AS up,
               SUM(CASE WHEN pv.vote = -1 THEN 1 ELSE 0 END) AS down
        FROM post_votes pv
        INNER JOIN posts p ON p.id = pv.post_id
        WHERE p.thread_id IN ($placeholders)
        AND p.status = 'visible'
        GROUP BY p.thread_id
    ");
    $replyStmt->execute($threadIds);
    foreach ($replyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['thread_id'];
        if (!isset($scores[$tid])) {
            $scores[$tid] = ['score' => 0, 'up' => 0, 'down' => 0];
        }
        $scores[$tid]['score'] += (int)$row['score'];
        $scores[$tid]['up'] += (int)$row['up'];
        $scores[$tid]['down'] += (int)$row['down'];
    }

    // Legacy: before this plugin had a thread_votes table, upvotes on the
    // opening post were stored in post_votes with post_id = thread id. Those
    // rows have no matching posts row; count them as opening-post votes so old
    // scores are not lost.
    $legacyStmt = $pdo->prepare("
        SELECT pv.post_id AS thread_id,
               SUM(pv.vote) AS score,
               SUM(CASE WHEN pv.vote = 1 THEN 1 ELSE 0 END) AS up,
               SUM(CASE WHEN pv.vote = -1 THEN 1 ELSE 0 END) AS down
        FROM post_votes pv
        WHERE pv.post_id IN ($placeholders)
        AND NOT EXISTS (SELECT 1 FROM posts lp WHERE lp.id = pv.post_id)
        AND NOT EXISTS (SELECT 1 FROM thread_votes tvx WHERE tvx.thread_id = pv.post_id AND tvx.user_id = pv.user_id)
        GROUP BY pv.post_id
    ");
    $legacyStmt->execute($threadIds);
    foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['thread_id'];
        if (!isset($scores[$tid])) {
            $scores[$tid] = ['score' => 0, 'up' => 0, 'down' => 0];
        }
        $scores[$tid]['score'] += (int)$row['score'];
        $scores[$tid]['up'] += (int)$row['up'];
        $scores[$tid]['down'] += (int)$row['down'];
    }

    return $scores;
}

function updownbored_user_thread_votes($pdo, $userId, $threadIds) {
    if (empty($threadIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $params = array_merge([$userId], $threadIds);
    $stmt = $pdo->prepare("
        SELECT thread_id, vote FROM thread_votes
        WHERE user_id = ? AND thread_id IN ($placeholders)
    ");
    $stmt->execute($params);
    $votes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $votes[(int)$row['thread_id']] = (int)$row['vote'];
    }

    // Legacy opening-post votes live in post_votes keyed by thread id. Surface
    // them as the user's current vote unless a thread_votes row already exists.
    $legacy = $pdo->prepare("
        SELECT pv.post_id, pv.vote FROM post_votes pv
        WHERE pv.user_id = ? AND pv.post_id IN ($placeholders)
        AND NOT EXISTS (SELECT 1 FROM posts lp WHERE lp.id = pv.post_id)
    ");
    $legacy->execute($params);
    foreach ($legacy->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['post_id'];
        if (!isset($votes[$tid])) {
            $votes[$tid] = (int)$row['vote'];
        }
    }

    return $votes;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'thread_scores') {
        $raw = $_GET['thread_ids'] ?? '';
        $threadIds = array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; });

        echo json_encode([
            'success' => true,
            'scores' => updownbored_thread_scores($pdo, $threadIds),
        ]);
        exit;
    }

    $rawPosts = $_GET['post_ids'] ?? '';
    $postIds = array_filter(array_map('intval', explode(',', $rawPosts)), function ($v) { return $v > 0; });
    $rawThreads = $_GET['thread_ids'] ?? '';
    $threadIds = array_filter(array_map('intval', explode(',', $rawThreads)), function ($v) { return $v > 0; });

    $userId = (int)($_SESSION['user_id'] ?? 0);

    echo json_encode([
        'success' => true,
        'scores' => updownbored_post_scores($pdo, $postIds),
        'my_votes' => $userId > 0 ? updownbored_user_votes($pdo, $userId, $postIds) : [],
        'thread_scores' => updownbored_thread_scores($pdo, $threadIds),
        'my_thread_votes' => $userId > 0 ? updownbored_user_thread_votes($pdo, $userId, $threadIds) : [],
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

    if ($action === 'vote_thread') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $vote = (int)($_POST['vote'] ?? 0);

        if ($threadId <= 0 || ($vote !== 1 && $vote !== -1 && $vote !== 0)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid vote']);
            exit;
        }

        // Only allow voting on threads the public can see.
        $threadCheck = $pdo->prepare("SELECT user_id, title FROM threads WHERE id = ? AND status IN ('visible','sticky','locked')");
        $threadCheck->execute([$threadId]);
        $threadInfo = $threadCheck->fetch(PDO::FETCH_ASSOC);
        if (!$threadInfo) {
            http_response_code(404);
            echo json_encode(['error' => 'Thread not found']);
            exit;
        }

        if (!rate_limit('updownbored_vote', 60, 300, (string)$_SESSION['user_id'])) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests']);
            exit;
        }

        // Drop any legacy opening-post row for this user/thread so it cannot be
        // counted twice once a real thread_votes row exists.
        $pdo->prepare("
            DELETE FROM post_votes
            WHERE post_id = ? AND user_id = ?
            AND NOT EXISTS (SELECT 1 FROM posts lp WHERE lp.id = post_votes.post_id)
        ")->execute([$threadId, $_SESSION['user_id']]);

        $changed = false;
        if ($vote === 0) {
            $pdo->prepare("DELETE FROM thread_votes WHERE thread_id = ? AND user_id = ?")
                ->execute([$threadId, $_SESSION['user_id']]);
        } else {
            if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
                $upsert = "INSERT INTO thread_votes (thread_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)";
            } else {
                $upsert = "INSERT INTO thread_votes (thread_id, user_id, vote) VALUES (?, ?, ?) ON CONFLICT(thread_id, user_id) DO UPDATE SET vote = excluded.vote";
            }
            $pdo->prepare($upsert)->execute([$threadId, $_SESSION['user_id'], $vote]);
            $changed = true;
        }

        // Notify the thread author. Skip self-votes and un-votes.
        if ($changed && $vote !== 0) {
            $voterName = $_SESSION['username'] ?? 'Someone';
            $threadTitle = $threadInfo['title'] ?? '';
            $authorId = (int)($threadInfo['user_id'] ?? 0);
            if ($authorId > 0 && $authorId !== (int)$_SESSION['user_id']) {
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

        $scores = updownbored_thread_scores($pdo, [$threadId]);
        $myVotes = updownbored_user_thread_votes($pdo, (int)$_SESSION['user_id'], [$threadId]);

        echo json_encode([
            'success' => true,
            'thread_id' => $threadId,
            'score' => $scores[$threadId]['score'] ?? 0,
            'up' => $scores[$threadId]['up'] ?? 0,
            'down' => $scores[$threadId]['down'] ?? 0,
            'my_vote' => $myVotes[$threadId] ?? 0,
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
