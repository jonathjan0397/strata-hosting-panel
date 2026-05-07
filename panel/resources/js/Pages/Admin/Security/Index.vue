<template>
    <AppLayout title="Security Center">
        <div class="space-y-6 p-6">
            <div>
                <h1 class="text-lg font-semibold text-gray-100">Security Center</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Manage node-level firewall controls, Fail2Ban jails, spam filtering, and host security tooling from dedicated workspaces.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    :href="route('admin.security.firewall')"
                    class="rounded-2xl border border-white/10 bg-white/5 p-5 transition hover:border-cyan-400/40 hover:bg-white/10"
                >
                    <p class="text-sm font-semibold text-gray-100">Firewall and IP Blocker</p>
                    <p class="mt-2 text-sm text-gray-400">Review UFW rules, open ports, and block abusive IP addresses.</p>
                </Link>

                <Link
                    :href="route('admin.security.fail2ban.index')"
                    class="rounded-2xl border border-white/10 bg-white/5 p-5 transition hover:border-cyan-400/40 hover:bg-white/10"
                >
                    <p class="text-sm font-semibold text-gray-100">Fail2Ban Administration</p>
                    <p class="mt-2 text-sm text-gray-400">Start, stop, or restart Fail2Ban, add manual bans, and remove banned IPs by jail.</p>
                </Link>

                <Link
                    :href="route('admin.security.spam')"
                    class="rounded-2xl border border-white/10 bg-white/5 p-5 transition hover:border-cyan-400/40 hover:bg-white/10"
                >
                    <p class="text-sm font-semibold text-gray-100">Spam Filter</p>
                    <p class="mt-2 text-sm text-gray-400">Inspect Rspamd health and mail filtering status across managed nodes.</p>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>
