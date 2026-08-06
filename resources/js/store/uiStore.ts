import { create } from 'zustand'

interface UiState {
  /** Off-canvas sidebar visibility on mobile / tablet. */
  isSidebarOpen: boolean
  /** Collapsed rail mode on desktop. */
  isSidebarCollapsed: boolean
  isSearchOpen: boolean
}

interface UiActions {
  openSidebar: () => void
  closeSidebar: () => void
  toggleSidebar: () => void
  toggleSidebarCollapsed: () => void
  setSearchOpen: (isOpen: boolean) => void
}

export const useUiStore = create<UiState & UiActions>()((set) => ({
  isSidebarOpen: false,
  isSidebarCollapsed: false,
  isSearchOpen: false,

  openSidebar: () => set({ isSidebarOpen: true }),
  closeSidebar: () => set({ isSidebarOpen: false }),
  toggleSidebar: () => set((state) => ({ isSidebarOpen: !state.isSidebarOpen })),
  toggleSidebarCollapsed: () =>
    set((state) => ({ isSidebarCollapsed: !state.isSidebarCollapsed })),
  setSearchOpen: (isSearchOpen) => set({ isSearchOpen }),
}))
