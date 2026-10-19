<?php

declare(strict_types=1);

namespace Sso\Contracts;

interface AcceptsAccountIds extends IdentityProvider
{
    /**
     * A regular expression, without delimiters, that every account ID of this provider matches.
     * Admins enter these IDs on the user forms, so they must be stable and public.
     *
     * @return string
     */
    public function accountIdPattern(): string;

    /**
     * @return string
     */
    public function accountIdHint(): string;
}
