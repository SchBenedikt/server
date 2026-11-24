<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real installer in a throwaway checkout.
 *
 * The temporary checkout gets the installer, the three CLI entry points, the
 * example environment and a tiny autoloader that points at this repository.
 * That keeps the test fast while still exercising argument parsing, .env
 * writing, migrations, account creation, the data-directory move and the
 * final doctor check.
 */
final class InstallScriptTest extends TestCase
{
    private string $directory;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->directory = sys_get_temp_dir() . '/alohasocial-install-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/scripts', 0o770, true);
        mkdir($this->directory . '/bin', 0o770, true);
        mkdir($this->directory . '/vendor', 0o770, true);

        copy($this->root . '/scripts/install.sh', $this->directory . '/scripts/install.sh');
        chmod($this->directory . '/scripts/install.sh', 0o755);
        copy($this->root . '/.env.example', $this->directory . '/.env.example');

        foreach (['update.php', 'create-account.php', 'doctor.php'] as $command) {
            copy($this->root . '/bin/' . $command, $this->directory . '/bin/' . $command);
        }

        file_put_contents(
            $this->directory . '/vendor/autoload.php',
            "<?php\nrequire " . var_export($this->root . '/vendor/autoload.php', true) . ";\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testFreshInstallPutsTheDatabaseInTheDataDirectory(): void
    {
        $data = $this->directory . '/data';

        [$status, $output, $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://social.example',
            '--name=Test Instance',
            '--data-dir=' . $data,
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
        ]);

        self::assertSame(0, $status, $error . $output);
        self::assertStringContainsString('Aloha Social is installed.', $output);
        self::assertFileExists($data . '/server.sqlite');
        self::assertFileExists($data . '/secret.key');

        $environment = $this->environment();
        self::assertSame('https://social.example', $environment['ALOHASOCIAL_INSTANCE_URL']);
        self::assertSame('sqlite:' . $data . '/server.sqlite', $environment['ALOHASOCIAL_DB_DSN']);
        self::assertSame($data, $environment['ALOHASOCIAL_VAR_DIRECTORY']);
        self::assertSame($data . '/blobs', $environment['ALOHASOCIAL_BLOB_DIRECTORY']);
        self::assertSame($data . '/secret.key', $environment['ALOHASOCIAL_SECRET_KEY_FILE']);
        self::assertSame($data . '/mail', $environment['ALOHASOCIAL_MAIL_LOG_DIR']);

        self::assertSame(1, $this->accountCount($data . '/server.sqlite'));
    }

    public function testRerunWithoutOptionsKeepsTheExistingConfiguration(): void
    {
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://keep.example',
            '--data-dir=' . $this->directory . '/data',
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        $before = file_get_contents($this->directory . '/.env');
        self::assertIsString($before);

        [$status, $output, $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--no-admin',
            '--no-verify',
        ]);

        self::assertSame(0, $status, $error);
        self::assertSame($before, file_get_contents($this->directory . '/.env'));
        self::assertSame(1, $this->accountCount($this->directory . '/data/server.sqlite'));
    }

    public function testFreshInteractiveInstallCreatesAnAdmin(): void
    {
        [$status, $output, $error] = $this->runInstaller(
            [
                '-i',
                '--skip-composer',
                '--handle=bob',
                '--email=bob@example.test',
                '--password=correct-horse-battery',
                '--no-verify',
            ],
            str_repeat("\n", 8),
        );

        self::assertSame(0, $status, $error . $output);
        self::assertFileExists($this->directory . '/var/server.sqlite');
        self::assertSame(1, $this->accountCount($this->directory . '/var/server.sqlite'));
        self::assertSame('http://localhost:3000', $this->environment()['ALOHASOCIAL_INSTANCE_URL']);
    }

    public function testInteractiveRerunKeepsTheInstanceUrlOnEnter(): void
    {
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://keep.example',
            '--data-dir=' . $this->directory . '/data',
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        [$status, $output, $error] = $this->runInstaller(
            ['-i', '--skip-composer', '--no-admin', '--no-verify'],
            str_repeat("\n", 8),
        );

        self::assertSame(0, $status, $error . $output);
        self::assertStringContainsString('existing .env detected', $output);

        $environment = $this->environment();
        self::assertSame('https://keep.example', $environment['ALOHASOCIAL_INSTANCE_URL']);
        self::assertSame('sqlite:' . $this->directory . '/data/server.sqlite', $environment['ALOHASOCIAL_DB_DSN']);
        self::assertSame($this->directory . '/data', $environment['ALOHASOCIAL_VAR_DIRECTORY']);
        self::assertSame(1, $this->accountCount($this->directory . '/data/server.sqlite'));
    }

    public function testInteractiveDataDirectoryChangeCopiesTheDatabase(): void
    {
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://interactive.example',
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        $data = $this->directory . '/data';
        // URL, name, data directory, database menu, SQLite DSN, mail menu,
        // service templates and confirmation: all defaults except the path.
        $input = "\n\n{$data}\n\n\n\n\n\n";
        [$status, $output, $error] = $this->runInstaller(
            ['-i', '--skip-composer', '--no-admin', '--no-verify'],
            $input,
        );

        self::assertSame(0, $status, $error . $output);
        self::assertFileExists($data . '/server.sqlite');
        self::assertSame(1, $this->accountCount($data . '/server.sqlite'));
        self::assertSame(
            'sqlite:' . $data . '/server.sqlite',
            $this->environment()['ALOHASOCIAL_DB_DSN'],
        );
    }

    public function testSmtpConfigurationIsWrittenForUnattendedInstalls(): void
    {
        $data = $this->directory . '/data';
        [$status, $output, $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://mail.example',
            '--data-dir=' . $data,
            '--no-admin',
            '--mail-transport=smtp',
            '--mail-from=aloha@mail.example',
            '--smtp-host=mail.example',
            '--smtp-port=465',
            '--smtp-user=aloha',
            '--smtp-password=correct-horse-battery',
            '--smtp-encryption=tls',
            '--no-verify',
        ]);

        self::assertSame(0, $status, $error . $output);
        $environment = $this->environment();
        self::assertSame('smtp', $environment['ALOHASOCIAL_MAIL_TRANSPORT']);
        self::assertSame('aloha@mail.example', $environment['ALOHASOCIAL_MAIL_FROM']);
        self::assertSame('mail.example', $environment['ALOHASOCIAL_SMTP_HOST']);
        self::assertSame('465', $environment['ALOHASOCIAL_SMTP_PORT']);
        self::assertSame('aloha', $environment['ALOHASOCIAL_SMTP_USER']);
        self::assertSame('correct-horse-battery', $environment['ALOHASOCIAL_SMTP_PASSWORD']);
        self::assertSame('tls', $environment['ALOHASOCIAL_SMTP_ENCRYPTION']);
    }

    public function testInteractiveRerunKeepsASeparateBlobPath(): void
    {
        $data = $this->directory . '/data';
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--data-dir=' . $data,
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        $media = $this->directory . '/media';
        mkdir($media, 0o770, true);
        $env = (string) file_get_contents($this->directory . '/.env');
        $env = preg_replace(
            '/^ALOHASOCIAL_BLOB_DIRECTORY=.*$/m',
            'ALOHASOCIAL_BLOB_DIRECTORY=' . $media,
            $env,
        );
        self::assertIsString($env);
        file_put_contents($this->directory . '/.env', $env);

        [$status, $output, $error] = $this->runInstaller(
            ['-i', '--skip-composer', '--no-admin', '--no-verify'],
            str_repeat("\n", 8),
        );

        self::assertSame(0, $status, $error . $output);
        self::assertSame($media, $this->environment()['ALOHASOCIAL_BLOB_DIRECTORY']);
        self::assertDirectoryExists($media);
        self::assertSame(1, $this->accountCount($data . '/server.sqlite'));
    }

    public function testForceResetKeepsAnExistingCustomDataDirectory(): void
    {
        $data = $this->directory . '/data';
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://force.example',
            '--data-dir=' . $data,
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        [$status, $output, $error] = $this->runInstaller([
            '--force',
            '--non-interactive',
            '--skip-composer',
            '--no-admin',
            '--no-verify',
        ]);

        self::assertSame(0, $status, $error . $output);
        self::assertStringContainsString('keeps the existing database and runtime paths', $output);
        self::assertSame($data, $this->environment()['ALOHASOCIAL_VAR_DIRECTORY']);
        self::assertSame('sqlite:' . $data . '/server.sqlite', $this->environment()['ALOHASOCIAL_DB_DSN']);
        self::assertSame(1, $this->accountCount($data . '/server.sqlite'));
    }

    public function testChangingTheDataDirectoryCopiesDatabaseKeyAndMedia(): void
    {
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://move.example',
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
            '--no-verify',
        ]);
        self::assertSame(0, $status, $error);

        mkdir($this->directory . '/var/blobs/2026/11', 0o770, true);
        file_put_contents($this->directory . '/var/blobs/2026/11/photo.jpg', 'image-bytes');
        $key = file_get_contents($this->directory . '/var/secret.key');
        self::assertIsString($key);

        $data = $this->directory . '/data';
        [$status, $output, $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--no-admin',
            '--data-dir=' . $data,
            '--no-verify',
        ]);

        self::assertSame(0, $status, $error . $output);
        self::assertSame(1, $this->accountCount($data . '/server.sqlite'));
        self::assertSame($key, file_get_contents($data . '/secret.key'));
        self::assertSame('image-bytes', file_get_contents($data . '/blobs/2026/11/photo.jpg'));

        $environment = $this->environment();
        self::assertSame('sqlite:' . $data . '/server.sqlite', $environment['ALOHASOCIAL_DB_DSN']);
        self::assertSame($data, $environment['ALOHASOCIAL_VAR_DIRECTORY']);
    }

    public function testInvalidConfigurationFailsBeforeWritingAnything(): void
    {
        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--url=https://social.example/path',
            '--handle=alice',
            '--email=alice@example.test',
            '--password=correct-horse-battery',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('must be an origin', $error);
        self::assertFileDoesNotExist($this->directory . '/.env');
        self::assertDirectoryDoesNotExist($this->directory . '/var');
    }

    public function testFailedEnvironmentUpdateLeavesTheExistingConfigurationUntouched(): void
    {
        $original = file_get_contents($this->root . '/.env.example');
        self::assertIsString($original);
        $original = str_replace(
            ['ALOHASOCIAL_INSTANCE_URL=http://localhost:3000', 'ALOHASOCIAL_INSTANCE_NAME=Aloha Social'],
            ['ALOHASOCIAL_INSTANCE_URL=https://old.example', 'ALOHASOCIAL_INSTANCE_NAME=Old Instance'],
            $original,
        );
        file_put_contents($this->directory . '/.env', $original);
        chmod($this->directory . '/.env', 0o600);

        $bin = $this->directory . '/fake-bin';
        mkdir($bin);
        $counter = $this->directory . '/env-write-count';
        $wrapper = "#!/bin/sh\n"
            . "if [ \"\${1:-}\" = '-r' ]; then\n"
            . "  case \"\${2:-}\" in\n"
            . "    *'stream_get_contents(STDIN)'*)\n"
            . "      count=0\n"
            . "      [ ! -f \"\$INSTALLER_ENV_WRITE_COUNT\" ] || count=\$(cat \"\$INSTALLER_ENV_WRITE_COUNT\")\n"
            . "      count=\$((count + 1))\n"
            . "      printf '%s' \"\$count\" > \"\$INSTALLER_ENV_WRITE_COUNT\"\n"
            . "      [ \"\$count\" -lt 2 ] || exit 73\n"
            . "      ;;\n"
            . "  esac\n"
            . "fi\n"
            . 'exec ' . escapeshellarg(PHP_BINARY) . " \"\$@\"\n";
        file_put_contents($bin . '/php', $wrapper);
        chmod($bin . '/php', 0o755);

        [$status, , $error] = $this->runInstaller([
            '--non-interactive',
            '--skip-composer',
            '--no-admin',
            '--no-verify',
            '--url=https://new.example',
            '--name=New Instance',
        ], environmentOverrides: [
            'PATH' => $bin . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'INSTALLER_ENV_WRITE_COUNT' => $counter,
        ]);

        self::assertSame(73, $status, $error);
        self::assertSame($original, file_get_contents($this->directory . '/.env'));
        self::assertSame([], glob($this->directory . '/.env.tmp.*') ?: []);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function runInstaller(array $arguments, string $input = '', array $environmentOverrides = []): array
    {
        $command = [
            'bash',
            $this->directory . '/scripts/install.sh',
            ...$arguments,
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        foreach (array_keys($environment) as $key) {
            if (str_starts_with($key, 'ALOHASOCIAL_')) {
                unset($environment[$key]);
            }
        }
        $environment = array_merge($environment, $environmentOverrides);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->directory, $environment);
        self::assertIsResource($process);

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, (string) $output, (string) $error];
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $values = [];
        $lines = file($this->directory . '/.env', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = trim($value, "\"'");
        }

        return $values;
    }

    private function accountCount(string $database): int
    {
        $pdo = new PDO('sqlite:' . $database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return (int) $pdo->query('SELECT COUNT(*) FROM account')->fetchColumn();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
