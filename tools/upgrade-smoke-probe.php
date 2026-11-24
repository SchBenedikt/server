<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Http\FrontController;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;

$root = $argv[1] ?? '';
$operation = $argv[2] ?? '';
if ($root === '' || !in_array($operation, ['seed', 'verify'], true)) {
	fwrite(STDERR, "Usage: upgrade-smoke-probe.php <server-root> seed|verify [post-id]\n");
	exit(2);
}

require $root . '/vendor/autoload.php';
$runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
$front = new FrontController($runtime);

if ($operation === 'seed') {
	$response = $front->handle(new Request(
		method: 'POST',
		path: '/api/v1/posts',
		headers: ['Content-Type' => 'application/json'],
		body: json_encode(['content' => 'upgrade-smoke-post', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
		userId: 'upgrade-user',
	));
	if ($response->status !== 201) {
		fwrite(STDERR, 'Prior release could not create its post: ' . $response->body . "\n");
		exit(1);
	}

	$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
	$postId = $data['post']['id'] ?? null;
	if (!is_string($postId) || $postId === '') {
		fwrite(STDERR, "Prior release returned no post id.\n");
		exit(1);
	}

	echo $postId;
	exit(0);
}

$postId = $argv[3] ?? '';
if ($postId === '') {
	fwrite(STDERR, "A post id is required for the verify operation.\n");
	exit(2);
}

$response = $front->handle(new Request(
	method: 'GET',
	path: '/api/v1/posts/' . rawurlencode($postId),
	userId: 'upgrade-user',
));
if ($response->status !== 200) {
	fwrite(STDERR, 'Upgraded server could not read the preserved post: ' . $response->body . "\n");
	exit(1);
}
$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
if (($data['post']['content'] ?? null) !== 'upgrade-smoke-post') {
	fwrite(STDERR, "Upgraded server returned incorrect post content.\n");
	exit(1);
}
if ((int) $runtime->database->fetchValue('SELECT COUNT(*) FROM account') !== 1) {
	fwrite(STDERR, "Upgraded server did not preserve exactly one account.\n");
	exit(1);
}

$migrations = new MigrationRunner($runtime->database, new SystemClock(), Migrations::all());
if (count($migrations->applied()) !== count(Migrations::all())) {
	fwrite(STDERR, "Upgraded database is missing current migrations.\n");
	exit(1);
}

echo "Upgrade preserved account, post and current migration set.\n";
