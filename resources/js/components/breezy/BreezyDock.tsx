import { Component, type ReactNode } from 'react'
import './breezy.css'
// @ts-expect-error - bundled module, no type declarations shipped
import Breezy from './breezy.js'

/** The robot is decoration plus help: if it ever fails it must not take the page with it. */
class Guard extends Component<{ children: ReactNode }, { failed: boolean }> {
  override state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  override componentDidCatch(error: unknown) {
    console.warn('Breezy stopped:', error)
  }
  override render() {
    return this.state.failed ? null : this.props.children
  }
}

export default function BreezyDock() {
  return (
    <Guard>
      <Breezy />
    </Guard>
  )
}
