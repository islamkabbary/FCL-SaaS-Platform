<?php

use App\Actions\Impersonation\RevokeImpersonation;
use App\Exceptions\ImpersonationRefused;
use App\Models\ImpersonationSession;
use App\Models\SupportSession;
use App\Notifications\SupportAccessStarted;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Access history')] class extends Component {
    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view-access-history');
    }

    /**
     * Support sessions of platform staff in this company (central records, this tenant only).
     *
     * @return Collection<int, SupportSession>
     */
    #[Computed]
    public function supportSessions(): Collection
    {
        return SupportSession::query()
            ->where('tenant_id', tenant()->getTenantKey())
            ->latest('started_at')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * Employee views started by the company's Tenant Admins (tenant database).
     *
     * @return Collection<int, ImpersonationSession>
     */
    #[Computed]
    public function employeeSessions(): Collection
    {
        return ImpersonationSession::query()->latest('started_at')->latest('id')->limit(50)->get();
    }

    #[Computed]
    public function unreadNoticeCount(): int
    {
        return Auth::user()->unreadNotifications()->where('type', SupportAccessStarted::class)->count();
    }

    public function markNoticesRead(): void
    {
        $this->authorize('view-access-history');

        Auth::user()->unreadNotifications()->where('type', SupportAccessStarted::class)->update(['read_at' => now()]);
        unset($this->unreadNoticeCount);
    }

    public function revokeSupport(int $sessionId, RevokeImpersonation $revoke): void
    {
        try {
            $revoke->supportByTenantAdmin(Auth::user(), $sessionId);
        } catch (AuthorizationException|ImpersonationRefused $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Support access revoked.'));
        unset($this->supportSessions);
    }

    public function endEmployeeView(int $sessionId, RevokeImpersonation $revoke): void
    {
        try {
            $revoke->employeeByTenantAdmin(Auth::user(), $sessionId);
        } catch (AuthorizationException|ImpersonationRefused $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Employee view ended.'));
        unset($this->employeeSessions);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Access history') }}</flux:heading>
    <flux:subheading>{{ __('Platform support access to your company, and employee views by your Tenant Admins.') }}</flux:subheading>

    @if ($this->unreadNoticeCount > 0)
        <flux:callout variant="warning" icon="lifebuoy" class="mt-6" :heading="trans_choice('{1} Platform support accessed your company once since you last checked.|[2,*] Platform support accessed your company :count times since you last checked.', $this->unreadNoticeCount, ['count' => $this->unreadNoticeCount])" data-test="support-notice">
            <x-slot name="actions">
                <flux:button size="sm" wire:click="markNoticesRead" data-test="mark-notices-read">{{ __('Mark as read') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <flux:heading size="lg" level="2" class="mt-8">{{ __('Platform support access') }}</flux:heading>
    <flux:table class="mt-4" data-test="tenant-support-sessions">
        <flux:table.columns>
            <flux:table.column>{{ __('Started') }}</flux:table.column>
            <flux:table.column>{{ __('Support staff') }}</flux:table.column>
            <flux:table.column>{{ __('Acting as') }}</flux:table.column>
            <flux:table.column>{{ __('Reason') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->supportSessions as $session)
                <flux:table.row :key="'support-'.$session->id">
                    <flux:table.cell class="whitespace-nowrap">{{ $session->started_at->toDayDateTimeString() }}</flux:table.cell>
                    <flux:table.cell>{{ $session->actor_name }} <div class="text-xs text-zinc-500">{{ $session->actor_email }}</div></flux:table.cell>
                    <flux:table.cell>{{ $session->target_email }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs whitespace-normal">{{ $session->reason }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$session->status()->color()">{{ $session->status()->label() }}</flux:badge>
                        @if ($session->end_reason)
                            <div class="text-xs text-zinc-500">{{ $session->end_reason->label() }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($session->isRevocable())
                            <flux:button size="sm" variant="danger" wire:click="revokeSupport({{ $session->id }})" wire:confirm="{{ __('End this support access now?') }}" data-test="tenant-revoke-support">{{ __('Revoke') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('Platform support has not accessed your company.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Employee views') }}</flux:heading>
    <flux:table class="mt-4" data-test="employee-impersonation-sessions">
        <flux:table.columns>
            <flux:table.column>{{ __('Started') }}</flux:table.column>
            <flux:table.column>{{ __('Tenant Admin') }}</flux:table.column>
            <flux:table.column>{{ __('Viewed as') }}</flux:table.column>
            <flux:table.column>{{ __('Reason') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->employeeSessions as $session)
                <flux:table.row :key="'employee-'.$session->id">
                    <flux:table.cell class="whitespace-nowrap">{{ $session->started_at->toDayDateTimeString() }}</flux:table.cell>
                    <flux:table.cell>{{ $session->impersonator_email }}</flux:table.cell>
                    <flux:table.cell>{{ $session->target_email }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs whitespace-normal">{{ $session->reason }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$session->status()->color()">{{ $session->status()->label() }}</flux:badge>
                        @if ($session->end_reason)
                            <div class="text-xs text-zinc-500">{{ $session->end_reason->label() }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($session->isOpen())
                            <flux:button size="sm" variant="danger" wire:click="endEmployeeView({{ $session->id }})" data-test="end-employee-view">{{ __('End') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No employee views yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
