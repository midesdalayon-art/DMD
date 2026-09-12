import InlineLoader from './InlineLoader'

function LoadingState({ message = 'Loading...' }) {
  return <InlineLoader message={message} />
}

export default LoadingState
