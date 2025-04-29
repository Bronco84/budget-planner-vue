<template>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <!-- Amount -->
    <div>
      <InputLabel for="amount" value="Amount" />
      <div class="mt-1 relative rounded-md shadow-sm">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <span class="text-gray-500 sm:text-sm">$</span>
        </div>
        <TextInput
          id="amount"
          type="number"
          step="0.01"
          v-model="localForm.amount"
          class="pl-7 block w-full"
          placeholder="0.00"
          required
        />
      </div>
      <InputError :message="errors.amount" class="mt-2" />
    </div>

    <!-- Amount Type -->
    <div>
      <InputLabel value="Amount Type" />
      <div class="mt-2 space-y-2">
        <div class="flex items-center">
          <input
            id="static_amount"
            type="radio"
            value="false"
            v-model="localForm.is_dynamic_amount"
            class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
          />
          <label for="static_amount" class="ml-2 block text-sm text-gray-900">
            Static Amount
          </label>
        </div>
        <div class="flex items-center">
          <input
            id="dynamic_amount"
            type="radio"
            value="true"
            v-model="localForm.is_dynamic_amount"
            class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
          />
          <label for="dynamic_amount" class="ml-2 block text-sm text-gray-900">
            Dynamic Amount (Recurring Only)
          </label>
        </div>
      </div>
      <InputError :message="errors.is_dynamic_amount" class="mt-2" />
    </div>

    <!-- Dynamic Amount Options -->
    <template v-if="localForm.is_dynamic_amount === 'true'">
      <div>
        <InputLabel for="min_amount" value="Minimum Amount (Optional)" />
        <TextInput
          id="min_amount"
          type="number"
          step="0.01"
          v-model="localForm.min_amount"
          class="mt-1 block w-full"
        />
        <InputError :message="errors.min_amount" class="mt-2" />
      </div>
      <div>
        <InputLabel for="max_amount" value="Maximum Amount (Optional)" />
        <TextInput
          id="max_amount"
          type="number"
          step="0.01"
          v-model="localForm.max_amount"
          class="mt-1 block w-full"
        />
        <InputError :message="errors.max_amount" class="mt-2" />
      </div>
      <div>
        <InputLabel for="average_amount" value="Starting Average (Optional)" />
        <TextInput
          id="average_amount"
          type="number"
          step="0.01"
          v-model="localForm.average_amount"
          class="mt-1 block w-full"
        />
        <InputError :message="errors.average_amount" class="mt-2" />
      </div>
    </template>

    <!-- Frequency -->
    <div>
      <InputLabel for="frequency" value="Frequency" />
      <SelectInput
        id="frequency"
        v-model="localForm.frequency"
        class="mt-1 block w-full"
        required
      >
        <option value="">Select frequency</option>
        <option value="daily">Daily</option>
        <option value="weekly">Weekly</option>
        <option value="biweekly">Bi-weekly</option>
        <option value="monthly">Monthly</option>
        <option value="quarterly">Quarterly</option>
        <option value="yearly">Yearly</option>
      </SelectInput>
      <InputError :message="errors.frequency" class="mt-2" />
    </div>

    <!-- Day of Week (for weekly/biweekly) -->
    <div v-if="localForm.frequency === 'weekly' || localForm.frequency === 'biweekly'">
      <InputLabel for="day_of_week" value="Day of Week" />
      <SelectInput
        id="day_of_week"
        v-model="localForm.day_of_week"
        class="mt-1 block w-full"
        required
      >
        <option value="">Select day</option>
        <option value="0">Sunday</option>
        <option value="1">Monday</option>
        <option value="2">Tuesday</option>
        <option value="3">Wednesday</option>
        <option value="4">Thursday</option>
        <option value="5">Friday</option>
        <option value="6">Saturday</option>
      </SelectInput>
      <InputError :message="errors.day_of_week" class="mt-2" />
    </div>

    <!-- Day of Month (for monthly/quarterly) -->
    <div v-if="localForm.frequency === 'monthly' || localForm.frequency === 'quarterly'">
      <InputLabel for="day_of_month" value="Day of Month" />
      <SelectInput
        id="day_of_month"
        v-model="localForm.day_of_month"
        class="mt-1 block w-full"
        required
      >
        <option value="">Select day</option>
        <option v-for="day in 31" :key="day" :value="day">{{ day }}</option>
      </SelectInput>
      <InputError :message="errors.day_of_month" class="mt-2" />
    </div>

    <!-- Start Date -->
    <div>
      <InputLabel for="start_date" value="Start Date" />
      <TextInput
        id="start_date"
        type="date"
        v-model="localForm.start_date"
        class="mt-1 block w-full"
        required
      />
      <InputError :message="errors.start_date" class="mt-2" />
    </div>

    <!-- End Date -->
    <div>
      <InputLabel for="end_date" value="End Date (Optional)" />
      <TextInput
        id="end_date"
        type="date"
        v-model="localForm.end_date"
        class="mt-1 block w-full"
      />
      <InputError :message="errors.end_date" class="mt-2" />
    </div>
  </div>
</template>

<script setup>
import { watch, reactive, toRefs } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
  modelValue: Object,
  errors: Object
});

const emit = defineEmits(['update:modelValue']);

const localForm = reactive({ ...props.modelValue });

watch(
  () => ({ ...localForm }),
  (val) => emit('update:modelValue', val),
  { deep: true }
);
</script> 