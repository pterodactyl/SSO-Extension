<?php

declare(strict_types=1);

namespace Sso\Exceptions;

use RuntimeException;

final class SsoException extends RuntimeException
{
    public const string STATE = 'state';

    public const string DENIED = 'denied';

    public const string UNAVAILABLE = 'unavailable';

    public const string PROVIDER = 'provider';

    public const string UNLINKED = 'unlinked';

    public const string TAKEN = 'taken';

    public const string FORBIDDEN = 'forbidden';

    /**
     * @var string
     */
    public readonly string $reason;

    /**
     * @param string
     */
    public function __construct(string $reason)
    {
        parent::__construct('Single sign-on failed: '.$reason.'.');
        $this->reason = $reason;
    }
}
