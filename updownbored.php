<?php
/**
 * Plugin Name: updownbored
 * Author: mlzog
 * Description: Upvote and downvote individual posts
 * License: BSD Zero Clause License

 */

function updownbored_init() {
    global $pluginManager, $config, $pdo;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/updownbored';
    $apiUrl = $pluginUrl . '/api.php';

    $driver = $config['db_driver'] ?? 'sqlite';

    if (isset($pdo)) {
        if ($driver === 'mysql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS post_votes (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    post_id INT NOT NULL,
                    user_id INT NOT NULL,
                    vote TINYINT NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_post_user (post_id, user_id),
                    INDEX idx_post_votes_post_id (post_id)
                )
            ");
            // The opening post is stored on threads (not as a posts row), so its
            // votes live in their own table and are summed with the replies.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_votes (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    thread_id INT NOT NULL,
                    user_id INT NOT NULL,
                    vote TINYINT NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_thread_user (thread_id, user_id),
                    INDEX idx_thread_votes_thread_id (thread_id)
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS post_votes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    vote INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (post_id, user_id)
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_post_votes_post_id ON post_votes(post_id)");
            // The opening post is stored on threads (not as a posts row), so its
            // votes live in their own table and are summed with the replies.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_votes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    vote INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (thread_id, user_id)
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_thread_votes_thread_id ON thread_votes(thread_id)");
        }
    }

    $udVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $udVer('assets/css/updownbored.css');
    $jsUrl = $udVer('assets/js/updownbored.js');
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
    $nonce = function_exists('csp_nonce') ? csp_nonce() : '';

    $sortLabel = t('sort_votes', [], 'plugin:updownbored');
    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">window.updownbored = window.updownbored || {};window.updownbored.apiUrl = ' . json_encode($apiUrl) . ';window.updownbored.baseUrl = ' . json_encode($baseUrl) . ';window.updownbored.csrfToken = ' . json_encode($csrfToken) . ';window.updownbored.currentUserId = ' . json_encode($_SESSION['user_id'] ?? 0) . ';window.updownbored.sortLabel = ' . json_encode($sortLabel) . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });

    // Sorting discussions by vote score is owned by this plugin. It adds the
    // "Votes" option to the core sort bar and supplies the matching ORDER BY
    // clause so the whole thread list is sorted server-side (and paginated)
    // by the summed score of each thread's visible posts.
    $pluginManager->addHook('thread_sort_options', function($options) use ($sortLabel) {
        if (is_array($options)) {
            $options['votes'] = $sortLabel;
        }
        return $options;
    });

    $pluginManager->addHook('thread_order_by', function($orderBy, $sort) {
        if ($sort !== 'votes') {
            return $orderBy;
        }
        // Total score = opening-post votes (thread_votes) + votes on every
        // visible reply (post_votes) + legacy opening-post votes that old
        // versions stored in post_votes keyed by the thread id (no posts row).
        return "(
            (SELECT COALESCE(SUM(tv.vote), 0) FROM thread_votes tv WHERE tv.thread_id = t.id)
            +
            (SELECT COALESCE(SUM(pv.vote), 0) FROM post_votes pv INNER JOIN posts p ON p.id = pv.post_id WHERE p.thread_id = t.id AND p.status = 'visible')
            +
            (SELECT COALESCE(SUM(lv.vote), 0) FROM post_votes lv WHERE lv.post_id = t.id AND NOT EXISTS (SELECT 1 FROM posts lp WHERE lp.id = lv.post_id) AND NOT EXISTS (SELECT 1 FROM thread_votes tvx WHERE tvx.thread_id = t.id AND tvx.user_id = lv.user_id))
        ) DESC, t.id DESC";
    });
}
