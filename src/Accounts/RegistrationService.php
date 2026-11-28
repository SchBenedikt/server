<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use AlohaSocial\Server\Config\HostConfig;
use PDOException;

/**
 * Public self-registration.
 *
 * The operator enables it with ALOHASOCIAL_REGISTRATIONS. When approval is
 * required the account is created disabled, so it cannot sign in until an
 * administrator enables it from the admin console.
 */
final class RegistrationService
{
    public function __construct(
        private readonly HostConfig $config,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly ?Connection $db = null,
        private readonly ?EmailVerificationService $verification = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->registrations;
    }

    public function approvalRequired(): bool
    {
        return $this->config->registrationApproval;
    }

    public function emailVerificationRequired(): bool
    {
        return $this->verification !== null && $this->config->registrationEmailVerification;
    }

    /**
     * @throws RegistrationException
     */
    public function register(
        string $handle,
        string $email,
        string $password,
        ?string $displayName = null,
    ): Account {
        if (!$this->enabled()) {
            throw new RegistrationException('Registration is closed on this instance.');
        }

        $handle = strtolower(trim($handle));
        $email = strtolower(trim($email));
        $displayName = $displayName === null ? null : trim($displayName);

        $fields = [];
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,29}$/', $handle) !== 1) {
            $fields['handle'] = '2–30 characters: lowercase letters, digits, "_" or "-".';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Please enter a valid email address.';
        }
        $passwordError = PasswordPolicy::error($password);
        if ($passwordError !== null) {
            $fields['password'] = $passwordError;
        }
        if ($displayName !== null && mb_strlen($displayName) > 80) {
            $fields['display_name'] = 'At most 80 characters.';
        }
        if ($fields !== []) {
            throw new RegistrationException('Please check the account details.', 422, $fields);
        }

        if ($this->accounts->exists($handle, $email)) {
            throw new RegistrationException('Handle or email address is already in use.');
        }

        $verify = $this->emailVerificationRequired();
        $create = function () use ($handle, $email, $password, $displayName, $verify): Account {
            // Approval-required or unverified accounts are inserted disabled.
            // There is no active intermediate state for a concurrent login to
            // observe and no second write that could fail open.
            $account = $this->accounts->create(
                $handle,
                $email,
                $this->hasher->hash($password),
                Account::ROLE_USER,
                $displayName,
                $this->approvalRequired() || $verify,
            );

            if ($verify) {
                $this->verification?->issue($account);
            }

            return $account;
        };

        try {
            return $this->db === null ? $create() : $this->db->transaction($create);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RegistrationException('Handle or email address is already in use.', 409);
            }

            throw $exception;
        }
    }
}
