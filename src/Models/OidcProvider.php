<?php

declare(strict_types=1);

namespace Sso\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property string $issuer
 * @property string $client_id
 * @property string $client_secret
 * @property string $scopes
 * @property string $groups_claim
 * @property array $admin_groups
 * @property array $member_groups
 * @property bool $create_users
 * @property bool $enabled
 */
final class OidcProvider extends Model
{
    public const string PREFIX = 'oidc-';

    /**
     * @var string
     */
    protected $table = 'sso_oidc_providers';

    /**
     * @var array
     */
    protected $fillable = [
        'slug', 'label', 'issuer', 'client_id', 'client_secret', 'scopes', 'groups_claim',
        'admin_groups', 'member_groups', 'create_users', 'enabled',
    ];

    /**
     * @var array
     */
    protected $hidden = ['client_secret'];

    /**
     * The identity provider id this record is served under. Prefixed so a record
     * can never shadow a built-in provider.
     *
     * @return string
     */
    public function providerId(): string
    {
        return self::PREFIX.$this->slug;
    }

    /**
     * @inheritdoc
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'admin_groups' => 'array',
            'member_groups' => 'array',
            'create_users' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
