<script setup>
import { computed } from 'vue';
import SelectInput from '@/Components/SelectInput.vue';

/**
 * Picks one of the budget's categories (or "Uncategorized") and keeps both
 * category_id and the legacy category name in sync. A legacy free-text value
 * that matches no category is offered as its own option so saving an old
 * record without touching the field keeps it.
 */
const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    categoryId: {
        type: [String, Number, null],
        default: null,
    },
    category: {
        type: [String, null],
        default: null,
    },
    id: {
        type: String,
        default: 'category_id',
    },
});

const emit = defineEmits(['update:categoryId', 'update:category']);

const LEGACY = '__legacy__';

const normalize = (name) => String(name ?? '').trim().toLowerCase();

// An unlinked name that matches a category is linked by the server on save.
const nameMatch = computed(() => {
    if (props.categoryId || !props.category) {
        return null;
    }

    return props.categories.find((c) => normalize(c.name) === normalize(props.category)) ?? null;
});

const legacyName = computed(() => {
    if (props.categoryId || !props.category || nameMatch.value) {
        return null;
    }

    return props.category;
});

const selected = computed(() => {
    if (props.categoryId) {
        return String(props.categoryId);
    }
    if (nameMatch.value) {
        return String(nameMatch.value.id);
    }

    return legacyName.value ? LEGACY : '';
});

const onChange = (value) => {
    if (value === LEGACY) {
        return;
    }
    if (value === '') {
        emit('update:categoryId', null);
        emit('update:category', '');

        return;
    }
    const chosen = props.categories.find((c) => String(c.id) === String(value));
    emit('update:categoryId', chosen ? chosen.id : null);
    emit('update:category', chosen ? chosen.name : '');
};
</script>

<template>
    <SelectInput :id="id" :model-value="selected" @update:model-value="onChange">
        <option value="">Uncategorized</option>
        <option v-if="legacyName" :value="LEGACY">{{ legacyName }} (not a budget category)</option>
        <option v-for="option in categories" :key="option.id" :value="String(option.id)">
            {{ option.name }}
        </option>
    </SelectInput>
</template>
