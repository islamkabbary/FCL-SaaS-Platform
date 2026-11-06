<?php

namespace App\Console\Commands;

use App\Actions\Billing\IssueMonthlyCharges as IssueMonthlyChargesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:issue-monthly-charges')]
#[Description('Issue the monthly charge of every subscription period that has started')]
class IssueMonthlyCharges extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IssueMonthlyChargesAction $issueMonthlyCharges): int
    {
        $issued = $issueMonthlyCharges->handle();

        $this->components->info("{$issued} monthly charges issued.");

        return self::SUCCESS;
    }
}
