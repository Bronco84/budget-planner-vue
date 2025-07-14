<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Activity Log</h3>
            <span class="text-sm text-gray-500">{{ activities.length }} activit{{ activities.length !== 1 ? 'ies' : 'y' }}</span>
        </div>

        <!-- Empty State -->
        <div v-if="activities.length === 0" class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="mt-2 text-sm text-gray-500">No activity recorded yet</p>
        </div>

        <!-- Activity List -->
        <div v-else class="space-y-4">
            <div
                v-for="activity in activities"
                :key="activity.id"
                class="relative bg-gray-50 rounded-lg border border-gray-200 overflow-hidden"
            >
                <!-- Clickable Header -->
                <div
                    @click="toggleActivity(activity.id)"
                    class="flex items-start space-x-3 p-4 cursor-pointer hover:bg-gray-100 transition-colors"
                    :class="{ 'bg-gray-100': isExpanded(activity.id) }"
                >
                    <!-- Activity Icon -->
                    <div class="flex-shrink-0">
                        <div :class="getActivityIconClass(activity.event)" class="flex items-center justify-center w-8 h-8 rounded-full">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getActivityIconPath(activity.event)"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Activity Content -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-gray-900">
                                {{ activity.description }}
                            </p>
                            <div class="flex items-center space-x-2">
                                <time class="text-xs text-gray-500" :datetime="activity.created_at">
                                    {{ formatDate(activity.created_at) }}
                                </time>
                                <!-- Expand/Collapse Icon -->
                                <div 
                                    v-if="activity.properties && Object.keys(activity.properties).length > 0" 
                                    class="flex-shrink-0"
                                >
                                    <svg 
                                        class="w-4 h-4 text-gray-400 transition-transform duration-200" 
                                        :class="{ 'rotate-180': isExpanded(activity.id) }"
                                        fill="none" 
                                        viewBox="0 0 24 24" 
                                        stroke="currentColor"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Causer Information -->
                        <div v-if="activity.causer" class="mt-1">
                            <p class="text-xs text-gray-600">
                                by {{ activity.causer.name }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Expandable Properties/Changes -->
                <div 
                    v-if="activity.properties && Object.keys(activity.properties).length > 0 && isExpanded(activity.id)" 
                    class="px-4 pb-4 border-t border-gray-200 bg-white"
                >
                    <!-- Show old/new values for updates -->
                    <div v-if="activity.event === 'updated' && activity.properties.old && activity.properties.attributes" class="space-y-1 mt-3">
                        <p class="text-xs font-medium text-gray-700">Changes:</p>
                        <div class="space-y-1">
                            <div 
                                v-for="(newValue, field) in activity.properties.attributes" 
                                :key="field"
                                v-if="activity.properties.old[field] !== newValue"
                                class="text-xs"
                            >
                                <span class="font-medium text-gray-600">{{ formatFieldName(field) }}:</span>
                                <span class="text-red-600 line-through ml-1">{{ formatFieldValue(field, activity.properties.old[field]) }}</span>
                                <span class="text-green-600 ml-1">→ {{ formatFieldValue(field, newValue) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Show created values -->
                    <div v-else-if="activity.event === 'created' && activity.properties.attributes" class="space-y-1 mt-3">
                        <p class="text-xs font-medium text-gray-700">Initial values:</p>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div v-for="(value, field) in activity.properties.attributes" :key="field">
                                <span class="font-medium text-gray-600">{{ formatFieldName(field) }}:</span>
                                <span class="text-gray-800 ml-1">{{ formatFieldValue(field, value) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Show custom properties -->
                    <div v-else class="space-y-1 mt-3">
                        <p class="text-xs font-medium text-gray-700">Details:</p>
                        <div class="text-xs text-gray-600 bg-gray-50 p-3 rounded border">
                            <pre class="whitespace-pre-wrap">{{ JSON.stringify(activity.properties, null, 2) }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineProps, ref } from 'vue'

const props = defineProps({
    activities: {
        type: Array,
        default: () => []
    }
})

// Track which activities are expanded
const expandedActivities = ref(new Set())

// Toggle expansion state of an activity
const toggleActivity = (activityId) => {
    if (expandedActivities.value.has(activityId)) {
        expandedActivities.value.delete(activityId)
    } else {
        expandedActivities.value.add(activityId)
    }
}

// Check if an activity is expanded
const isExpanded = (activityId) => {
    return expandedActivities.value.has(activityId)
}

const getActivityIconClass = (event) => {
    switch (event) {
        case 'created':
            return 'bg-green-100 text-green-600'
        case 'updated':
            return 'bg-blue-100 text-blue-600'
        case 'deleted':
            return 'bg-red-100 text-red-600'
        default:
            return 'bg-gray-100 text-gray-600'
    }
}

const getActivityIconPath = (event) => {
    switch (event) {
        case 'created':
            return 'M12 4v16m8-8H4' // Plus icon
        case 'updated':
            return 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z' // Edit icon
        case 'deleted':
            return 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16' // Trash icon
        default:
            return 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' // Info icon
    }
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

const formatFieldName = (field) => {
    // Convert snake_case to Title Case
    return field
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ')
}

const formatFieldValue = (field, value) => {
    if (value === null || value === undefined) {
        return '(empty)'
    }
    
    // Format specific fields
    switch (field) {
        case 'amount_in_cents':
            return `$${(value / 100).toFixed(2)}`
        case 'date':
            return new Date(value).toLocaleDateString()
        case 'is_reconciled':
        case 'is_plaid_imported':
            return value ? 'Yes' : 'No'
        default:
            return String(value)
    }
}
</script> 