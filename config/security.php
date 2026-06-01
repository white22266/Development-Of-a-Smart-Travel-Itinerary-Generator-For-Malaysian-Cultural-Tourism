<?php
// config/security.php
// Common security helpers for forms, AJAX requests, and POST endpoints.

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
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (defined('CSRF_SKIP') && CSRF_SKIP === true) {
            return;
        }

        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = csrf_request_token();

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            exit('Invalid request token. Please go back and try again.');
        }
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

                if (!preg_match('/method\s*=\s*(["\'])?post\1?/i', $attributes)) {
                    return $formTag;
                }

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

        return $html;
    }
}

if (!function_exists('csrf_enable_auto_injection')) {
    function csrf_enable_auto_injection(): void
    {
        if (defined('CSRF_AUTO_INJECTION_STARTED')) {
            return;
        }
        define('CSRF_AUTO_INJECTION_STARTED', true);

        ob_start(function (string $buffer): string {
            if ($buffer === '') {
                return $buffer;
            }

            if (stripos($buffer, '<html') === false && stripos($buffer, '<form') === false) {
                return $buffer;
            }

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

if (!defined('CSRF_DISABLE_AUTO_VERIFY') || CSRF_DISABLE_AUTO_VERIFY !== true) {
    verify_csrf_token();
}

if (!defined('CSRF_DISABLE_AUTO_INJECTION') || CSRF_DISABLE_AUTO_INJECTION !== true) {
    csrf_enable_auto_injection();
}
