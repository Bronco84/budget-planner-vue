<template>
  <Head title="Edit Account" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Account</h2>
    </template>

    <div class="py-8">
      <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <form @submit.prevent="submit">
          <!-- Two-column grid layout -->
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Left Column: Account Details (2/3 width) -->
            <div class="lg:col-span-2 space-y-6">
              
              <!-- Account Information Card -->
              <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-100">
                      <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                      </svg>
                    </div>
                    <div>
                      <h3 class="text-base font-semibold text-gray-900">Account Information</h3>
                      <p class="text-sm text-gray-500">Basic details about this account</p>
                    </div>
                  </div>
                </div>
                
                <div class="p-6 space-y-5">
                  <!-- Account Name -->
                  <div>
                    <InputLabel for="name" value="Account Name" required />
                    <TextInput
                      id="name"
                      type="text"
                      class="mt-1.5 block w-full"
                      v-model="form.name"
                      required
                      autofocus
                    />
                    <InputError class="mt-2" :message="form.errors.name" />
                  </div>
                  
                  <!-- Account Type -->
                  <div>
                    <InputLabel for="type" value="Account Type" :required="!isPlaidConnected" />
                    
                    <!-- Read-only display for Plaid-connected accounts -->
                    <div v-if="isPlaidConnected" class="mt-1.5">
                      <div class="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-slate-50 to-gray-50 rounded-lg border border-gray-200">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-indigo-600 shadow-sm">
                          <svg v-if="isLiabilityType" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                          </svg>
                          <svg v-else class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                          </svg>
                        </div>
                        <div class="flex-1">
                          <span class="text-base font-medium text-gray-900">{{ getAccountTypeDisplayName(account.type) }}</span>
                          <p class="text-xs text-gray-500 mt-0.5">Automatically classified by your bank</p>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Editable select for manual accounts -->
                    <template v-else>
                      <SelectInput
                        id="type"
                        class="mt-1.5 block w-full"
                        v-model="form.type"
                        required
                      >
                        <option value="checking">Checking</option>
                        <option value="savings">Savings</option>
                        <option value="credit card">Credit Card</option>
                        <option value="line of credit">Line of Credit</option>
                        <option value="mortgage">Mortgage</option>
                        <option value="investment">Investment</option>
                        <option value="loan">Loan</option>
                        <option value="other">Other</option>
                      </SelectInput>
                      <InputError class="mt-2" :message="form.errors.type" />
                    </template>
                  </div>
                  
                  <!-- Account Logo -->
                  <div>
                    <InputLabel for="custom_logo" value="Account Logo (Optional)" />
                    <p class="text-xs text-gray-500 mt-1">Fetch a logo automatically or upload a custom one.</p>
                    
                    <div class="mt-3 space-y-3">
                      <!-- Current Logo Preview -->
                      <div v-if="hasLogo || (isPlaidConnected && account.plaid_account?.plaid_connection?.institution_logo)" class="flex items-center gap-4">
                        <div class="flex-shrink-0">
                          <InstitutionLogo
                            :custom-logo="form.custom_logo || account.custom_logo"
                            :logo-url="form.logo_url || account.logo_url"
                            :logo="account.plaid_account?.plaid_connection?.institution_logo"
                            :name="account.name"
                            size="lg"
                          />
                        </div>
                        <div class="flex-1">
                          <p class="text-sm font-medium text-gray-700">
                            {{ logoSourceLabel }}
                          </p>
                          <p class="text-xs text-gray-500">
                            {{ logoSourceDescription }}
                          </p>
                        </div>
                        <button
                          v-if="hasCustomLogo"
                          type="button"
                          @click="clearLogo"
                          class="text-sm text-red-600 hover:text-red-700 font-medium"
                        >
                          Remove
                        </button>
                      </div>
                      
                      <!-- Logo Actions -->
                      <div class="flex items-center gap-3 flex-wrap">
                        <!-- Fetch Logo Button -->
                        <button
                          type="button"
                          @click="fetchLogo"
                          :disabled="fetchingLogo"
                          class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-50 border border-indigo-200 rounded-lg text-sm font-medium text-indigo-700 hover:bg-indigo-100 transition-colors disabled:opacity-50"
                        >
                          <svg v-if="fetchingLogo" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                          </svg>
                          <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                          </svg>
                          {{ fetchingLogo ? 'Fetching...' : 'Fetch Logo' }}
                        </button>
                        
                        <!-- Or separator -->
                        <span class="text-sm text-gray-400">or</span>
                        
                        <!-- Upload Custom Logo -->
                        <input
                          type="file"
                          id="custom_logo"
                          ref="logoInput"
                          @change="handleLogoUpload"
                          accept="image/*"
                          class="hidden"
                        />
                        <button
                          type="button"
                          @click="$refs.logoInput.click()"
                          class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                        >
                          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                          </svg>
                          Upload Custom
                        </button>
                        <span v-if="logoUploading" class="text-sm text-gray-500">Uploading...</span>
                      </div>
                      
                      <InputError class="mt-2" :message="form.errors.custom_logo" />
                      <InputError class="mt-2" :message="form.errors.logo_url" />
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Account Status Card -->
              <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100">
                      <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                      </svg>
                    </div>
                    <div>
                      <h3 class="text-base font-semibold text-gray-900">Settings</h3>
                      <p class="text-sm text-gray-500">Control how this account is used</p>
                    </div>
                  </div>
                </div>
                
                <div class="p-6">
                  <div class="space-y-3">
                    <label 
                      class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all"
                      :class="accountStatus === 'active' ? 'border-indigo-500 bg-indigo-50/50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'"
                    >
                      <input
                        type="radio"
                        name="status"
                        value="active"
                        v-model="accountStatus"
                        class="mt-0.5 h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <div class="flex-1">
                        <div class="flex items-center gap-2">
                          <span class="font-medium text-gray-900">Active</span>
                          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">
                            Recommended
                          </span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                          Include in projections and count toward your total balance
                        </p>
                      </div>
                    </label>
                    
                    <label 
                      class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all"
                      :class="accountStatus === 'excluded' ? 'border-indigo-500 bg-indigo-50/50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'"
                    >
                      <input
                        type="radio"
                        name="status"
                        value="excluded"
                        v-model="accountStatus"
                        class="mt-0.5 h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <div class="flex-1">
                        <span class="font-medium text-gray-900">Excluded</span>
                        <p class="mt-1 text-sm text-gray-500">
                          Track transactions but hide from projections and totals
                        </p>
                      </div>
                    </label>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Right Column: Bank Connection & Actions (1/3 width) -->
            <div class="space-y-6">
              
              <!-- Bank Connection Card -->
              <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg" :class="isPlaidConnected ? 'bg-emerald-100' : 'bg-gray-100'">
                      <svg v-if="isPlaidConnected" class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                      </svg>
                      <svg v-else class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.181 8.68a4.503 4.503 0 0 1 1.903 6.405m-9.768-2.782L3.56 14.06a4.5 4.5 0 0 0 6.364 6.365l3.129-3.129m5.614-5.615 1.757-1.757a4.5 4.5 0 0 0-6.364-6.365l-4.5 4.5a4.5 4.5 0 0 0-.264 6.086m8.035-8.036-1.757 1.757" />
                      </svg>
                    </div>
                    <div>
                      <h3 class="text-base font-semibold text-gray-900">Bank Connection</h3>
                      <p class="text-sm text-gray-500">{{ isPlaidConnected ? 'Connected' : 'Not connected' }}</p>
                    </div>
                  </div>
                </div>
                
                <div class="p-6">
                  <div v-if="isPlaidConnected" class="space-y-4">
                    <div class="flex items-center gap-3">
                      <InstitutionLogo
                        :custom-logo="account.custom_logo"
                        :logo-url="account.logo_url"
                        :logo="account.plaid_account?.plaid_connection?.institution_logo"
                        :name="account.plaid_account?.institution_name || 'Unknown Bank'"
                        size="lg"
                      />
                      <div class="flex-1 min-w-0">
                        <p class="font-medium text-gray-900 truncate">{{ account.plaid_account?.institution_name || 'Unknown Bank' }}</p>
                        <p class="text-xs text-gray-500">
                          Last synced: <PlaidSyncTimestamp
                            :timestamp="account.plaid_account?.plaid_connection?.last_sync_at"
                            format="absolute"
                          />
                        </p>
                      </div>
                    </div>
                    
                    <Link 
                      :href="route('plaid.link', [budget.id, account.id])"
                      class="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.204-.107-.397.165-.71.505-.78.929l-.15.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                      </svg>
                      Manage Connection
                    </Link>
                  </div>
                  
                  <div v-else class="text-center py-4">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 mb-3">
                      <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                      </svg>
                    </div>
                    <p class="text-sm text-gray-600 mb-4">
                      Connect to automatically import transactions and update balances
                    </p>
                    <Link 
                      :href="route('plaid.link', [budget.id, account.id])"
                      class="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 rounded-lg text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                      </svg>
                      Connect to Bank
                    </Link>
                  </div>
                </div>
              </div>
              
              <!-- Danger Zone Card -->
              <div class="bg-white rounded-xl shadow-sm border border-red-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-red-100 bg-gradient-to-r from-red-50 to-white">
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-100">
                      <svg class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                      </svg>
                    </div>
                    <div>
                      <h3 class="text-base font-semibold text-red-900">Danger Zone</h3>
                    </div>
                  </div>
                </div>
                
                <div class="p-6">
                  <p class="text-sm text-gray-600 mb-4">
                    Permanently delete this account. This cannot be undone.
                  </p>
                  <button 
                    type="button" 
                    @click="confirmAccountDeletion" 
                    class="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-white border border-red-300 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50 transition-colors"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                    Delete Account
                  </button>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Sticky Action Bar -->
          <div class="mt-8 flex items-center justify-between gap-4 bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-4">
            <Link
              :href="route('budgets.show', budget.id)"
              class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
              </svg>
              Back to Budget
            </Link>
            
            <PrimaryButton 
              class="inline-flex items-center gap-2 px-6 py-2.5"
              :class="{ 'opacity-25': form.processing }" 
              :disabled="form.processing"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
              </svg>
              Save Changes
            </PrimaryButton>
          </div>
        </form>
      </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <Modal :show="confirmingDeletion" @close="closeModal">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900">
          Are you sure you want to delete this account?
        </h2>

        <p class="mt-1 text-sm text-gray-600">
          Deleting this account is only possible if it has no transactions. If you proceed, the account will be permanently removed from your budget.
        </p>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="closeModal">
            Cancel
          </SecondaryButton>

          <DangerButton
            class="ml-3"
            :class="{ 'opacity-25': deleting }"
            :disabled="deleting"
            @click="deleteAccount"
          >
            Delete Account
          </DangerButton>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Modal from '@/Components/Modal.vue';
