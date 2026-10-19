<?php

declare(strict_types=1);

namespace Sso\Contracts;

use Sso\Data\ExternalIdentity;

interface LooksUpAccounts extends AcceptsAccountIds
{
    /**
     * @return bool
     */
    public function canLookUp(): bool;

    /**
     * Returns null when no account has this ID, and throws when the provider cannot be asked.
     *
     * @param string
     * @return ExternalIdentity|null
     */
    public function lookUp(string $id): ?ExternalIdentity;
}
