<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Models\FeatureList;
use App\Models\HostingDatabase;
use App\Models\HostingPackage;
use App\Models\Node;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\AgentClient;
use App\Services\DatabaseProvisioner;
use App\Services\DnsProvisioner;
use App\Services\DomainProvisioner;
use App\Services\MailProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DemoSeedPublic extends Command
{
    protected $signature = 'demo:seed-public
        {--domain=stratadevplatform.net : Base domain used for demo subdomains}
        {--provision : Provision live node resources in addition to panel records}
        {--reset : Remove existing public demo panel records before seeding}';

    protected $description = 'Seed public smoke-test demo users and dummy hosting data.';

    private const PASSWORDS = [
        'demo-admin@stratadevplatform.net' => 'DemoAdmin2026!',
        'demo-reseller@stratadevplatform.net' => 'DemoReseller2026!',
        'demo-user@stratadevplatform.net' => 'DemoUser2026!',
        'demo-client@stratadevplatform.net' => 'DemoClient2026!',
    ];

    private const USERNAMES = ['demouser', 'democlient'];

    public function handle(
        AccountProvisioner $accountProvisioner,
        DomainProvisioner $domainProvisioner,
        MailProvisioner $mailProvisioner
    ): int {
        $baseDomain = trim((string) $this->option('domain')) ?: 'stratadevplatform.net';
        $provision = (bool) $this->option('provision');

        if ($this->option('reset')) {
            $this->resetDemo($provision, $accountProvisioner, $domainProvisioner, $mailProvisioner);
        }

        $node = Node::where('is_primary', true)->first() ?? Node::first();
        if (! $node) {
            $this->error('No node is registered. Install/register a node before seeding demo hosting data.');
            return self::FAILURE;
        }

        $this->createRoles();
        $package = $this->createPackage();

        $admin = $this->createUser('Demo Admin', 'demo-admin@stratadevplatform.net', 'admin');
        $reseller = $this->createUser('Demo Reseller', 'demo-reseller@stratadevplatform.net', 'reseller', [
            'quota_accounts' => 10,
            'quota_disk_mb' => 20480,
            'quota_bandwidth_mb' => 102400,
            'quota_domains' => 25,
            'quota_email_accounts' => 50,
            'quota_databases' => 25,
            'default_hosting_package_id' => $package->id,
            'brand_name' => 'Strata Demo Reseller',
            'brand_color' => '#2563eb',
        ]);
        $user = $this->createUser('Demo User', 'demo-user@stratadevplatform.net', 'user');
        $client = $this->createUser('Demo Client', 'demo-client@stratadevplatform.net', 'user', [
            'reseller_id' => $reseller->id,
        ]);

        $this->seedAccount($user, null, $node, $package, 'demouser', [
            "demo-user.{$baseDomain}",
            "blog-demo.{$baseDomain}",
        ], $provision, $accountProvisioner, $domainProvisioner, $mailProvisioner);

        $this->seedAccount($client, $reseller, $node, $package, 'democlient', [
            "client-demo.{$baseDomain}",
        ], $provision, $accountProvisioner, $domainProvisioner, $mailProvisioner);

        $this->info('Public demo seeded.');
        $this->table(['Role', 'Email', 'Password'], [
            ['Admin', 'demo-admin@stratadevplatform.net', self::PASSWORDS['demo-admin@stratadevplatform.net']],
            ['Reseller', 'demo-reseller@stratadevplatform.net', self::PASSWORDS['demo-reseller@stratadevplatform.net']],
            ['User', 'demo-user@stratadevplatform.net', self::PASSWORDS['demo-user@stratadevplatform.net']],
            ['Reseller Client', 'demo-client@stratadevplatform.net', self::PASSWORDS['demo-client@stratadevplatform.net']],
        ]);

        return self::SUCCESS;
    }

    private function createRoles(): void
    {
        foreach (['admin', 'reseller', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function createPackage(): HostingPackage
    {
        $features = FeatureList::updateOrCreate(
            ['slug' => 'public-demo-all-features'],
            [
                'name' => 'Public Demo - All Features',
                'description' => 'Feature list used by the public smoke-test demo accounts.',
                'features' => array_keys(FeatureList::catalog()),
            ]
        );

        return HostingPackage::updateOrCreate(
            ['slug' => 'public-demo-starter'],
            [
                'name' => 'Public Demo Starter',
                'description' => 'Demo-only starter package for public smoke testing.',
                'feature_list_id' => $features->id,
                'php_version' => '8.4',
                'disk_limit_mb' => 2048,
                'bandwidth_limit_mb' => 10240,
                'max_domains' => 5,
                'max_subdomains' => 10,
                'max_email_accounts' => 10,
                'max_databases' => 10,
                'max_ftp_accounts' => 5,
                'available_to_resellers' => true,
                'is_active' => true,
            ]
        );
    }

    private function createUser(string $name, string $email, string $role, array $attributes = []): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            array_merge([
                'name' => $name,
                'password' => Hash::make(self::PASSWORDS[$email]),
            ], $attributes)
        );

        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function seedAccount(
        User $user,
        ?User $reseller,
        Node $node,
        HostingPackage $package,
        string $username,
        array $domains,
        bool $provision,
        AccountProvisioner $accountProvisioner,
        DomainProvisioner $domainProvisioner,
        MailProvisioner $mailProvisioner
    ): void {
        $account = Account::updateOrCreate(
            ['username' => $username],
            array_merge($package->accountAttributes(), [
                'user_id' => $user->id,
                'node_id' => $node->id,
                'reseller_id' => $reseller?->id,
                'status' => 'active',
                'disk_used_mb' => $username === 'demouser' ? 384 : 128,
                'bandwidth_used_mb' => $username === 'demouser' ? 2048 : 640,
            ])
        );

        if ($provision) {
            [$ok, $error] = $accountProvisioner->provision($account->fresh(['node']));
            $this->assertProvisioned($ok, $error, "account {$username}");
        }

        foreach ($domains as $index => $domainName) {
            $domain = Domain::updateOrCreate(
                ['domain' => $domainName],
                [
                    'account_id' => $account->id,
                    'node_id' => $node->id,
                    'type' => $index === 0 ? 'main' : 'addon',
                    'document_root' => "/var/www/{$username}/public_html" . ($index === 0 ? '' : "/site{$index}"),
                    'web_server' => $node->web_server ?? 'nginx',
                    'php_version' => $account->php_version,
                    'force_https' => false,
                    'mail_spam_action' => 'junk',
                ]
            );

            if ($provision) {
                [$ok, $error] = $domainProvisioner->provision($domain->fresh(['account', 'node']));
                $this->assertProvisioned($ok, $error, "domain {$domainName}");
            }

            $this->seedDns($domain->fresh(['node']), $provision);

            if ($index === 0) {
                $this->seedMail($domain->fresh(['node']), $provision, $mailProvisioner);
            }
        }

        $this->seedDatabases($account->fresh(['node']), $provision);
    }

    private function seedDns(Domain $domain, bool $provision): void
    {
        if ($provision) {
            $dns = new DnsProvisioner(AgentClient::for($domain->node));
            [$ok, $error] = $dns->createZone($domain);
            $this->assertProvisioned($ok, $error, "DNS zone {$domain->domain}");

            $zone = DnsZone::where('domain_id', $domain->id)->firstOrFail();
            foreach ($this->dnsRecordsFor($domain) as $record) {
                [$ok, $error] = $dns->addRecord($zone, $record['name'], $record['type'], $record['ttl'], $record['contents'], $record['managed'] ?? false, $record['priority'] ?? null);
                $this->assertProvisioned($ok, $error, "DNS record {$record['name']} {$record['type']} for {$domain->domain}");
            }

            return;
        }

        $zone = DnsZone::updateOrCreate(
            ['domain_id' => $domain->id],
            [
                'account_id' => $domain->account_id,
                'node_id' => $domain->node_id,
                'zone_name' => $domain->domain,
                'active' => true,
            ]
        );

        foreach ($this->dnsRecordsFor($domain) as $record) {
            DnsRecord::updateOrCreate(
                ['dns_zone_id' => $zone->id, 'name' => $record['name'], 'type' => $record['type']],
                [
                    'ttl' => $record['ttl'],
                    'value' => implode("\n", $record['contents']),
                    'priority' => $record['priority'] ?? 0,
                    'managed' => $record['managed'] ?? false,
                ]
            );
        }
    }

    /**
     * @return array<int, array{name:string,type:string,ttl:int,contents:array<int,string>,managed?:bool,priority?:int}>
     */
    private function dnsRecordsFor(Domain $domain): array
    {
        $ip = $domain->node?->ip_address ?: '192.0.2.10';

        return [
            ['name' => '@', 'type' => 'A', 'ttl' => 300, 'contents' => [$ip], 'managed' => true],
            ['name' => 'www', 'type' => 'CNAME', 'ttl' => 300, 'contents' => [$domain->domain . '.'], 'managed' => true],
            ['name' => 'mail', 'type' => 'A', 'ttl' => 300, 'contents' => [$ip], 'managed' => true],
            ['name' => '@', 'type' => 'MX', 'ttl' => 300, 'contents' => ['mail.' . $domain->domain . '.'], 'priority' => 10, 'managed' => true],
            ['name' => '@', 'type' => 'TXT', 'ttl' => 300, 'contents' => ['"v=spf1 a mx ~all"'], 'managed' => true],
            ['name' => '_dmarc', 'type' => 'TXT', 'ttl' => 300, 'contents' => ['"v=DMARC1; p=none; rua=mailto:postmaster@' . $domain->domain . '"'], 'managed' => true],
        ];
    }

    private function seedMail(Domain $domain, bool $provision, MailProvisioner $mailProvisioner): void
    {
        if ($provision) {
            [$ok, $error] = $mailProvisioner->createMailbox($domain, 'hello', 'DemoMailbox2026!', 512);
            $this->assertProvisioned($ok, $error, "mailbox hello@{$domain->domain}");

            [$ok, $error] = $mailProvisioner->createForwarder($domain, 'support@' . $domain->domain, 'hello@' . $domain->domain);
            $this->assertProvisioned($ok, $error, "forwarder support@{$domain->domain}");

            return;
        }

        EmailAccount::updateOrCreate(
            ['email' => 'hello@' . $domain->domain],
            [
                'domain_id' => $domain->id,
                'account_id' => $domain->account_id,
                'node_id' => $domain->node_id,
                'local_part' => 'hello',
                'quota_mb' => 512,
                'active' => true,
                'spam_action' => 'junk',
            ]
        );

        EmailForwarder::updateOrCreate(
            ['source' => 'support@' . $domain->domain],
            [
                'domain_id' => $domain->id,
                'account_id' => $domain->account_id,
                'node_id' => $domain->node_id,
                'destination' => 'hello@' . $domain->domain,
            ]
        );
    }

    private function seedDatabases(Account $account, bool $provision): void
    {
        if ($provision) {
            $db = new DatabaseProvisioner(AgentClient::for($account->node));
            [$ok, $error] = $db->create($account, "{$account->username}_app", "{$account->username}_app", 'DemoDbPass2026!', 'Public demo MySQL database', 'mysql');
            $this->assertProvisioned($ok, $error, "MySQL database {$account->username}_app");

            [$ok, $error] = $db->create($account, "{$account->username}_pg", "{$account->username}_pg", 'DemoDbPass2026!', 'Public demo PostgreSQL database', 'postgresql');
            $this->assertProvisioned($ok, $error, "PostgreSQL database {$account->username}_pg");

            return;
        }

        foreach (['mysql' => 'app', 'postgresql' => 'pg'] as $engine => $suffix) {
            HostingDatabase::updateOrCreate(
                ['db_name' => "{$account->username}_{$suffix}"],
                [
                    'account_id' => $account->id,
                    'node_id' => $account->node_id,
                    'engine' => $engine,
                    'db_user' => "{$account->username}_{$suffix}",
                    'note' => $engine === 'mysql' ? 'Public demo MySQL database' : 'Public demo PostgreSQL database',
                ]
            );
        }
    }

    private function resetDemo(
        bool $provision,
        AccountProvisioner $accountProvisioner,
        DomainProvisioner $domainProvisioner,
        MailProvisioner $mailProvisioner
    ): void {
        foreach (self::USERNAMES as $username) {
            $account = Account::withTrashed()->where('username', $username)->first();
            if (! $account) {
                continue;
            }

            $account->loadMissing(['node', 'domains', 'emailAccounts', 'databases']);

            if ($provision && $account->node) {
                foreach ($account->emailAccounts as $mailbox) {
                    try {
                        $mailProvisioner->deleteMailbox($mailbox);
                    } catch (\Throwable) {
                    }
                }

                foreach ($account->domains as $domain) {
                    try {
                        (new DnsProvisioner(AgentClient::for($domain->node)))->deleteZone($domain);
                    } catch (\Throwable) {
                    }
                    try {
                        $domainProvisioner->deprovision($domain);
                    } catch (\Throwable) {
                    }
                }

                foreach ($account->databases as $database) {
                    try {
                        (new DatabaseProvisioner(AgentClient::for($database->node)))->delete($database);
                    } catch (\Throwable) {
                    }
                }

                try {
                    $accountProvisioner->deprovision($account);
                } catch (\Throwable) {
                }
            }

            $account->forceDelete();
        }

        foreach (array_keys(self::PASSWORDS) as $email) {
            User::where('email', $email)->delete();
        }
    }

    private function assertProvisioned(bool $ok, ?string $error, string $context): void
    {
        if (! $ok) {
            throw new \RuntimeException("Failed to provision {$context}: " . ($error ?: 'unknown error'));
        }
    }
}