import PlaidSyncTimestamp from '@/Components/PlaidSyncTimestamp.vue';
import InstitutionLogo from '@/Components/InstitutionLogo.vue';

// Define props to receive the budget and account
const props = defineProps({
  budget: Object,
  account: Object
});

// Check if this account is connected to Plaid
const isPlaidConnected = computed(() => !!props.account.plaid_account);

// Check if this account type is a liability (credit card, loan, etc.)
const liabilityTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan', 'auto loan', 'student loan', 'home equity', 'business loan'];
const isLiabilityType = computed(() => liabilityTypes.includes(props.account.type?.toLowerCase()));

// Helper function to get display name for account type
const getAccountTypeDisplayName = (type) => {
  const typeMap = {
    'checking': 'Checking',
    'savings': 'Savings',
    'money market': 'Money Market',
    'cd': 'Certificate of Deposit',
    'certificate of deposit': 'Certificate of Deposit',
    'investment': 'Investment',
    'credit card': 'Credit Card',
    'credit': 'Credit',
    'loan': 'Loan',
    'line of credit': 'Line of Credit',
    'mortgage': 'Mortgage',
    'auto loan': 'Auto Loan',
    'student loan': 'Student Loan',
    'home equity': 'Home Equity',
    'business loan': 'Business Loan',
    'brokerage': 'Brokerage',
    'mutual fund': 'Mutual Fund',
    '401k': '401(k)',
    '403b': '403(b)',
    '457b': '457(b)',
    'traditional ira': 'Traditional IRA',
    'roth ira': 'Roth IRA',
    'health savings account': 'Health Savings Account',
    'hsa': 'Health Savings Account',
    'paypal': 'PayPal',
    'prepaid': 'Prepaid',
    'other': 'Other'
  };
  return typeMap[type?.toLowerCase()] || (type ? type.charAt(0).toUpperCase() + type.slice(1) : 'Unknown');
};

