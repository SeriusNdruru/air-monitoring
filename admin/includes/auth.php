<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

function requireAdmin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function redirectLoggedAdmin(): void
{
    if (!empty($_SESSION['admin_id'])) {
        header('Location: index.php');
        exit;
    }
}

function adminDisplayName(): string
{
    $fullName = $_SESSION['admin_full_name'] ?? 'Admin';

    return is_string($fullName) ? $fullName : 'Admin';
}