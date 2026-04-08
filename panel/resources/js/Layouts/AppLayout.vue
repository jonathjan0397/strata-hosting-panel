<template>
    <div :class="[themeClass, 'min-h-screen bg-gray-950 text-gray-100']">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-gray-900 border-r border-gray-800"
        >
            <!-- Logo -->
            <div class="flex h-16 items-center gap-3 px-5 border-b border-gray-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                    <span class="text-sm font-bold text-white">S</span>
                </div>
                <span class="text-lg font-semibold tracking-tight">{{ $page.props.branding?.name || 'Strata Hosting Panel' }}</span>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <NavItem :href="route('dashboard')" :active="$page.url === '/dashboard'">
                    <template #icon>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                        </svg>
                    </template>
                    Dashboard
                </NavItem>

                <!-- Admin nav -->
                <template v-if="$page.props.auth.user.roles?.includes('admin')">
                    <NavGroup label="Resellers">
                        <NavItem :href="route('admin.resellers.index')" :active="$page.url.startsWith('/admin/resellers')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                                </svg>
                            </template>
                            Resellers
                        </NavItem>
                    </NavGroup>

                    <NavGroup label="Security">
                        <NavItem :href="route('admin.security.firewall')" :active="$page.url.startsWith('/admin/security/firewall')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                                </svg>
                            </template>
                            Firewall
                        </NavItem>
                        <NavItem :href="route('admin.security.fail2ban.index')" :active="$page.url.startsWith('/admin/security/fail2ban')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12V16.5Zm8.25-4.5a8.25 8.25 0 1 1-16.5 0 8.25 8.25 0 0 1 16.5 0Z" />
                                </svg>
                            </template>
                            Fail2Ban
                        </NavItem>
                    </NavGroup>
                </template>

                <!-- Admin nav (continued) -->
                <template v-if="$page.props.auth.user.roles?.includes('admin')">
                    <NavGroup label="System">
                        <NavItem :href="route('admin.audit-log.index')" :active="$page.url.startsWith('/admin/audit-log')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                                </svg>
                            </template>
                            Audit Log
                        </NavItem>
                        <NavItem :href="route('admin.deliverability.index')" :active="$page.url.startsWith('/admin/deliverability')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </template>
                            Deliverability
                        </NavItem>
                        <NavItem :href="route('admin.backups.index')" :active="$page.url.startsWith('/admin/backups') && !$page.url.startsWith('/admin/backups/destinations') && !$page.url.startsWith('/admin/backups/schedules')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                                </svg>
                            </template>
                            Backups
                        </NavItem>
                        <NavItem :href="route('admin.backups.schedules')" :active="$page.url.startsWith('/admin/backups/schedules')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </template>
                            Backup Schedules
                        </NavItem>
                        <NavItem :href="route('admin.backups.destinations')" :active="$page.url.startsWith('/admin/backups/destinations')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                </svg>
                            </template>
                            Remote Backups
                        </NavItem>
                        <NavItem :href="route('admin.security.index')" :active="$page.url.startsWith('/admin/security') && !$page.url.startsWith('/admin/security/firewall') && !$page.url.startsWith('/admin/security/fail2ban') && !$page.url.startsWith('/admin/security/spam')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                </svg>
                            </template>
                            Security
                        </NavItem>
                        <NavItem :href="route('admin.updates.index')" :active="$page.url.startsWith('/admin/updates')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                            </template>
                            OS Updates
                        </NavItem>
                        <NavItem :href="route('admin.security.spam')" :active="$page.url.startsWith('/admin/security/spam')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.981l7.5-4.039a2.25 2.25 0 0 1 2.134 0l7.5 4.039a2.25 2.25 0 0 1 1.183 1.98V19.5Z" />
                                </svg>
                            </template>
                            Spam Filter
                        </NavItem>
                        <NavItem :href="route('admin.api-tokens.index')" :active="$page.url.startsWith('/admin/api-tokens')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 0 1 21.75 8.25Z" />
                                </svg>
                            </template>
                            API Tokens
                        </NavItem>
                        <NavItem :href="route('admin.webhooks.index')" :active="$page.url.startsWith('/admin/webhooks')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                </svg>
                            </template>
                            Webhooks
                        </NavItem>
                    </NavGroup>
                    <NavGroup label="Infrastructure">
                        <NavItem :href="route('admin.nodes.index')" :active="$page.url.startsWith('/admin/nodes')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3M12 3v4.5" />
                                </svg>
                            </template>
                            Nodes
                        </NavItem>
                    </NavGroup>

                    <NavGroup label="Hosting">
                        <NavItem :href="route('admin.my-website.index')" :active="$page.url.startsWith('/admin/my-website')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                                </svg>
                            </template>
                            My Website
                        </NavItem>
                        <NavItem :href="route('admin.accounts.index')" :active="$page.url.startsWith('/admin/accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                </svg>
                            </template>
                            Accounts
                        </NavItem>
                        <NavItem :href="route('admin.migrations.index')" :active="$page.url.startsWith('/admin/migrations')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5M16.5 3 21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                </svg>
                            </template>
                            Migrations
                        </NavItem>
                        <NavItem :href="route('admin.backup-imports.index')" :active="$page.url.startsWith('/admin/backup-imports')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                </svg>
                            </template>
                            Backup Imports
                        </NavItem>
                        <NavItem :href="route('admin.packages.index')" :active="$page.url.startsWith('/admin/packages')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 12 6.75l5.571 3m-11.142 0L12 12.75m-5.571-3v6l5.571 3 5.571-3v-6M4.5 8.714l7.114-3.84a.75.75 0 0 1 .772 0L19.5 8.714a.75.75 0 0 1 .386.659v5.254a.75.75 0 0 1-.386.659l-7.114 3.84a.75.75 0 0 1-.772 0L4.5 15.286a.75.75 0 0 1-.386-.659V9.373A.75.75 0 0 1 4.5 8.714Z" />
                                </svg>
                            </template>
                            Packages
                        </NavItem>
                        <NavItem :href="route('admin.feature-lists.index')" :active="$page.url.startsWith('/admin/feature-lists')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12m-12 4.5h12m-12 4.5h12m-16.5-9h.008v.008H3.75V6.75Zm0 4.5h.008v.008H3.75v-.008Zm0 4.5h.008v.008H3.75v-.008Z" />
                                </svg>
                            </template>
                            Feature Lists
                        </NavItem>
                        <NavItem :href="route('admin.domains.index')" :active="$page.url.startsWith('/admin/domains')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                </svg>
                            </template>
                            Domains
                        </NavItem>
                        <NavItem :href="route('email-accounts.index')" :active="$page.url.startsWith('/email-accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </template>
                            Email Accounts
                        </NavItem>
                        <NavItem :href="route('admin.mail-queue.index')" :active="$page.url.startsWith('/admin/mail-queue')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5h16.5M3.75 12h16.5m-16.5 4.5h10.5M6 5.25h12A2.25 2.25 0 0 1 20.25 7.5v9A2.25 2.25 0 0 1 18 18.75H6A2.25 2.25 0 0 1 3.75 16.5v-9A2.25 2.25 0 0 1 6 5.25Z" />
                                </svg>
                            </template>
                            Mail Queue
                        </NavItem>
                        <NavItem :href="route('admin.dns.index')" :active="$page.url.startsWith('/admin/dns') && !$page.url.startsWith('/admin/dns/server')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5" />
                                </svg>
                            </template>
                            DNS Zones
                        </NavItem>
                        <NavItem :href="route('admin.dns.server.index')" :active="$page.url.startsWith('/admin/dns/server')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 17.25v.75a3 3 0 0 1-3 3h-15a3 3 0 0 1-3-3v-.75m19.5 0a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3m19.5 0h.008v.015h-.008v-.015Zm-19.5 0h.008v.015H2.25v-.015ZM12 12.75h.008v.015H12v-.015Zm0-4.5h.008v.015H12V8.25Z" />
                                </svg>
                            </template>
                            Server DNS
                        </NavItem>
                    </NavGroup>
                </template>

                <!-- Reseller nav -->
                <template v-else-if="$page.props.auth.user.roles?.includes('reseller')">
                    <NavGroup label="Reseller">
                        <NavItem :href="route('reseller.dashboard')" :active="$page.url === '/reseller'">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                                </svg>
                            </template>
                            Dashboard
                        </NavItem>
                        <NavItem :href="route('reseller.accounts.index')" :active="$page.url.startsWith('/reseller/accounts') || $page.url.startsWith('/reseller/clients')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                </svg>
                            </template>
                            Clients
                        </NavItem>
                        <NavItem :href="route('email-accounts.index')" :active="$page.url.startsWith('/email-accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </template>
                            Email Accounts
                        </NavItem>
                        <NavItem :href="route('reseller.packages.index')" :active="$page.url.startsWith('/reseller/packages')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 12 6.75l5.571 3m-11.142 0L12 12.75m-5.571-3v6l5.571 3 5.571-3v-6M4.5 8.714l7.114-3.84a.75.75 0 0 1 .772 0L19.5 8.714a.75.75 0 0 1 .386.659v5.254a.75.75 0 0 1-.386.659l-7.114 3.84a.75.75 0 0 1-.772 0L4.5 15.286a.75.75 0 0 1-.386-.659V9.373A.75.75 0 0 1 4.5 8.714Z" />
                                </svg>
                            </template>
                            Packages
                        </NavItem>
                        <NavItem :href="route('reseller.branding')" :active="$page.url.startsWith('/reseller/branding')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                                </svg>
                            </template>
                            Settings
                        </NavItem>
                    </NavGroup>
                </template>

                <!-- User nav -->
                <template v-else-if="$page.props.auth.user.roles?.includes('user')">
                    <NavGroup label="My Hosting">
                        <NavItem v-if="hasFeature('domains')" :href="route('my.domains.index')" :active="$page.url.startsWith('/my/domains')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                </svg>
                            </template>
                            Domains
                        </NavItem>
                        <NavItem v-if="hasFeature('dns')" :href="route('my.dns.index')" :active="$page.url.startsWith('/my/dns')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5" />
                                </svg>
                            </template>
                            DNS
                        </NavItem>
                        <NavItem v-if="hasFeature('email')" :href="route('email-accounts.index')" :active="$page.url.startsWith('/email-accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </template>
                            Email Accounts
                        </NavItem>
                        <NavItem v-if="hasFeature('databases')" :href="route('my.databases.index')" :active="$page.url.startsWith('/my/databases')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                                </svg>
                            </template>
                            Databases
                        </NavItem>
                        <NavItem v-if="hasFeature('ftp')" :href="route('my.ftp.index')" :active="$page.url.startsWith('/my/ftp')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h7.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-6 3.75 3 3m0 0 3-3m-3 3V1.5m6 9h.75a2.25 2.25 0 0 1 2.25 2.25v7.5a2.25 2.25 0 0 1-2.25 2.25h-7.5a2.25 2.25 0 0 1-2.25-2.25v-.75" />
                                </svg>
                            </template>
                            FTP
                        </NavItem>
                        <NavItem v-if="hasFeature('web_disk')" :href="route('my.web-disk.index')" :active="$page.url.startsWith('/my/web-disk')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.75h16.5m-16.5 0A2.25 2.25 0 0 1 6 7.5h12a2.25 2.25 0 0 1 2.25 2.25m-16.5 0v6.75A2.25 2.25 0 0 0 6 18.75h12a2.25 2.25 0 0 0 2.25-2.25V9.75M7.5 14.25h.008v.008H7.5v-.008Zm3 0h6" />
                                </svg>
                            </template>
                            Web Disk
                        </NavItem>
                        <NavItem v-if="hasFeature('file_manager')" :href="route('my.files.index')" :active="$page.url.startsWith('/my/files')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25m19.5 0v-5.625c0-.621-.504-1.125-1.125-1.125H4.875c-.621 0-1.125.504-1.125 1.125v5.625m19.5 0v2.625c0 .621-.504 1.125-1.125 1.125H4.875c-.621 0-1.125-.504-1.125-1.125v-2.625" />
                                </svg>
                            </template>
                            File Manager
                        </NavItem>
                        <NavItem v-if="hasFeature('disk_usage')" :href="route('my.disk-usage.index')" :active="$page.url.startsWith('/my/disk-usage')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                                </svg>
                            </template>
                            Disk Usage
                        </NavItem>
                        <NavItem v-if="hasFeature('git')" :href="route('my.git.index')" :active="$page.url.startsWith('/my/git')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.008v.008H6.75V6.75Zm0 10.5h.008v.008H6.75v-.008Zm10.5-10.5h.008v.008H17.25V6.75Zm0 10.5h.008v.008H17.25v-.008ZM8.25 6.75a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm0 10.5a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm12-10.5a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm0 10.5a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0ZM8.25 6.75h7.5m-7.5 10.5h7.5m-9-9 1.5 7.5m9-7.5-1.5 7.5" />
                                </svg>
                            </template>
                            Git
                        </NavItem>
                        <NavItem v-if="hasFeature('deliverability')" :href="route('my.deliverability.index')" :active="$page.url.startsWith('/my/deliverability')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </template>
                            Deliverability
                        </NavItem>
                        <NavItem v-if="hasFeature('backups')" :href="route('my.backups.index')" :active="$page.url.startsWith('/my/backups')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                                </svg>
                            </template>
                            Backups
                        </NavItem>
                        <NavItem v-if="hasFeature('metrics')" :href="route('my.metrics.index')" :active="$page.url.startsWith('/my/metrics')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v18m0 0h18M3.75 21 9 14.25l4.5 4.5L20.25 9" />
                                </svg>
                            </template>
                            Metrics
                        </NavItem>
                        <NavItem v-if="hasFeature('app_installer')" :href="route('my.apps.catalog')" :active="$page.url.startsWith('/my/apps')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                            </template>
                            App Installer
                        </NavItem>
                        <NavItem v-if="hasFeature('ssh_keys')" :href="route('my.ssh-keys.index')" :active="$page.url.startsWith('/my/security/ssh-keys')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 0 1 21.75 8.25Z" />
                                </svg>
                            </template>
                            SSH Keys
                        </NavItem>
                        <NavItem v-if="hasFeature('malware_scanner')" :href="route('my.malware.index')" :active="$page.url.startsWith('/my/security/malware-scanner')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.75A11.959 11.959 0 0 1 12 2.714Z" />
                                </svg>
                            </template>
                            Malware Scanner
                        </NavItem>
                        <NavItem v-if="hasFeature('php')" :href="route('my.php.index')" :active="$page.url.startsWith('/my/php')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                                </svg>
                            </template>
                            PHP Settings
                        </NavItem>
                    </NavGroup>
                </template>
            </nav>

            <!-- License status (admin only) -->
            <div
                v-if="$page.props.auth.user.roles?.includes('admin') && $page.props.license"
                class="px-4 py-2 border-t border-gray-800/60"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="licenseClass"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :class="licenseDot"></span>
                        {{ licenseLabel }}
                    </span>
                    <span v-if="$page.props.app?.demo_mode" class="inline-flex items-center rounded-full bg-indigo-900/40 px-2 py-0.5 text-xs font-medium text-indigo-300">
                        Demo
                    </span>
                    <span class="ml-auto text-xs text-gray-600 font-mono">{{ $page.props.app?.version }}</span>
                </div>
            </div>

            <!-- User menu -->
            <div class="border-t border-gray-800 p-4 space-y-2">
                <Link
                    :href="route('profile.security')"
                    class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                    :class="{ 'bg-gray-800 text-gray-200': $page.url.startsWith('/profile') }"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                    Security
                </Link>
                <div class="flex items-center gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-700 text-sm font-medium">
                        {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="truncate text-sm font-medium">{{ $page.props.auth.user.name }}</p>
                        <p class="truncate text-xs text-gray-400">{{ $page.props.auth.user.email }}</p>
                    </div>
                    <Link :href="route('logout')" method="post" as="button" class="text-gray-500 hover:text-gray-300 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                        </svg>
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div class="pl-64 flex flex-col min-h-screen">
            <!-- Top bar -->
            <header class="sticky top-0 z-40 flex h-16 items-center gap-4 border-b border-gray-800 bg-gray-950/80 backdrop-blur px-6">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-400">{{ workspaceLabel }}</p>
                    <h1 class="truncate text-base font-semibold text-gray-100">{{ title }}</h1>
                </div>
                <div class="ml-auto flex min-w-0 items-center gap-3">
                    <div class="relative hidden w-80 lg:block">
                        <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input
                            v-model="quickSearch"
                            type="search"
                            placeholder="Quick jump..."
                            class="field w-full py-2 pl-9 pr-3 text-sm"
                            @keydown.enter.prevent="goToFirstSearchResult"
                            @focus="searchFocused = true"
                            @blur="searchFocused = false"
                        />
                        <div
                            v-if="showSearchResults"
                            class="absolute right-0 top-11 z-50 w-full overflow-hidden rounded-xl border border-gray-800 bg-gray-900 shadow-2xl"
                        >
                            <button
                                v-for="item in filteredNavActions"
                                :key="item.href"
                                type="button"
                                class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-gray-800"
                                @mousedown.prevent="router.visit(item.href)"
                            >
                                <span class="mt-1 h-2 w-2 rounded-full bg-indigo-400"></span>
                                <span>
                                    <span class="block text-sm font-semibold text-gray-100">{{ item.label }}</span>
                                    <span class="block text-xs text-gray-500">{{ item.group }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-800 bg-gray-900 px-3 py-2 text-xs font-semibold text-gray-300">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: activeTheme.swatch }"></span>
                        <span class="sr-only">Theme</span>
                        <select v-model="theme" class="theme-picker bg-transparent text-xs font-semibold focus:outline-none" @change="persistTheme">
                            <option v-for="option in themeOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>
            </header>

            <!-- Impersonation banner -->
            <div
                v-if="$page.props.auth?.impersonation"
                class="mx-6 mt-5 flex items-center justify-between gap-4 rounded-xl border border-sky-700/60 bg-sky-900/25 px-4 py-3"
            >
                <div class="text-sm text-sky-200">
                    Viewing this hosting panel as a client.
                    <span class="text-sky-400">Original operator: {{ $page.props.auth.impersonation.impersonator_email }}</span>
                </div>
                <button
                    type="button"
                    class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-sky-500"
                    @click="stopImpersonation"
                >
                    Return to my panel
                </button>
            </div>

            <!-- 2FA nudge banner -->
            <div
                v-if="show2faNudge"
                class="mx-6 mt-5 flex items-center justify-between gap-4 rounded-xl border border-amber-700/60 bg-amber-900/20 px-4 py-3"
            >
                <div class="flex items-center gap-3 text-sm text-amber-300">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <span>
                        Your account is not protected by two-factor authentication.
                        <Link :href="route('profile.security')" class="ml-1 font-semibold underline underline-offset-2 hover:text-amber-200 transition-colors">
                            Enable 2FA now
                        </Link>
                    </span>
                </div>
                <button @click="dismiss2faNudge" class="shrink-0 text-amber-500 hover:text-amber-300 transition-colors" aria-label="Dismiss">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Flash messages -->
            <div v-if="$page.props.flash?.success" class="mx-6 mt-5">
                <div class="rounded-xl border border-emerald-700 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-300">
                    {{ $page.props.flash.success }}
                </div>
            </div>
            <div v-if="$page.props.flash?.error" class="mx-6 mt-5">
                <div class="rounded-xl border border-red-700 bg-red-900/30 px-4 py-3 text-sm text-red-300">
                    {{ $page.props.flash.error }}
                </div>
            </div>

            <!-- Page slot -->
            <main class="flex-1 px-6 py-6">
                <slot />
            </main>

            <!-- Footer -->
            <footer class="border-t border-gray-800/60 px-6 py-3 flex items-center gap-4 text-xs text-gray-600">
                <span>&copy; {{ year }} Strata Hosting Panel &mdash; MIT License</span>
                <a href="https://github.com/jonathjan0397/strata-hosting-panel/issues" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 hover:text-gray-400 transition-colors">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
                    Report issue
                </a>
                <a href="https://buymeacoffee.com/jonathan0397" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 hover:text-yellow-400 transition-colors ml-auto">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M20.216 6.415l-.132-.666c-.119-.598-.388-1.163-1.001-1.379-.197-.069-.42-.098-.57-.241-.152-.143-.196-.366-.231-.572-.065-.378-.125-.756-.192-1.133-.057-.325-.102-.69-.25-.987-.195-.4-.597-.634-.996-.788a5.723 5.723 0 00-.626-.194c-1-.263-2.05-.36-3.077-.416a25.834 25.834 0 00-3.7.062c-.915.083-1.88.184-2.75.5-.318.116-.646.256-.888.501-.297.302-.393.77-.177 1.146.154.267.415.456.692.58.36.162.737.284 1.123.366 1.075.238 2.189.262-.043 3.276-.013 4.245.021.47.017.959.067 1.35.39.266.219.437.512.495.846.049.27.064.558.029.832-.054.437-.23.899-.632 1.157-.356.231-.813.302-1.23.236-1.037-.166-1.917-.736-2.697-1.427-.285-.25-.583-.491-.897-.695-.39-.249-.843-.443-1.306-.294-.413.132-.729.488-.87.892-.188.548-.124 1.158.013 1.717.145.592.392 1.15.673 1.685.344.657.756 1.282 1.218 1.861.428.537.904 1.039 1.408 1.51a9.513 9.513 0 001.657 1.182c.56.3 1.148.536 1.764.686a8.59 8.59 0 001.9.213 8.19 8.19 0 001.828-.197 7.45 7.45 0 001.708-.617 6.956 6.956 0 001.46-1.029 6.29 6.29 0 001.117-1.394 5.78 5.78 0 00.623-1.71 5.573 5.573 0 00.068-1.889z"/></svg>
                    Buy me a coffee
                </a>
            </footer>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import NavItem from '@/Components/NavItem.vue';
import NavGroup from '@/Components/NavGroup.vue';

const year = new Date().getFullYear();

defineProps({
    title: {
        type: String,
        default: '',
    },
});

const page = usePage();
const accountFeatures = computed(() => page.props.auth?.user?.account?.features ?? []);
const quickSearch = ref('');
const searchFocused = ref(false);
const themeOptions = [
    { value: 'smoke', label: 'Smoky Gray', swatch: 'linear-gradient(135deg, #cbd5e1, #475569)' },
    { value: 'aurora', label: 'Aurora Teal', swatch: 'linear-gradient(135deg, #2dd4bf, #2563eb)' },
    { value: 'ember', label: 'Ember Gold', swatch: 'linear-gradient(135deg, #f59e0b, #dc2626)' },
    { value: 'violet', label: 'Violet Bloom', swatch: 'linear-gradient(135deg, #a78bfa, #ec4899)' },
];
const storedTheme = typeof localStorage !== 'undefined' ? localStorage.getItem('strata_theme') : null;
const theme = ref(themeOptions.some((option) => option.value === storedTheme) ? storedTheme : 'smoke');
const featureFallbacks = {
    forwarders: 'email',
    autoresponders: 'email',
};

const nudgeDismissed = ref(localStorage.getItem('2fa_nudge_dismissed') === '1');

const show2faNudge = computed(() =>
    !nudgeDismissed.value &&
    page.props.auth?.user &&
    !page.props.auth.user.two_factor_enabled &&
    !page.props.ziggy?.location?.includes('/profile')
);

function dismiss2faNudge() {
    localStorage.setItem('2fa_nudge_dismissed', '1');
    nudgeDismissed.value = true;
}

const licenseStatus = computed(() => page.props.license?.status ?? 'active');

const licenseLabel = computed(() => ({
    active:    'License Active',
    suspended: 'License Suspended',
    unknown:   'License Unknown',
}[licenseStatus.value] ?? 'License Active'));

const licenseClass = computed(() => ({
    active:    'bg-emerald-900/40 text-emerald-300',
    suspended: 'bg-red-900/40 text-red-300',
    unknown:   'bg-gray-800 text-gray-400',
}[licenseStatus.value] ?? 'bg-emerald-900/40 text-emerald-300'));

const licenseDot = computed(() => ({
    active:    'bg-emerald-400',
    suspended: 'bg-red-400',
    unknown:   'bg-gray-500',
}[licenseStatus.value] ?? 'bg-emerald-400'));

const activeTheme = computed(() => themeOptions.find((option) => option.value === theme.value) ?? themeOptions[0]);
const themeClass = computed(() => ['theme-glass', `theme-glass-${theme.value}`]);

const workspaceLabel = computed(() => {
    const roles = page.props.auth?.user?.roles ?? [];
    if (roles.includes('admin')) return 'Admin Workspace';
    if (roles.includes('reseller')) return 'Reseller Workspace';
    if (roles.includes('user')) return 'Hosting Workspace';
    return 'Workspace';
});

const navActions = computed(() => {
    const roles = page.props.auth?.user?.roles ?? [];
    const items = [{ label: 'Dashboard', group: workspaceLabel.value, href: route('dashboard') }];

    if (roles.includes('admin')) {
        items.push(
            { label: 'Accounts', group: 'Hosting', href: route('admin.accounts.index') },
            { label: 'Account Migrations', group: 'Hosting', href: route('admin.migrations.index') },
            { label: 'Backup Imports', group: 'Hosting', href: route('admin.backup-imports.index') },
            { label: 'cPanel / CWP Imports', group: 'Hosting', href: route('admin.backup-imports.index') },
            { label: 'Packages', group: 'Hosting', href: route('admin.packages.index') },
            { label: 'Feature Lists', group: 'Hosting', href: route('admin.feature-lists.index') },
            { label: 'Domains', group: 'Hosting', href: route('admin.domains.index') },
            { label: 'DNS Zones', group: 'Hosting', href: route('admin.dns.index') },
            { label: 'Nodes', group: 'Infrastructure', href: route('admin.nodes.index') },
            { label: 'Backups', group: 'System', href: route('admin.backups.index') },
            { label: 'Firewall and IP Blocker', group: 'Security', href: route('admin.security.firewall') },
            { label: 'Fail2Ban Administration', group: 'Security', href: route('admin.security.fail2ban.index') },
            { label: 'Email Accounts', group: 'Mail', href: route('email-accounts.index') },
            { label: 'Mail Queue', group: 'Mail', href: route('admin.mail-queue.index') },
            { label: 'Audit Log', group: 'System', href: route('admin.audit-log.index') },
            { label: 'Webhooks', group: 'System', href: route('admin.webhooks.index') },
        );
    } else if (roles.includes('reseller')) {
        items.push(
            { label: 'Clients', group: 'Reseller', href: route('reseller.accounts.index') },
            { label: 'Email Accounts', group: 'Mail', href: route('email-accounts.index') },
            { label: 'Packages', group: 'Reseller', href: route('reseller.packages.index') },
            { label: 'Reseller Settings', group: 'Reseller', href: route('reseller.branding') },
        );
    } else if (roles.includes('user')) {
        addUserAction(items, 'domains', 'Domains', 'Websites', route('my.domains.index'));
        addUserAction(items, 'email', 'Email Accounts', 'Mail', route('email-accounts.index'));
        addUserAction(items, 'email', 'Email Delivery', 'Mail', route('my.email.delivery'));
        addUserAction(items, 'file_manager', 'File Manager', 'Files', route('my.files.index'));
        addUserAction(items, 'web_disk', 'Web Disk', 'Files', route('my.web-disk.index'));
        addUserAction(items, 'databases', 'Databases', 'Data', route('my.databases.index'));
        addUserAction(items, 'databases', 'Database Tools', 'Data', route('my.databases.tools'));
        addUserAction(items, 'backups', 'Backups', 'Files', route('my.backups.index'));
        addUserAction(items, 'metrics', 'Metrics and Logs', 'Diagnostics', route('my.metrics.index'));
        addUserAction(items, 'git', 'Git Version Control', 'Developer Tools', route('my.git.index'));
        addUserAction(items, 'ssh_keys', 'SSH Keys', 'Security', route('my.ssh-keys.index'));
        addUserAction(items, 'malware_scanner', 'Malware Scanner', 'Security', route('my.malware.index'));
    }

    return items;
});

const filteredNavActions = computed(() => {
    const query = quickSearch.value.trim().toLowerCase();
    if (! query) return navActions.value.slice(0, 6);

    return navActions.value
        .filter((item) => `${item.label} ${item.group}`.toLowerCase().includes(query))
        .slice(0, 8);
});

const showSearchResults = computed(() =>
    searchFocused.value && filteredNavActions.value.length > 0
);

function addUserAction(items, feature, label, group, href) {
    if (hasFeature(feature)) {
        items.push({ label, group, href });
    }
}

function persistTheme() {
    localStorage.setItem('strata_theme', theme.value);
}

function goToFirstSearchResult() {
    const first = filteredNavActions.value[0];
    if (! first) return;
    quickSearch.value = '';
    router.visit(first.href);
}

function stopImpersonation() {
    router.post(route('impersonation.stop'));
}

function hasFeature(feature) {
    if (page.props.auth?.user?.roles?.includes('admin')) {
        return true;
    }

    if (accountFeatures.value.includes(feature)) {
        return true;
    }

    const fallback = featureFallbacks[feature];

    return fallback ? accountFeatures.value.includes(fallback) : false;
}
</script>