// Set up account status based on include_in_budget
const accountStatus = ref(props.account.include_in_budget ? 'active' : 'excluded');

// Initialize form with account values
const form = useForm({
  name: props.account.name,
  type: props.account.type,
  include_in_budget: props.account.include_in_budget,
  custom_logo: props.account.custom_logo || null,
  logo_url: props.account.logo_url || null,
});

// Logo upload state
const logoInput = ref(null);
const logoUploading = ref(false);
const fetchingLogo = ref(false);

// Computed properties for logo display
const hasCustomLogo = computed(() => {
  return form.custom_logo || props.account.custom_logo || form.logo_url || props.account.logo_url;
});

const hasLogo = computed(() => {
  return hasCustomLogo.value || (isPlaidConnected.value && props.account.plaid_account?.plaid_connection?.institution_logo);
});

const logoSourceLabel = computed(() => {
  if (form.custom_logo || props.account.custom_logo) return 'Custom Logo';
  if (form.logo_url || props.account.logo_url) return 'Fetched Logo';
  return 'Institution Logo';
});

const logoSourceDescription = computed(() => {
  if (form.custom_logo || props.account.custom_logo) return 'Your uploaded logo';
  if (form.logo_url || props.account.logo_url) return 'Automatically fetched';
  return 'From ' + (props.account.plaid_account?.plaid_connection?.institution_name || 'bank');
});

