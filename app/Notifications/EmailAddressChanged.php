<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the previous address of an account that its login email was changed, so the
 * owner notices a change they did not make (a changed email can receive the password
 * reset link). Sent to the old address only; it never contains a link to the account.
 */
class EmailAddressChanged extends Notification
{
    public function __construct(
        public string $oldEmail,
        public string $newEmail,
        public string $changedBy,
        public ?string $company = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('The email address of your account was changed'))
            ->line(__('The login email of your account:company was changed from :old to :new by :by.', [
                'company' => $this->company !== null ? ' ('.$this->company.')' : '',
                'old' => $this->oldEmail,
                'new' => $this->newEmail,
                'by' => $this->changedBy,
            ]))
            ->line(__('If you did not expect this change, contact your company administrator immediately.'));
    }
}
