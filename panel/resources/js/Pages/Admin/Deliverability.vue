<template>
    <AppLayout title="Email Deliverability">
        <div class="max-w-3xl space-y-6 p-6">

            <!-- Header -->
            <div>
                <h1 class="text-lg font-semibold text-gray-100">Email Deliverability Troubleshooter</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Run a full DNS deliverability audit on any domain — MX, SPF, DKIM, DMARC, reverse DNS, and blacklist checks.
                </p>
            </div>

            <DeliverabilityChecker
                :check-url="route('admin.deliverability.check')"
                :server-ip="serverIp"
            />

        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeliverabilityChecker from '@/Components/DeliverabilityChecker.vue';

defineProps({
    serverIp: { type: String, default: '' },
});
</script>
