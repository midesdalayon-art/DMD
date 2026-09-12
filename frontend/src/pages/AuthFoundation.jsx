import { Link } from 'react-router-dom'
import SectionHeading from '../components/SectionHeading'

const pageContent = {
  register: {
    eyebrow: 'Register',
    title: 'Create an account',
    body: 'Registration is prepared as a route foundation. The full account creation workflow will be implemented separately.',
  },
  forgotPassword: {
    eyebrow: 'Password Recovery',
    title: 'Forgot password',
    body: 'Password recovery is prepared as a route foundation. Backend password reset delivery is not implemented yet.',
  },
}

function AuthFoundation({ mode }) {
  const content = pageContent[mode]

  return (
    <section className="site-shell page-section narrow-page">
      <SectionHeading eyebrow={content.eyebrow} title={content.title}>
        {content.body}
      </SectionHeading>
      <div className="auth-placeholder">
        <Link className="outline-button" to="/login">
          Back to Sign In
        </Link>
        <Link className="text-link" to="/">
          Back to Home
        </Link>
      </div>
    </section>
  )
}

export default AuthFoundation
