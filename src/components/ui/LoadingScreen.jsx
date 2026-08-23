import { Printer } from 'lucide-react'

export function LoadingScreen({ label }) {
  return <main className="loading-screen"><div className="loading-mark"><Printer size={26} /></div><div className="loading-bar"><span /></div><p>{label}</p></main>
}
