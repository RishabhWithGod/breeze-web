import { create } from 'zustand'

interface UiState {
  /** Off-canvas sidebar visibility on mobile / tablet. */
  isSidebarOpen: boolean
  isSearchOpen: boolean
}

interface UiActions {
  openSidebar: () => void
  closeSidebar: () => void
  toggleSidebar: () => void
  setSearchOpen: (isOpen: boolean) => void
}

export const useUiStore = create<UiState & UiActions>()((set) => ({
  isSidebarOpen: false,
  isSearchOpen: false,

  openSidebar: () => set({ isSidebarOpen: true }),
  closeSidebar: () => set({ isSidebarOpen: false }),
  toggleSidebar: () => set((state) => ({ isSidebarOpen: !state.isSidebarOpen })),
  setSearchOpen: (isSearchOpen) => set({ isSearchOpen }),
}))
