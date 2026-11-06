/* Guest Wi-Fi management console. External file so the page's CSP can forbid inline script. */
(function () {
    'use strict';

    function $(selector) {
        return document.querySelector(selector);
    }

    /* Clicks are delegated, so buttons inside refreshed content keep working. */
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target : e.target.parentNode;
        var hit;

        if ((hit = t.closest('[data-toggle-side]'))) {
            $('#app').classList.toggle('side-open');
            return;
        }

        var menu = $('#user-menu');
        if ((hit = t.closest('[data-toggle-menu]'))) {
            menu.hidden = !menu.hidden;
            hit.setAttribute('aria-expanded', String(!menu.hidden));
            return;
        }
        if (menu && !menu.hidden && !t.closest('#user-menu')) {
            menu.hidden = true;
        }

        if ((hit = t.closest('[data-reveal]'))) {
            var input = hit.parentNode.querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            hit.textContent = show ? 'Hide' : 'Show';
            return;
        }

        if ((hit = t.closest('[data-copy]'))) {
            var text = $(hit.getAttribute('data-copy')).textContent;
            var range = document.createRange();
            range.selectNodeContents($(hit.getAttribute('data-copy')));
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            // Clipboard API needs https; on plain http the selection is left for Ctrl+C.
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { hit.textContent = 'Copied'; });
            } else if (document.execCommand('copy')) {
                hit.textContent = 'Copied';
            }
            return;
        }

        if (t.closest('[data-confirm-no]')) {
            closeConfirm();
            return;
        }
        if (t.closest('[data-confirm-yes]')) {
            var form = pending;
            closeConfirm();
            if (form) {
                form.removeAttribute('data-confirm');
                form.submit();
            }
        }
    });

    /* Confirmation dialog for destructive actions. */
    var pending = null;

    function closeConfirm() {
        pending = null;
        $('#confirm').hidden = true;
    }

    document.addEventListener('submit', function (e) {
        var message = e.target.getAttribute('data-confirm');
        if (!message) {
            return;
        }
        e.preventDefault();
        pending = e.target;
        $('#confirm-text').textContent = message;
        $('#confirm').hidden = false;
        $('[data-confirm-yes]').focus();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $('#confirm') && !$('#confirm').hidden) {
            closeConfirm();
        }
    });

    /* Chart hover: one tooltip per band, and the crosshair on line charts. */
    function moveTip(e) {
        var tip = $('#tip');
        var x = e.clientX + 14;
        var y = e.clientY + 14;
        if (x + tip.offsetWidth > window.innerWidth - 8) {
            x = e.clientX - tip.offsetWidth - 14;
        }
        if (y + tip.offsetHeight > window.innerHeight - 8) {
            y = e.clientY - tip.offsetHeight - 14;
        }
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    }

    function fillTip(data) {
        var tip = $('#tip');
        tip.textContent = '';

        var title = document.createElement('div');
        title.className = 'tip-title';
        title.textContent = data.t;
        tip.appendChild(title);

        data.r.forEach(function (row) {
            var line = document.createElement('div');
            line.className = 'tip-row';

            var key = document.createElement('i');
            key.className = 'tip-key ' + row[0];
            var value = document.createElement('b');
            value.textContent = row[2];
            var name = document.createElement('span');
            name.textContent = row[1];

            line.appendChild(key);
            line.appendChild(value);
            line.appendChild(name);
            tip.appendChild(line);
        });
    }

    document.addEventListener('pointermove', function (e) {
        var tip = $('#tip');
        if (!tip) {
            return;
        }

        var band = e.target.getAttribute && e.target.getAttribute('data-tip') ? e.target : null;
        document.querySelectorAll('.cross').forEach(function (line) {
            line.setAttribute('hidden', '');
        });

        if (!band) {
            tip.hidden = true;
            return;
        }

        fillTip(JSON.parse(band.getAttribute('data-tip')));
        tip.hidden = false;
        moveTip(e);

        var x = band.getAttribute('data-x');
        var cross = band.ownerSVGElement.querySelector('.cross');
        if (x && cross) {
            cross.setAttribute('x1', x);
            cross.setAttribute('x2', x);
            cross.removeAttribute('hidden');
        }
    });

    /* Keeps monitoring pages current. Skipped while someone is typing or a
       dialog is open. The header tells the server this is not user activity,
       so a screen left open still times out. */
    var content = $('#content');
    var seconds = content ? parseInt(content.getAttribute('data-refresh'), 10) : 0;

    if (seconds > 0) {
        setInterval(function () {
            var active = document.activeElement;
            var typing = active && (active.tagName === 'INPUT' || active.tagName === 'SELECT');
            if (document.hidden || typing || !$('#confirm').hidden) {
                return;
            }

            var url = location.search.indexOf('?') === 0 ? location.search + '&partial=1' : '?partial=1';
            fetch('admin.php' + url, { credentials: 'same-origin', headers: { 'X-GW-Poll': '1' } })
                .then(function (res) {
                    if (res.status === 401) {
                        location.href = 'admin.php?expired=1';
                        return null;
                    }
                    return res.ok ? res.text() : null;
                })
                .then(function (html) {
                    if (html) {
                        content.innerHTML = html;
                    }
                })
                .catch(function () { /* next tick retries */ });
        }, seconds * 1000);
    }

    if (document.body.getAttribute('data-autoprint')) {
        window.print();
    }
}());
