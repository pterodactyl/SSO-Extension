<?php

declare(strict_types=1);

namespace Sso\Data;

final readonly class ExternalIdentity
{
    /**
     * @var string
     */
    public string $provider;

    /**
     * @var string
     */
    public string $id;

    /**
     * @var string
     */
    public string $name;

    /**
     * @var string|null
     */
    public ?string $email;

    /**
     * @var bool
     */
    public bool $emailVerified;

    /**
     * @var string|null
     */
    public ?string $avatarUrl;

    /**
     * Whether the panel account is an administrator, when the provider decides
     * it (for example from groups). Null leaves the role as it is.
     *
     * @var bool|null
     */
    public ?bool $admin;

    /**
     * Whether an unknown identity may get a new panel account.
     *
     * @var bool
     */
    public bool $provision;

    /**
     * The login name the provider gives the user, used for a new account's username.
     *
     * @var string|null
     */
    public ?string $username;

    /**
     * @param string
     * @param string
     * @param string
     * @param string|null
     * @param bool
     * @param string|null
     * @param bool|null
     * @param bool
     * @param string|null
     */
    public function __construct(string $provider, string $id, string $name, ?string $email, bool $emailVerified, ?string $avatarUrl, ?bool $admin = null, bool $provision = false, ?string $username = null)
    {
        $this->provider = $provider;
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->emailVerified = $emailVerified;
        $this->avatarUrl = $avatarUrl;
        $this->admin = $admin;
        $this->provision = $provision;
        $this->username = $username;
    }

    /**
     * @return array
     */
    public function details(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
        ];
    }
}
