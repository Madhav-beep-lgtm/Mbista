<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

/**
 * Retired. /login.php is the one and only sign-in page for every role; an admin
 * who authenticates there is routed to portal.php by role_home_path(), picks an
 * organization, and clears the company PIN before any admin screen opens.
 *
 * This file stays in the repo as a permanent redirect rather than being deleted:
 * deploy/tasks.sh ships with `rsync -a` and no `--delete`, so a deleted file is
 * never pruned from the live docroot — the old sign-in form would keep serving
 * itself at /admin/login.php forever. Overwriting it is what actually retires it.
 */
header('Location: ' . url('login.php'), true, 301);
exit;
