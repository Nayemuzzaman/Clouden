import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useTheme } from '../hooks/theme'

export interface ChartPoint {
  t: string
  value: number | null
}

/** Small time-series area chart used for CPU, memory and disk history. */
export function MetricChart({ points, color = '#6366f1', format, max, height = 180, label }: {
  points: ChartPoint[]
  color?: string
  format: (value: number) => string
  max?: number
  height?: number
  label: string
}) {
  const { resolved } = useTheme()
  const grid = resolved === 'dark' ? '#27272a' : '#f4f4f5'
  const axis = resolved === 'dark' ? '#71717a' : '#a1a1aa'
  const id = `grad-${label.replace(/\W/g, '')}`

  if (points.length < 2) {
    return <div className="muted flex items-center justify-center text-sm" style={{ height }}>Collecting data… history appears after a few minutes.</div>
  }

  return (
    <div style={{ height }} role="img" aria-label={`${label} over time`}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
          <defs>
            <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={color} stopOpacity={0.25} />
              <stop offset="100%" stopColor={color} stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid stroke={grid} vertical={false} />
          <XAxis dataKey="t" tickFormatter={(t: string) => new Date(t).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })} stroke={axis} fontSize={11} tickLine={false} axisLine={false} minTickGap={40} />
          <YAxis domain={[0, max ?? 'auto']} tickFormatter={(v: number) => format(v)} stroke={axis} fontSize={11} tickLine={false} axisLine={false} width={64} />
          <Tooltip
            contentStyle={{ background: resolved === 'dark' ? '#18181b' : '#fff', border: `1px solid ${grid}`, borderRadius: 8, fontSize: 12 }}
            labelFormatter={(t) => new Date(String(t)).toLocaleString()}
            formatter={(v) => [format(Number(v)), label]}
          />
          <Area type="monotone" dataKey="value" stroke={color} strokeWidth={2} fill={`url(#${id})`} isAnimationActive={false} connectNulls />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}
