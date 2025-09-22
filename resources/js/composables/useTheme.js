import { ref, computed, watch, onMounted } from 'vue'

const isDark = ref(false)
const isInitialized = ref(false)

// Theme system composable
export function useTheme() {
  // Initialize theme from localStorage or system preference
  const initializeTheme = () => {
    if (typeof window === 'undefined') return

    const stored = localStorage.getItem('theme')
    
    if (stored) {
      // Use stored preference
      isDark.value = stored === 'dark'
    } else {
      // Use system preference
      isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches
    }
    
    applyTheme()
    isInitialized.value = true
  }

  // Apply theme to document
  const applyTheme = () => {
    if (typeof window === 'undefined') return

    const html = document.documentElement
    
    if (isDark.value) {
      html.classList.add('dark')
    } else {
      html.classList.remove('dark')
    }
  }

  // Toggle theme
  const toggleTheme = () => {
    isDark.value = !isDark.value
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
    applyTheme()
  }

  // Set specific theme
  const setTheme = (theme) => {
    isDark.value = theme === 'dark'
    localStorage.setItem('theme', theme)
    applyTheme()
  }

  // Computed properties
  const theme = computed(() => isDark.value ? 'dark' : 'light')
  const themeIcon = computed(() => isDark.value ? '☀️' : '🌙')
  const themeLabel = computed(() => isDark.value ? 'Switch to Light Mode' : 'Switch to Dark Mode')

  // Watch for system preference changes
  const watchSystemPreference = () => {
    if (typeof window === 'undefined') return

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    
    const handleChange = (e) => {
      // Only update if no manual preference is stored
      if (!localStorage.getItem('theme')) {
        isDark.value = e.matches
        applyTheme()
      }
    }

    mediaQuery.addEventListener('change', handleChange)
    
    // Return cleanup function
    return () => mediaQuery.removeEventListener('change', handleChange)
  }

  // Auto-initialize on mount
  onMounted(() => {
    initializeTheme()
    watchSystemPreference()
  })

  return {
    isDark,
    theme,
    themeIcon,
    themeLabel,
    isInitialized,
    toggleTheme,
    setTheme,
    initializeTheme
  }
}

// Global theme state (singleton pattern)
export const globalTheme = {
  isDark,
  isInitialized
}
