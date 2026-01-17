<template>
  <div 
    class="flex items-center justify-center rounded-full overflow-hidden flex-shrink-0"
    :class="sizeClasses"
  >
    <!-- Primary: Custom logo (if set) or Base64 logo from Plaid -->
    <img 
      v-if="logoSrc && !imageError" 
      :src="logoSrc" 
      :alt="name || 'Institution logo'"
      class="w-full h-full object-contain"
      @error="handlePrimaryError"
    />
    
    <!-- Fallback: Google S2 favicon -->
    <img 
      v-else-if="fallbackUrl && !fallbackError"
      :src="fallbackUrl"
      :alt="name || 'Institution logo'"
      class="w-full h-full object-contain bg-white"
      @error="handleFallbackError"
    />
    
    <!-- Final fallback: Colored initials circle -->
    <div 
      v-else
      class="w-full h-full flex items-center justify-center text-white font-semibold"
      :class="[fallbackBgClass, fallbackTextClass]"
    >
      {{ initials }}
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  // Custom logo (takes priority) - base64 or URL
  customLogo: {
    type: String,
    default: null
  },
  // External logo URL (second priority) - from Clearbit, Google, etc.
  logoUrl: {
    type: String,
    default: null
  },
  // Base64-encoded logo string from Plaid (without data URI prefix)
  logo: {
    type: String,
    default: null
  },
  // Institution name for fallback initials and Clearbit lookup
  name: {
    type: String,
    default: 'Bank'
  },
  // Size: 'xs', 'sm', 'md', 'lg', 'xl'
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['xs', 'sm', 'md', 'lg', 'xl'].includes(value)
  }
});

const imageError = ref(false);
const fallbackError = ref(false);

// Known institution name to domain mappings for Google S2 fallback
const institutionDomains = {
  'chase': 'chase.com',
  'bank of america': 'bankofamerica.com',
  'wells fargo': 'wellsfargo.com',
  'citibank': 'citi.com',
  'citi': 'citi.com',
  'capital one': 'capitalone.com',
  'us bank': 'usbank.com',
  'pnc': 'pnc.com',
  'truist': 'truist.com',
  'td bank': 'td.com',
  'fifth third': '53.com',
  'regions': 'regions.com',
  'regions bank': 'regions.com',
  'citizens': 'citizensbank.com',
  'huntington': 'huntington.com',
  'keybank': 'key.com',
  'ally': 'ally.com',
  'discover': 'discover.com',
  'synchrony': 'synchrony.com',
  'american express': 'americanexpress.com',
  'amex': 'americanexpress.com',
  'usaa': 'usaa.com',
  'navy federal': 'navyfederal.org',
  'schwab': 'schwab.com',
  'charles schwab': 'schwab.com',
  'fidelity': 'fidelity.com',
  'vanguard': 'vanguard.com',
  'e*trade': 'etrade.com',
  'etrade': 'etrade.com',
  'robinhood': 'robinhood.com',
  'paypal': 'paypal.com',
  'venmo': 'venmo.com',
  'chime': 'chime.com',
  'sofi': 'sofi.com',
  'marcus': 'marcus.com',
  'goldman sachs': 'goldmansachs.com',
  'home depot': 'homedepot.com',
  'lowes': 'lowes.com',
  'target': 'target.com',
  'walmart': 'walmart.com',
  'amazon': 'amazon.com',
  'apple': 'apple.com',
  'coinbase': 'coinbase.com',
};

// Convert base64 to data URI for img src
// Priority: customLogo > logoUrl > Plaid logo > fallbacks
const logoSrc = computed(() => {
  if (imageError.value) return null;
  
  // 1. Check for custom logo first (highest priority - uploaded base64)
  if (props.customLogo) {
    // If it's already a full URL or data URI, use as-is
    if (props.customLogo.startsWith('http') || props.customLogo.startsWith('data:')) {
      return props.customLogo;
    }
    // Otherwise assume it's base64
    return `data:image/png;base64,${props.customLogo}`;
  }
  
  // 2. Check for external logo URL (fetched from Clearbit, etc.)
  if (props.logoUrl) {
    return props.logoUrl;
  }
  
  // 3. Fall back to Plaid logo
  if (!props.logo) return null;
  
  // If it already has a data URI prefix, use as-is
  if (props.logo.startsWith('data:')) {
    return props.logo;
  }
  
  // Plaid provides base64 without prefix, typically PNG
  return `data:image/png;base64,${props.logo}`;
});

// Get fallback logo URL based on institution name
// Uses Google's S2 favicon service as fallback (highly reliable)
const fallbackUrl = computed(() => {
  if (!props.name) return null;
  
  const nameLower = props.name.toLowerCase();
  
  // Check for exact or partial match in our domain mapping
  for (const [key, domain] of Object.entries(institutionDomains)) {
    if (nameLower.includes(key)) {
      // Google's S2 favicon service - highly reliable, gives high-res icons
      const size = props.size === 'xl' ? 128 : props.size === 'lg' ? 64 : 32;
      return `https://www.google.com/s2/favicons?domain=${domain}&sz=${size}`;
    }
  }
  
  return null;
});

// Handle image load errors
const handlePrimaryError = () => {
  imageError.value = true;
};

const handleFallbackError = () => {
  fallbackError.value = true;
};

// Generate initials from name (first 2 letters of first 2 words, or first 2 letters)
const initials = computed(() => {
  if (!props.name) return '?';
  
  const words = props.name.trim().split(/\s+/);
  if (words.length >= 2) {
    return (words[0][0] + words[1][0]).toUpperCase();
  }
  return props.name.substring(0, 2).toUpperCase();
});

// Size classes for the container
const sizeClasses = computed(() => {
  const sizes = {
    'xs': 'w-6 h-6',
    'sm': 'w-8 h-8',
    'md': 'w-10 h-10',
    'lg': 'w-12 h-12',
    'xl': 'w-16 h-16'
  };
  return sizes[props.size];
});

// Text size classes for fallback initials
const fallbackTextClass = computed(() => {
  const textSizes = {
    'xs': 'text-[10px]',
    'sm': 'text-xs',
    'md': 'text-sm',
    'lg': 'text-base',
    'xl': 'text-lg'
  };
  return textSizes[props.size];
});

// Generate a consistent color based on institution name
const fallbackBgClass = computed(() => {
  const colors = [
    'bg-blue-500',
    'bg-emerald-500',
    'bg-violet-500',
    'bg-amber-500',
    'bg-rose-500',
    'bg-cyan-500',
    'bg-indigo-500',
    'bg-teal-500',
    'bg-orange-500',
    'bg-pink-500'
  ];
  
  // Simple hash of the name to get consistent color
  let hash = 0;
  const name = props.name || '';
  for (let i = 0; i < name.length; i++) {
    hash = ((hash << 5) - hash) + name.charCodeAt(i);
    hash = hash & hash;
  }
  
  return colors[Math.abs(hash) % colors.length];
});
</script>
