import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

export type ThemePreference = 'light' | 'dark' | 'system'

const ThemeContext = createContext<{ preference: ThemePreference; setPreference: (p: ThemePreference) => void; resolved: 'light' | 'dark' } | null>(null)

function systemTheme(): 'light' | 'dark' {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function readPreference(): ThemePreference {
  try {
    const stored = localStorage.getItem('pc-theme')
    return stored === 'light' || stored === 'dark' ? stored : 'system'
  } catch {
    return 'system'
  }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, setPreferenceState] = useState<ThemePreference>(readPreference)
  const [system, setSystem] = useState<'light' | 'dark'>(systemTheme)
  const resolved = preference === 'system' ? system : preference

  useEffect(() => {
    const media = window.matchMedia?.('(prefers-color-scheme: dark)')
    const listener = () => setSystem(systemTheme())
    media?.addEventListener('change', listener)
    return () => media?.removeEventListener('change', listener)
  }, [])

  useEffect(() => {
    document.documentElement.classList.toggle('dark', resolved === 'dark')
  }, [resolved])

  const setPreference = (p: ThemePreference) => {
    setPreferenceState(p)
    try {
      localStorage.setItem('pc-theme', p)
    } catch {
      // storage unavailable (private mode): keep the in-memory preference
    }
  }

  return <ThemeContext.Provider value={{ preference, setPreference, resolved }}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used inside ThemeProvider')
  return ctx
}
