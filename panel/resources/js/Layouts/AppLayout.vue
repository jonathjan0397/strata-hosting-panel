<template>
    <div class="min-h-screen bg-gray-950 text-gray-100">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-gray-900 border-r border-gray-800"
        >
            <!-- Logo -->
            <div class="flex h-16 items-center gap-3 px-5 border-b border-gray-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                    <span class="text-sm font-bold text-white">S</span>
                </div>
                <span class="text-lg font-semibold tracking-tight">Strata Panel</span>
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
                        <NavItem :href="route('admin.accounts.index')" :active="$page.url.startsWith('/admin/accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                </svg>
                            </template>
                            Accounts
                        </NavItem>
                        <NavItem :href="route('admin.domains.index')" :active="$page.url.startsWith('/admin/domains')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                </svg>
                            </template>
                            Domains
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
                        <NavItem :href="route('reseller.accounts.index')" :active="$page.url.startsWith('/reseller/accounts')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                </svg>
                            </template>
                            Clients
                        </NavItem>
                    </NavGroup>
                </template>

                <!-- User nav -->
                <template v-else-if="$page.props.auth.user.roles?.includes('user')">
                    <NavGroup label="My Hosting">
                        <NavItem :href="route('my.domains.index')" :active="$page.url.startsWith('/my/domains')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                </svg>
                            </template>
                            Domains
                        </NavItem>
                        <NavItem :href="route('my.databases.index')" :active="$page.url.startsWith('/my/databases')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                                </svg>
                            </template>
                            Databases
                        </NavItem>
                        <NavItem :href="route('my.ftp.index')" :active="$page.url.startsWith('/my/ftp')">
                            <template #icon>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h7.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-6 3.75 3 3m0 0 3-3m-3 3V1.5m6 9h.75a2.25 2.25 0 0 1 2.25 2.25v7.5a2.25 2.25 0 0 1-2.25 2.25h-7.5a2.25 2.25 0 0 1-2.25-2.25v-.75" />
                                </svg>
                            </template>
                            FTP
                        </NavItem>
                    </NavGroup>
                </template>
            </nav>

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
                <h1 class="text-base font-semibold text-gray-100">{{ title }}</h1>
                <div class="ml-auto flex items-center gap-3">
                    <!-- Flash messages badge area could go here -->
                </div>
            </header>

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
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import NavItem from '@/Components/NavItem.vue';
import NavGroup from '@/Components/NavGroup.vue';

defineProps({
    title: {
        type: String,
        default: '',
    },
});

const page = usePage();

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
</script>
