<template>
    <AppLayout title="Email Deliverability">
        <div class="max-w-3xl space-y-6 p-6">
            <PageHeader
                eyebrow="Email"
                title="Email Deliverability Troubleshooter"
                description="Check your domain's email configuration: MX, SPF, DKIM, DMARC, public-IP PTR/rDNS, and blacklists."
            />

            <DeliverabilityChecker
                :check-url="route('my.deliverability.check')"
                :server-ip="serverIp"
            >
                <template #domain-input="{ domain, setDomain }">
                    <div v-if="domains.length" class="flex items-center gap-3">
                        <select
                            :value="domain"
                            @change="setDomain($event.target.value)"
                            class="field flex-1"
                        >
                            <option value="">Select a domain...</option>
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
import PageHeader from '@/Components/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    domains: { type: Array, default: () => [] },
    serverIp: { type: String, default: '' },
});
</script>
