// Apply the saved theme before first paint to avoid a flash (external file so the CSP can forbid inline scripts).
try {
  var t = localStorage.getItem('pc-theme')
  if (t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark')
} catch (e) {}
