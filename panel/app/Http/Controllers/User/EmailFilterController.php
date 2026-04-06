<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Models\EmailFilter;
use App\Services\MailSieveProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailFilterController extends Controller
{
    public function index(EmailAccount $emailAccount): Response
    {
        $account = $this->account();
        abort_unless($emailAccount->account_id === $account->id, 403);

        $emailAccount->load(['filters', 'domain']);

        return Inertia::render('User/Email/Filters', [
            'mailbox' => [
                'id' => $emailAccount->id,
                'email' => $emailAccount->email,
                'domain' => $emailAccount->domain?->only(['id', 'domain']),
            ],
            'filters' => $emailAccount->filters->map(fn (EmailFilter $filter) => [
                'id' => $filter->id,
                'name' => $filter->name,
                'match_field' => $filter->match_field,
                'match_operator' => $filter->match_operator,
                'match_value' => $filter->match_value,
                'action' => $filter->action,
                'action_value' => $filter->action_value,
                'active' => $filter->active,
                'sort_order' => $filter->sort_order,
            ])->values(),
            'fieldOptions' => [
                ['value' => 'subject', 'label' => 'Subject'],
                ['value' => 'from', 'label' => 'From'],
                ['value' => 'to', 'label' => 'To'],
            ],
            'operatorOptions' => [
                ['value' => 'contains', 'label' => 'Contains'],
                ['value' => 'is', 'label' => 'Exactly matches'],
            ],
            'actionOptions' => [
                ['value' => 'discard', 'label' => 'Discard message'],
                ['value' => 'redirect', 'label' => 'Redirect message'],
            ],
        ]);
    }

    public function store(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $account = $this->account();
        abort_unless($emailAccount->account_id === $account->id, 403);

        $data = $this->validated($request);
        $data['sort_order'] = (int) ($emailAccount->filters()->max('sort_order') ?? 0) + 10;
        $filter = $emailAccount->filters()->create($data);

        [$success, $error] = app(MailSieveProvisioner::class)->sync($emailAccount);
        if (! $success) {
            $filter->delete();
            return back()->with('error', 'Failed to save filter: ' . $error);
        }

        return back()->with('success', 'Mailbox filter saved.');
    }

    public function update(Request $request, EmailFilter $filter): RedirectResponse
    {
        $account = $this->account();
        $filter->load('emailAccount');
        abort_unless($filter->emailAccount && $filter->emailAccount->account_id === $account->id, 403);

        $filter->update($this->validated($request));

        [$success, $error] = app(MailSieveProvisioner::class)->sync($filter->emailAccount);
        if (! $success) {
            return back()->with('error', 'Failed to update filter: ' . $error);
        }

        return back()->with('success', 'Mailbox filter updated.');
    }

    public function destroy(EmailFilter $filter): RedirectResponse
    {
        $account = $this->account();
        $filter->load('emailAccount');
        abort_unless($filter->emailAccount && $filter->emailAccount->account_id === $account->id, 403);

        $emailAccount = $filter->emailAccount;
        $filter->delete();

        [$success, $error] = app(MailSieveProvisioner::class)->sync($emailAccount);
        if (! $success) {
            return back()->with('error', 'Filter removed locally but mailbox rules could not be synced: ' . $error);
        }

        return back()->with('success', 'Mailbox filter deleted.');
    }

    private function account()
    {
        return auth()->user()->account()->firstOrFail();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'match_field' => ['required', 'in:subject,from,to'],
            'match_operator' => ['required', 'in:contains,is'],
            'match_value' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:discard,redirect'],
            'action_value' => ['nullable', 'required_if:action,redirect', 'email'],
            'active' => ['nullable', 'boolean'],
        ] + [
            'active' => $request->boolean('active', true),
        ]);
    }
}
