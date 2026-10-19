<?php

declare(strict_types=1);

namespace Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

final class PendingSetup extends Model
{
    /**
     * @var string
     */
    protected $table = 'sso_pending_setups';

    /**
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var array
     */
    protected $fillable = ['user_id'];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @inheritdoc
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }
}
