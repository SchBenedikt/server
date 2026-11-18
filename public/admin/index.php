<?php

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * The admin assets live in a real `public/admin/` directory. Apache and Nginx
 * resolve `/admin` to that directory before the front controller sees it; with
 * directory listings disabled, that produced a 403 on production domains.
 *
 * This index is an internal forwarder only. The original REQUEST_URI stays
 * `/admin`, so the front controller can perform its normal session and role
 * checks before rendering the console.
 */

require dirname(__DIR__) . '/index.php';
