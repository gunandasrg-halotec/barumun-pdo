import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useLogin } from '@/hooks/useAuth'
import { getApiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { PasswordInput } from '@/components/ui/PasswordInput'

export function LoginPage() {
  const navigate  = useNavigate()
  const login     = useLogin()
  const [email, setEmail]       = useState('')
  const [password, setPassword] = useState('')
  const [error, setError]       = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    try {
      await login.mutateAsync({ email, password })
      navigate('/')
    } catch (err) {
      setError(getApiErrorMessage(err))
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-bg">
      <div className="card w-full max-w-sm">
        {/* Logo */}
        <div className="flex flex-col items-center mb-8">
          <div
            className="w-14 h-14 rounded-[16px] flex items-center justify-center text-white font-[900] text-lg mb-3"
            style={{ background: 'linear-gradient(135deg, #16a36d, #d4af37)' }}
          >
            PDO
          </div>
          <h1 className="text-[22px] font-[950] text-ink">Dana Operasional Kebun</h1>
          <p className="text-sm text-muted mt-1">PT Barumun Palma Nauli</p>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">Email</label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="input-base"
              placeholder="nama@barumunpalma.co.id"
            />
          </div>

          <PasswordInput
            label="Password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="••••••••"
          />

          {error && (
            <div className="text-sm text-red bg-[#fee2e2] px-3 py-2 rounded-btn">
              {error}
            </div>
          )}

          <Button type="submit" loading={login.isPending} className="w-full justify-center mt-2">
            Masuk
          </Button>
        </form>
      </div>
    </div>
  )
}
