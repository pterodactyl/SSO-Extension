<?php

declare(strict_types=1);

namespace Sso\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final class WemxGenerateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wemx:generate';

    /**
     * @var string
     */
    protected $description = 'Generate a new SSO secret key for WemX (compatibility command)';

    /**
     * Execute the console command.
     *
     * @param ExtensionSettingsRegistry $registry
     * @return int
     */
    public function handle(ExtensionSettingsRegistry $registry): int
    {
        return $this->call(GenerateDirectSecretCommand::class);
    }
}
