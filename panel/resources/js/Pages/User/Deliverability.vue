<template>
    <AppLayout title="Email Deliverability">
        <div class="max-w-3xl space-y-6 p-6">

            <!-- Header -->
            <div>
                <h1 class="text-lg font-semibold text-gray-100">Email Deliverability Troubleshooter</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Check your domain's email configuration — MX, SPF, DKIM, DMARC, reverse DNS, and blacklists.
                </p>
            </div>

            <DeliverabilityChecker
                :check-url="route('my.deliverability.check')"
                :server-ip="serverIp"
            >
                <!-- Override the default free-text input with a domain dropdown -->
                <template #domain-input="{ domain, setDomain }">
                    <div v-if="domains.length" class="flex items-center gap-3">
                        <select
                            :value="domain"
                            @change="setDomain($event.target.value)"
                            class="field flex-1"
                        >
                            <option value="">Select a domain…</option>
                            <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>
                    <div v-else class="rounded-lg border border-gray-700 bg-gray-900 px-4 py-3 text-sm text-gray-400">
                        No domains found on your account.
                        <Link :href="route('my.domains.create')" class="ml-1 text-indigo-400 hover:underline">Add one</Link>
                    </div>
                </template>
            </DeliverabilityChecker>

        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeliverabilityChecker from '@/Components/DeliverabilityChecker.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    domains:  { type: Array,  default: () => [] },
    serverIp: { type: String, default: '' },
});
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
