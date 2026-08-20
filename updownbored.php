<?php
/**
 * Plugin Name: updownbored
 * Version: 1.0.0
 * Author: mlzog
 * Description: Upvote and downvote individual posts
 * License: MIT License
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
                    UNIQUE KEY uniq_post_user (post_id, user_id)
                )
            ");
            try { $pdo->exec("CREATE INDEX idx_post_votes_post_id ON post_votes(post_id)"); } catch (Throwable $e) {}
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
        }
    }

    $udVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $udVer('assets/css/updownbored.css');
    $jsUrl = $udVer('assets/js/updownbored.js');
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script>window.updownbored = window.updownbored || {};window.updownbored.apiUrl = ' . json_encode($apiUrl) . ';window.updownbored.baseUrl = ' . json_encode($baseUrl) . ';window.updownbored.csrfToken = ' . json_encode($csrfToken) . ';window.updownbored.currentUserId = ' . json_encode($_SESSION['user_id'] ?? 0) . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '" onload="window.updownbored=window.updownbored||{};window.updownbored.init&&window.updownbored.init()"></script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}
