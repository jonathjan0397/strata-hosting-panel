<template>
    <AppLayout title="My Hosting">
        <div class="p-6">
            <PageHeader
                eyebrow="Hosting"
                title="My Hosting"
                description="Hosting access is waiting on account provisioning."
            />

            <EmptyState
                title="No hosting account"
                description="Your account has not been provisioned yet. Contact your administrator."
            />
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
</script>
