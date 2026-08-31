import { create } from 'zustand'

interface UiState {
  /** Off-canvas sidebar visibility on mobile / tablet. */
  isSidebarOpen: boolean
}

interface UiActions {
  openSidebar: () => void
  closeSidebar: () => void
  toggleSidebar: () => void
}

export const useUiStore = create<UiState & UiActions>()((set) => ({
  isSidebarOpen: false,

  openSidebar: () => set({ isSidebarOpen: true }),
  closeSidebar: () => set({ isSidebarOpen: false }),
  toggleSidebar: () => set((state) => ({ isSidebarOpen: !state.isSidebarOpen })),
}))
