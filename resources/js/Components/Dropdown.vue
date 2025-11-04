<script setup>
import { computed, onMounted, onUnmounted, ref, nextTick } from 'vue';

const props = defineProps({
    align: {
        type: String,
        default: 'right',
    },
    width: {
        type: String,
        default: '48',
    },
    contentClasses: {
        type: String,
        default: 'py-1 bg-white dark:bg-gray-800',
    },
});

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

const widthClass = computed(() => {
    return {
        48: 'w-48',
    }[props.width.toString()];
});

const alignmentClasses = computed(() => {
    if (props.align === 'left') {
        return 'ltr:origin-top-left rtl:origin-top-right';
    } else if (props.align === 'right') {
        return 'ltr:origin-top-right rtl:origin-top-left';
    } else {
        return 'origin-top';
    }
});

const open = ref(false);
const triggerRef = ref(null);
const dropdownStyle = ref({});

const handleToggle = () => {
    open.value = !open.value;
    if (open.value) {
        nextTick(() => {
            updateDropdownPosition();
        });
    }
};

const updateDropdownPosition = () => {
    if (!triggerRef.value) return;

    const rect = triggerRef.value.getBoundingClientRect();

    if (props.align === 'left') {
        dropdownStyle.value = {
            top: `${rect.bottom + 8}px`,
            left: `${rect.left}px`,
        };
    } else if (props.align === 'right') {
        dropdownStyle.value = {
            top: `${rect.bottom + 8}px`,
            right: `${window.innerWidth - rect.right}px`,
        };
    } else {
        dropdownStyle.value = {
            top: `${rect.bottom + 8}px`,
            left: `${rect.left}px`,
        };
    }
};
</script>

<template>
    <div class="relative" ref="triggerRef">
        <div @click="handleToggle">
            <slot name="trigger" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <Teleport to="body">
            <div
                v-show="open"
                class="fixed inset-0 z-40"
                @click="open = false"
            ></div>

            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-show="open"
                    class="fixed z-50 mt-2 mb-2 rounded-md shadow-lg"
                    :class="[widthClass, alignmentClasses]"
                    :style="dropdownStyle"
                    @click="open = false"
                >
                    <div
                        class="rounded-md ring-1 ring-black ring-opacity-5 dark:ring-white dark:ring-opacity-20"
                        :class="contentClasses"
                    >
                        <slot name="content" />
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
