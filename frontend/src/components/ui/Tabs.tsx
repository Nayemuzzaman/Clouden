import { NavLink } from 'react-router'
import { classNames } from '../../lib/format'

export function RouteTabs({ tabs }: { tabs: { to: string; label: string; end?: boolean }[] }) {
  return (
    <nav className="-mb-px flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800" aria-label="Sections">
      {tabs.map((tab) => (
        <NavLink
          key={tab.to}
          to={tab.to}
          end={tab.end}
          className={({ isActive }) =>
            classNames(
              'border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
              isActive ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200',
            )
          }
        >
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}

export function Segmented<T extends string>({ value, onChange, options, label }: { value: T; onChange: (value: T) => void; options: { value: T; label: string }[]; label?: string }) {
  return (
    <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5 dark:border-zinc-800 dark:bg-zinc-900" role="radiogroup" aria-label={label}>
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={value === option.value}
          onClick={() => onChange(option.value)}
          className={classNames(
            'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
            value === option.value ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400',
          )}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}
