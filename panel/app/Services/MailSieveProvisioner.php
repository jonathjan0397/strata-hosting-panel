<?php

namespace App\Services;

use App\Models\EmailAccount;

class MailSieveProvisioner
{
    public function sync(EmailAccount $emailAccount): array
    {
        $emailAccount->loadMissing(['node', 'autoresponder', 'filters']);

        $script = $this->compile($emailAccount);
        $response = AgentClient::for($emailAccount->node)->mailboxSieveSet($emailAccount->email, $script);

        if (! $response->successful()) {
            return [false, $response->body()];
        }

        return [true, null];
    }

    public function compile(EmailAccount $emailAccount): ?string
    {
        $requires = [];
        $rules = [];

        if ($emailAccount->spam_action && $emailAccount->spam_action !== 'inbox') {
            $rule = "if anyof (header :contains \"X-Spam-Flag\" \"YES\", header :contains \"X-Spam\" \"Yes\") {\n";

            if ($emailAccount->spam_action === 'junk') {
                $requires['fileinto'] = true;
                $rule .= "  fileinto \"Junk\";\n";
            } elseif ($emailAccount->spam_action === 'discard') {
                $rule .= "  discard;\n";
            }

            $rule .= "  stop;\n}";
            $rules[] = $rule;
        }

        foreach ($emailAccount->filters->where('active', true)->sortBy('sort_order') as $filter) {
            $matchField = match ($filter->match_field) {
                'subject' => 'Subject',
                'from' => 'From',
                'to' => 'To',
                default => null,
            };

            if (! $matchField) {
                continue;
            }

            $operator = $filter->match_operator === 'is' ? ':is' : ':contains';
            $value = $this->escapeString($filter->match_value);
            $rule = "if header {$operator} \"{$matchField}\" \"{$value}\" {\n";

            if ($filter->action === 'redirect' && $filter->action_value) {
                $requires['redirect'] = true;
                $target = $this->escapeString($filter->action_value);
                $rule .= "  redirect \"{$target}\";\n";
            } elseif ($filter->action === 'discard') {
                $rule .= "  discard;\n";
            } else {
                continue;
            }

            $rule .= "  stop;\n}";
            $rules[] = $rule;
        }

        if ($emailAccount->autoresponder?->active) {
            $requires['vacation'] = true;
            $subject = $this->escapeString($emailAccount->autoresponder->subject);
            $body = $this->escapeMultilineString($emailAccount->autoresponder->body);
            $rules[] = "vacation\n  :days 1\n  :subject \"{$subject}\"\n  text:\n{$body}\n.\n;";
        }

        if ($rules === []) {
            return null;
        }

        $header = '';
        if ($requires !== []) {
            $header = 'require [' . implode(', ', array_map(fn ($item) => "\"{$item}\"", array_keys($requires))) . "];\n\n";
        }

        return $header . implode("\n\n", $rules) . "\n";
    }

    private function escapeString(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        return str_replace('"', '\"', $value);
    }

    private function escapeMultilineString(string $value): string
    {
        return str_replace("\r", '', $value);
    }
}
