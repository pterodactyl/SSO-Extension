<?php

declare(strict_types=1);

namespace Sso\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Sso\SsoSettings;

class GenerateDirectSecretCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sso:direct-secret';

    /**
     * @var string
     */
    protected $description = 'Generate a new shared secret key for Direct SSO (WemX, Paymenter, WHMCS, etc.)';

    /**
     * Execute the console command.
     *
     * @param ExtensionSettingsRegistry $registry
     * @return int
     */
    public function handle(ExtensionSettingsRegistry $registry): int
    {
        $secret = Str::random(48);

        $settings = $registry->get('sso');
        if ($settings !== null) {
            $settings->update([
                SsoSettings::DIRECT_SECRET => $secret,
                SsoSettings::DIRECT_ENABLED => true,
            ]);
        }

        $this->info('Successfully generated a new Direct SSO secret key:');
        $this->line('<comment>'.$secret.'</comment>');
        $this->newLine();
        $this->line('Direct SSO has been enabled in the Single Sign-On extension settings.');
        $this->line('Configure this in your billing platform (WemX, Paymenter, WHMCS, etc.):');
        $this->line('- SSO Secret: <comment>'.$secret.'</comment>');
        $this->line('- Webhook Endpoint: <comment>'.url('/sso-wemx').'</comment> (or <comment>'.url('/extensions/sso/direct').'</comment>)');

        return self::SUCCESS;
    }
}
