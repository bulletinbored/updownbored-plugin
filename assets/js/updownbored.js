(function () {
    'use strict';

    var baseUrl = (window.updownbored && window.updownbored.baseUrl) || '';
    var apiUrl = (window.updownbored && window.updownbored.apiUrl) || '';
    var csrfToken = (window.updownbored && window.updownbored.csrfToken) || '';
    var currentUserId = (window.updownbored && window.updownbored.currentUserId) || 0;
    var sortLabel = (window.updownbored && window.updownbored.sortLabel) || 'Votes';

    // Returns the vote target for an article. The opening post has no posts row
    // (its body lives on threads), so its vote is stored per-thread; replies are
    // stored per-post. For the OP data-post-id is the thread id.
    function getTarget(article) {
        var raw = article.getAttribute('data-post-id');
        var id = raw !== null ? parseInt(raw, 10) : 0;
        if (id <= 0) return null;
        var isOp = article.getAttribute('data-is-op') === '1'
            || article.classList.contains('post-op');
        return { type: isOp ? 'thread' : 'post', id: id };
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

    function applyState(w, score, myVote) {
        w.score.textContent = score;
        w.up.classList.toggle('active', myVote === 1);
        w.down.classList.toggle('active', myVote === -1);
        w.score.classList.toggle('positive', score > 0);
        w.score.classList.toggle('negative', score < 0);
    }

    function sendVote(target, vote, w) {
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
                        applyState(w, data.score, data.my_vote);
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
        var isThread = target.type === 'thread';
        xhr.send(
            'action=' + (isThread ? 'vote_thread' : 'vote') +
            (isThread ? '&thread_id=' : '&post_id=') + encodeURIComponent(target.id) +
            '&vote=' + encodeURIComponent(vote) +
            '&csrf_token=' + encodeURIComponent(csrfToken)
        );
    }

    function inject() {
        var articles = document.querySelectorAll('.post');
        var pendingPosts = [];
        var pendingThreads = [];

        articles.forEach(function (article) {
            if (article.querySelector('.updownbored')) return;
            var target = getTarget(article);
            if (!target) return;

            var w = buildWidget();
            w.wrap.setAttribute('data-vote-type', target.type);
            w.wrap.setAttribute('data-vote-id', target.id);

            var side = article.querySelector('.post-side');
            if (side) {
                side.appendChild(w.wrap);
            } else {
                article.insertBefore(w.wrap, article.firstChild);
            }

            w.up.addEventListener('click', function () {
                var myVote = w.up.classList.contains('active') ? 0 : 1;
                sendVote(target, myVote, w);
            });
            w.down.addEventListener('click', function () {
                var myVote = w.down.classList.contains('active') ? 0 : -1;
                sendVote(target, myVote, w);
            });

            if (target.type === 'thread') {
                pendingThreads.push(target.id);
            } else {
                pendingPosts.push(target.id);
            }
        });

        if (pendingPosts.length === 0 && pendingThreads.length === 0) return;

        var query = [];
        if (pendingPosts.length) query.push('post_ids=' + encodeURIComponent(pendingPosts.join(',')));
        if (pendingThreads.length) query.push('thread_ids=' + encodeURIComponent(pendingThreads.join(',')));

        var xhr = new XMLHttpRequest();
        xhr.open('GET', apiUrl + '?' + query.join('&'), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.success) return;
                var postScores = data.scores || {};
                var threadScores = data.thread_scores || {};
                var myPostVotes = data.my_votes || {};
                var myThreadVotes = data.my_thread_votes || {};

                document.querySelectorAll('.updownbored').forEach(function (el) {
                    var type = el.getAttribute('data-vote-type');
                    var id = parseInt(el.getAttribute('data-vote-id'), 10);
                    var s = type === 'thread' ? (threadScores[id] || { score: 0 }) : (postScores[id] || { score: 0 });
                    var v = type === 'thread' ? (myThreadVotes[id] || 0) : (myPostVotes[id] || 0);
                    applyState({
                        wrap: el,
                        up: el.querySelector('.updownbored-up'),
                        down: el.querySelector('.updownbored-down'),
                        score: el.querySelector('.updownbored-score')
                    }, s.score, v);
                });
            } catch (e) {
                console.error('updownbored: failed to load scores', e);
            }
        };
        xhr.send();
    }

    // --- Thread sorting by votes ---

    function getThreadId(discussion) {
        var link = discussion.querySelector('.discussion-title a');
        if (!link) return null;
        var href = link.getAttribute('href') || '';
        // URL format: /thread/5-title-slug
        var match = href.match(/thread\/(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }

    // The "Votes" sort option is rendered server-side by updownbored.php via the
    // thread_sort_options filter. This is only a fallback for cores without that
    // filter: returns true when the option is already present.
    function injectSortOption() {
        var sortBar = document.querySelector('.sort-bar');
        if (!sortBar) return false;

        var existing = sortBar.querySelectorAll('.sort-link');
        for (var i = 0; i < existing.length; i++) {
            if (existing[i].getAttribute('data-sort') === 'votes') return true;
            var href = existing[i].getAttribute('href') || '';
            if (/[?&]sort=votes(?:&|$)/.test(href)) return true;
        }

        var link = document.createElement('a');
        link.className = 'sort-link';
        link.setAttribute('data-sort', 'votes');
        link.href = window.location.pathname + '?sort=votes';
        link.textContent = sortLabel;

        sortBar.appendChild(link);
        return false;
    }

    function sortThreadsByVotes() {
        var list = document.querySelector('.discussion-list');
        if (!list) return;

        var discussions = Array.prototype.slice.call(list.querySelectorAll('.discussion'));
        if (discussions.length === 0) return;

        var threadIds = [];
        discussions.forEach(function (d) {
            var id = getThreadId(d);
            if (id) threadIds.push(id);
        });

        if (threadIds.length === 0) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', apiUrl + '?action=thread_scores&thread_ids=' + encodeURIComponent(threadIds.join(',')), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.success) return;

                discussions.sort(function (a, b) {
                    var idA = getThreadId(a);
                    var idB = getThreadId(b);
                    var scoreA = data.scores[idA] ? data.scores[idA].score : 0;
                    var scoreB = data.scores[idB] ? data.scores[idB].score : 0;
                    if (scoreB !== scoreA) return scoreB - scoreA;

                    var stickyA = a.querySelector('.pill-sticky') ? 1 : 0;
                    var stickyB = b.querySelector('.pill-sticky') ? 1 : 0;
                    if (stickyB !== stickyA) return stickyB - stickyA;

                    return 0;
                });

                discussions.forEach(function (d) {
                    list.appendChild(d);
                });
            } catch (e) {
                console.error('updownbored: failed to sort threads', e);
            }
        };
        xhr.send();
    }

    function updateSortActiveState() {
        var params = new URLSearchParams(window.location.search);
        var currentSort = params.get('sort') || 'latest';

        var sortBar = document.querySelector('.sort-bar');
        if (!sortBar) return;

        sortBar.querySelectorAll('.sort-link').forEach(function (link) {
            var sortKey = link.getAttribute('data-sort');
            if (sortKey) {
                link.classList.toggle('active', sortKey === currentSort);
            } else {
                var href = link.getAttribute('href') || '';
                var hrefParams = new URLSearchParams(href.split('?')[1] || '');
                link.classList.toggle('active', hrefParams.get('sort') === currentSort);
            }
        });
    }

    function initSort() {
        injectSortOption();
        updateSortActiveState();

        // Always re-apply the ordering client-side on the visible page. The
        // server already sorts globally, but this guarantees the rendered order
        // matches the vote scores even if the server-side sort filter is not
        // deployed/active (e.g. older core or stale OPcache).
        var params = new URLSearchParams(window.location.search);
        if (params.get('sort') === 'votes') {
            sortThreadsByVotes();
        }

        document.addEventListener('click', function (e) {
            var link = e.target.closest && e.target.closest('.sort-link[data-sort="votes"]');
            if (link) {
                e.preventDefault();
                var url = new URL(window.location.href);
                url.searchParams.set('sort', 'votes');
                window.location.href = url.toString();
            }
        });
    }

    function init() {
        inject();
        initSort();
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
