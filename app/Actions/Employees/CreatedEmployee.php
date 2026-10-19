<?php

namespace App\Actions\Employees;

use App\Models\User;

/**
 * A newly created employee and their temporary password, to be shown exactly once.
 * It must never be persisted, flashed to the session, or logged.
 */
final readonly class CreatedEmployee
{
    public function __construct(
        public User $employee,
        #[\SensitiveParameter] public string $temporaryPassword,
    ) {}

    /**
     * Hide the temporary password from dumps and debug output.
     *
     * @return array{employee: int, temporaryPassword: string}
     */
    public function __debugInfo(): array
    {
        return [
            'employee' => $this->employee->id,
            'temporaryPassword' => '[redacted]',
        ];
    }
}