// Handle logo file upload
const handleLogoUpload = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  
  // Validate file type
  if (!file.type.startsWith('image/')) {
    alert('Please upload an image file');
    return;
  }
  
  // Validate file size (max 2MB)
  if (file.size > 2 * 1024 * 1024) {
    alert('Image must be less than 2MB');
    return;
  }
  
  logoUploading.value = true;
  
  try {
    // Convert to base64
    const reader = new FileReader();
    reader.onload = (e) => {
      form.custom_logo = e.target.result;
      logoUploading.value = false;
    };
    reader.onerror = () => {
      alert('Failed to read file');
      logoUploading.value = false;
    };
    reader.readAsDataURL(file);
  } catch (error) {
    console.error('Error uploading logo:', error);
    alert('Failed to upload logo');
    logoUploading.value = false;
  }
};

// Fetch logo from external provider
const fetchLogo = () => {
  fetchingLogo.value = true;
  
  router.post(route('accounts.fetchLogo', [props.budget.id, props.account.id]), {}, {
    preserveScroll: true,
    preserveState: false, // Force full page refresh to get updated account data
    onFinish: () => {
      fetchingLogo.value = false;
    },
  });
};

// Clear all custom logos (both uploaded and fetched)
const clearLogo = () => {
  router.delete(route('accounts.clearLogo', [props.budget.id, props.account.id]), {
    preserveScroll: true,
    preserveState: false, // Force full page refresh to get updated account data
    onFinish: () => {
      if (logoInput.value) {
        logoInput.value.value = '';
      }
    },
  });
};

// Watch for changes to account status and update include_in_budget
watch(accountStatus, (newValue) => {
  form.include_in_budget = newValue === 'active';
});

// Submit form handler
const submit = () => {
  form.put(route('budgets.accounts.update', [props.budget.id, props.account.id]), {
    preserveScroll: true,
  });
};

// Delete account functionality
const confirmingDeletion = ref(false);
const deleting = ref(false);

const confirmAccountDeletion = () => {
  confirmingDeletion.value = true;
};

const closeModal = () => {
  confirmingDeletion.value = false;
};

const deleteAccount = () => {
  deleting.value = true;

  form.delete(route('budgets.accounts.destroy', [props.budget.id, props.account.id]), {
    onFinish: () => {
      deleting.value = false;
      closeModal();
    },
  });
};
</script> 