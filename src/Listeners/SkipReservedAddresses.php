<?php

declare(strict_types=1);

namespace Sso\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Pterodactyl\Facades\Extensions;
use Sso\Services\Placeholders;
use Sso\SsoSettings;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Accounts waiting for their first sign-in have a placeholder email under the reserved
 * .invalid domain. Nothing is ever mailed to such an address, such as the panel's welcome
 * email or a password reset link.
 */
final class SkipReservedAddresses
{
    /**
     * @param NotificationSending
     * @return bool|null
     */
    public function notification(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || ! Extensions::isAvailable(SsoSettings::EXTENSION) || ! method_exists($event->notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $event->notifiable->routeNotificationFor('mail', $event->notification);
        $addresses = is_array($route) ? array_merge(array_filter(array_keys($route), 'is_string'), array_filter($route, 'is_string')) : [$route];
        foreach ($addresses as $address) {
            if (is_string($address) && Placeholders::isReservedEmail($address)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param MessageSending
     * @return bool|null
     */
    public function message(MessageSending $event): ?bool
    {
        if (! $event->message instanceof Email || ! Extensions::isAvailable(SsoSettings::EXTENSION)) {
            return null;
        }

        $message = $event->message;
        $keep = fn (array $addresses): array => array_values(array_filter($addresses, fn (Address $address): bool => ! Placeholders::isReservedEmail($address->getAddress())));
        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());
        if ($to === [] && $cc === [] && $bcc === []) {
            return false;
        }

        if (count($to) + count($cc) + count($bcc) < count($message->getTo()) + count($message->getCc()) + count($message->getBcc())) {
            $message->to(...$to)->cc(...$cc)->bcc(...$bcc);
        }

        return null;
    }
}
