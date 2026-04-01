<template>
    <div class="pt-4">
        <p class="mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
            {{ label }}
        </p>
        <div class="space-y-0.5">
            <slot />
        </div>
    </div>
</template>

<script setup>
defineProps({
    label: String,
});
</script>
