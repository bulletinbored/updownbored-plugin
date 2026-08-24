(function () {
    'use strict';

    var baseUrl = (window.updownbored && window.updownbored.baseUrl) || '';
    var apiUrl = (window.updownbored && window.updownbored.apiUrl) || '';
    var csrfToken = (window.updownbored && window.updownbored.csrfToken) || '';
    var currentUserId = (window.updownbored && window.updownbored.currentUserId) || 0;

    function getPostId(article) {
        var raw = article.getAttribute('data-post-id');
        var pid = raw !== null ? parseInt(raw, 10) : 0;
        return pid > 0 ? pid : null;
    }

    function buildWidget() {
        var wrap = document.createElement('div');
        wrap.className = 'updownbored';

        var up = document.createElement('button');
        up.type = 'button';
        up.className = 'updownbored-btn updownbored-up';
        up.setAttribute('aria-label', 'Upvote');
        up.innerHTML = '<i class="fas fa-arrow-up"></i>';

        var score = document.createElement('span');
        score.className = 'updownbored-score';
        score.textContent = '0';

        var down = document.createElement('button');
        down.type = 'button';
        down.className = 'updownbored-btn updownbored-down';
        down.setAttribute('aria-label', 'Downvote');
        down.innerHTML = '<i class="fas fa-arrow-down"></i>';

        wrap.appendChild(up);
        wrap.appendChild(score);
        wrap.appendChild(down);
        wrap.classList.add('updownbored-horizontal');

        return { wrap: wrap, up: up, down: down, score: score };
    }

    function applyState(w, postId, score, myVote) {
        w.score.textContent = score;
        w.up.classList.toggle('active', myVote === 1);
        w.down.classList.toggle('active', myVote === -1);
        w.score.classList.toggle('positive', score > 0);
        w.score.classList.toggle('negative', score < 0);
    }

    function sendVote(postId, vote, w) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        applyState(w, data.post_id, data.score, data.my_vote);
                    }
                } catch (e) {
                    console.error('updownbored: failed to parse response', e);
                }
            } else if (xhr.status === 403) {
                console.warn('updownbored: login required to vote');
                w.wrap.title = 'Devi effettuare il login per votare';
            } else {
                console.error('updownbored: vote failed with status ' + xhr.status);
            }
        };
        xhr.send(
            'action=vote&post_id=' + encodeURIComponent(postId) +
            '&vote=' + encodeURIComponent(vote) +
            '&csrf_token=' + encodeURIComponent(csrfToken)
        );
    }

    function inject() {
        var articles = document.querySelectorAll('.post');
        var pending = [];

        articles.forEach(function (article) {
            if (article.querySelector('.updownbored')) return;
            var postId = getPostId(article);
            if (!postId) return;

            var w = buildWidget();
            w.wrap.setAttribute('data-post-id', postId);

            var side = article.querySelector('.post-side');
            if (side) {
                side.appendChild(w.wrap);
            } else {
                article.insertBefore(w.wrap, article.firstChild);
            }

            w.up.addEventListener('click', function () {
                var myVote = w.up.classList.contains('active') ? 0 : 1;
                sendVote(postId, myVote, w);
            });
            w.down.addEventListener('click', function () {
                var myVote = w.down.classList.contains('active') ? 0 : -1;
                sendVote(postId, myVote, w);
            });

            pending.push(postId);
        });

        if (pending.length === 0) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', apiUrl + '?post_ids=' + encodeURIComponent(pending.join(',')), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.success) return;
                document.querySelectorAll('.updownbored').forEach(function (el) {
                    var pid = parseInt(el.getAttribute('data-post-id'), 10);
                    var s = data.scores[pid] || { score: 0 };
                    var v = data.my_votes[pid] || 0;
                    applyState({
                        wrap: el,
                        up: el.querySelector('.updownbored-up'),
                        down: el.querySelector('.updownbored-down'),
                        score: el.querySelector('.updownbored-score')
                    }, pid, s.score, v);
                });
            } catch (e) {
                console.error('updownbored: failed to load scores', e);
            }
        };
        xhr.send();
    }

    function init() {
        inject();
        if (document.addEventListener) {
            var mo = window.MutationObserver;
            var firstPost = document.querySelector('.post');
            if (mo && firstPost) {
                var target = firstPost.parentNode;
                var obs = new mo(function () { inject(); });
                obs.observe(target, { childList: true, subtree: true });
            }
        }
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[data-updown-refresh]');
            if (a) inject();
        });
    }

    window.updownbored = window.updownbored || {};
    window.updownbored.init = init;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
