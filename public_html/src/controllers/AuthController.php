<?php
// public_html/src/controllers/AuthController.php

declare(strict_types=1);

require_once SRC_PATH . '/lib/auth.php';
require_once SRC_PATH . '/lib/csrf.php';
require_once SRC_PATH . '/lib/response.php';
require_once SRC_PATH . '/lib/view.php';

class AuthController {

    public function showLogin(): void {
        if (is_logged_in()) { redirect('/'); }
        $next  = self::safeNext((string)($_GET['next'] ?? '/'));
        $error = $_GET['err'] ?? '';
        render('auth/login.php', [
            'title' => 'sign in · my little cookbook',
            'next'  => $next,
            'error' => $error,
        ]);
    }

    public function postLogin(): void {
        csrf_require();
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $next  = self::safeNext((string)($_POST['next'] ?? '/'));
        if ($email === '' || $pass === '') {
            redirect('/login?err=' . urlencode('Please enter email and password.') . '&next=' . urlencode($next));
        }
        if (!auth_login($email, $pass)) {
            redirect('/login?err=' . urlencode('Wrong email or password.') . '&next=' . urlencode($next));
        }
        redirect($next);
    }

    public function postLogout(): void {
        csrf_require();
        auth_logout();
        redirect('/login');
    }

    /**
     * Restrict ?next= to same-origin paths. Rejects schemed URLs and
     * protocol-relative ("//evil.com") / backslash-prefixed forms that some
     * browsers treat as authority components.
     */
    private static function safeNext(string $next): string {
        if ($next === '' || $next[0] !== '/') return '/';
        if (strlen($next) > 1 && ($next[1] === '/' || $next[1] === '\\')) return '/';
        // Strip control chars and leading whitespace tricks just in case.
        if (preg_match('/[\x00-\x1F\x7F]/', $next)) return '/';
        return $next;
    }
}
