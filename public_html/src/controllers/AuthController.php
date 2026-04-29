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
        $next  = $_GET['next'] ?? '/';
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
        $next  = $_POST['next'] ?? '/';
        if (!filter_var($next, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED) && !str_starts_with($next, '/')) {
            $next = '/';
        }
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
}
