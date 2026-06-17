<?php
// config/security.php
// Common security helpers for forms, AJAX requests, and POST endpoints.
// This file intentionally contains no itinerary-view route or cost scripts.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_request_token')) {
    function csrf_request_token(): string
    {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (is_string($postedToken) && $postedToken !== '') {
            return $postedToken;
        }
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($headerToken) ? $headerToken : '';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
        if (defined('CSRF_SKIP') && CSRF_SKIP === true) return;

        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = csrf_request_token();
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            exit('Invalid request token. Please go back and try again.');
        }
    }
}

if (!function_exists('is_itinerary_review_page')) {
    function is_itinerary_review_page(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return str_contains($script, 'itinerary_review.php') || str_contains($uri, 'itinerary_review.php');
    }
}

if (!function_exists('itinerary_review_keep_fix_assets')) {
    function itinerary_review_keep_fix_assets(): array
    {
        $css = <<<'HTML'
<style id="itinerary-review-keep-fix-style">
.place-card.confirmed { border-color: #16a34a !important; background: #f0fdf4 !important; }
.place-card.confirmed .place-actions button { display: none !important; }
.place-card.confirmed .review-status { display: inline-flex; align-items: center; justify-content: center; background: #dcfce7 !important; color: #15803d !important; border: 1px solid rgba(34, 197, 94, .28); }
.confirmed-note { background: #dcfce7 !important; color: #15803d !important; }
.hotel-empty-state { margin-top: 10px; padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(245, 158, 11, .32); background: #fffbeb; color: #78350f; font-size: 12.5px; line-height: 1.45; }
.hotel-empty-state strong { display: block; margin-bottom: 4px; color: #92400e; font-size: 13px; }
</style>
HTML;

        $script = <<<'HTML'
<script id="itinerary-review-keep-fix-script">
(function () {
    function markConfirmed(itemId) {
        var card = document.getElementById('card-' + itemId);
        if (!card) return;
        card.classList.remove('rejected', 'replacing');
        card.classList.add('accepted', 'confirmed');
        card.dataset.status = 'accepted';
        card.dataset.confirmed = '1';
        ['btn-accept-', 'btn-reject-', 'btn-replace-'].forEach(function (prefix) {
            var button = document.getElementById(prefix + itemId);
            if (!button) return;
            button.style.display = 'none';
            button.disabled = true;
        });
        var status = document.getElementById('status-' + itemId);
        if (status) {
            status.textContent = '✔ Confirmed';
            status.setAttribute('aria-label', 'Confirmed');
        }
        var replacement = document.getElementById('replacement-' + itemId);
        if (replacement && replacement.classList.contains('visible')) {
            replacement.innerHTML = '<span class="pending-change-note confirmed-note">✔ Confirmed replacement</span>';
        }
    }

    function restoreReviewButtons() {
        document.querySelectorAll('.place-card').forEach(function (card) {
            card.classList.remove('confirmed');
            card.dataset.confirmed = '0';
            var itemId = card.dataset.itemId;
            if (!itemId) return;
            ['btn-accept-', 'btn-reject-', 'btn-replace-'].forEach(function (prefix) {
                var button = document.getElementById(prefix + itemId);
                if (!button) return;
                button.style.display = '';
                button.disabled = false;
            });
            var status = document.getElementById('status-' + itemId);
            if (status && status.textContent.indexOf('Confirmed') !== -1) {
                status.textContent = 'Kept';
                status.removeAttribute('aria-label');
            }
        });
    }

    function installKeepFix() {
        if (!document.querySelector('.place-card') || !document.getElementById('btn-confirm')) return;
        if (typeof window.acceptPlace === 'function' && !window.acceptPlace.__keepFixApplied) {
            var originalAcceptPlace = window.acceptPlace;
            var wrappedAcceptPlace = function (itemId) { originalAcceptPlace(itemId); markConfirmed(itemId); };
            wrappedAcceptPlace.__keepFixApplied = true;
            window.acceptPlace = wrappedAcceptPlace;
        }
        if (typeof window.resetAll === 'function' && !window.resetAll.__keepFixApplied) {
            var originalResetAll = window.resetAll;
            var wrappedResetAll = function () { originalResetAll(); restoreReviewButtons(); };
            wrappedResetAll.__keepFixApplied = true;
            window.resetAll = wrappedResetAll;
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installKeepFix); else installKeepFix();
})();
</script>
HTML;

        return [$css, $script];
    }
}

if (!function_exists('csrf_inject_html')) {
    function csrf_inject_html(string $html): string
    {
        $token = csrf_token();
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        if (stripos($html, '<form') !== false) {
            $html = preg_replace_callback('/<form\b([^>]*)>/i', function (array $matches) use ($escapedToken): string {
                $attributes = $matches[1] ?? '';
                $formTag = $matches[0];
                if (!preg_match('/method\s*=\s*(["\'])?post\1?/i', $attributes)) return $formTag;
                return $formTag . "\n" . '<input type="hidden" name="csrf_token" value="' . $escapedToken . '">';
            }, $html) ?? $html;
        }

        if (stripos($html, '</head>') !== false && stripos($html, 'name="csrf-token"') === false) {
            $csrfHead = "\n" . '<meta name="csrf-token" content="' . $escapedToken . '">' . "\n"
                . '<script>' . "\n"
                . '(function(){' . "\n"
                . '  var tokenEl = document.querySelector(\'meta[name="csrf-token"]\');' . "\n"
                . '  if (!tokenEl || !window.fetch) return;' . "\n"
                . '  var token = tokenEl.getAttribute("content");' . "\n"
                . '  var originalFetch = window.fetch;' . "\n"
                . '  window.fetch = function(input, init) {' . "\n"
                . '    init = init || {};' . "\n"
                . '    var method = (init.method || "GET").toUpperCase();' . "\n"
                . '    if (["POST", "PUT", "PATCH", "DELETE"].indexOf(method) !== -1) {' . "\n"
                . '      var requestUrl = typeof input === "string" ? input : (input && input.url ? input.url : "");' . "\n"
                . '      var sameOrigin = true;' . "\n"
                . '      try { sameOrigin = new URL(requestUrl, window.location.href).origin === window.location.origin; } catch (e) {}' . "\n"
                . '      if (sameOrigin) {' . "\n"
                . '        var headers = new Headers(init.headers || (input && input.headers) || {});' . "\n"
                . '        if (!headers.has("X-CSRF-Token")) headers.set("X-CSRF-Token", token);' . "\n"
                . '        init.headers = headers;' . "\n"
                . '      }' . "\n"
                . '    }' . "\n"
                . '    return originalFetch(input, init);' . "\n"
                . '  };' . "\n"
                . '})();' . "\n"
                . '</script>' . "\n";
            $html = str_ireplace('</head>', $csrfHead . '</head>', $html);
        }

        if (is_itinerary_review_page() && stripos($html, 'itinerary-review-keep-fix-script') === false) {
            [$reviewCss, $reviewScript] = itinerary_review_keep_fix_assets();
            if (stripos($html, '</head>') !== false && stripos($html, 'itinerary-review-keep-fix-style') === false) {
                $html = str_ireplace('</head>', $reviewCss . "\n</head>", $html);
            }
            if (stripos($html, '</body>') !== false) {
                $loaderScript = '';
                if (stripos($html, 'itinerary_review_hotel_loader.js') === false
                    && stripos($html, 'data-native-hotel-review="1"') === false) {
                    $loaderScript = '<script src="../assets/itinerary_review_hotel_loader.js?v=20260604"></script>' . "\n";
                }
                $html = str_ireplace('</body>', $reviewScript . "\n" . $loaderScript . '</body>', $html);
            } else {
                $html .= $reviewScript;
                if (stripos($html, 'itinerary_review_hotel_loader.js') === false
                    && stripos($html, 'data-native-hotel-review="1"') === false) {
                    $html .= '<script src="../assets/itinerary_review_hotel_loader.js?v=20260604"></script>';
                }
            }
        }

        return $html;
    }
}

if (!function_exists('csrf_enable_auto_injection')) {
    function csrf_enable_auto_injection(): void
    {
        if (defined('CSRF_AUTO_INJECTION_STARTED')) return;
        define('CSRF_AUTO_INJECTION_STARTED', true);
        ob_start(function (string $buffer): string {
            if ($buffer === '') return $buffer;
            if (stripos($buffer, '<html') === false && stripos($buffer, '<form') === false) return $buffer;
            return csrf_inject_html($buffer);
        });
    }
}

if (!function_exists('secure_html')) {
    function secure_html($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

csrf_token();
if (!defined('CSRF_DISABLE_AUTO_VERIFY') || CSRF_DISABLE_AUTO_VERIFY !== true) verify_csrf_token();
if (!defined('CSRF_DISABLE_AUTO_INJECTION') || CSRF_DISABLE_AUTO_INJECTION !== true) csrf_enable_auto_injection();
