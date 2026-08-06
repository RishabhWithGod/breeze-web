/**
 * Every URL the client links to, mirroring routes/web.php.
 *
 * Kept as plain paths rather than generated route helpers so navigation stays
 * readable at the call site; the paths are asserted by routes/web.php.
 */
export const ROUTES = {
  // Public
  login: '/login',
  logout: '/logout',
  forgotPassword: '/forgot-password',

  // Protected
  home: '/home',
  /** AI Takeoff module landing screen — the takeoff history. */
  aiTakeoff: '/ai-takeoff',
  /**
   * Semantic alias of `aiTakeoff`: history *is* the module landing screen.
   * Kept so "view history" links read clearly at their call sites.
   */
  history: '/ai-takeoff',
  /** Upload screen, reached from any "New Takeoff" action. */
  upload: '/ai-takeoff/upload',
  estimates: '/estimates',
  estimateCreate: '/estimates/create',
  jobs: '/jobs',
  jobCreate: '/jobs/create',
  /** Most recently reviewed takeoff. */
  results: '/results',
  empty: '/empty',
  error: '/error',

  /**
   * Scheduling lands on the queue of work still to be booked; the calendar is
   * reached from it.
   */
  scheduling: '/scheduling',
  schedulingCalendar: '/scheduling/calendar',
  schedulingAvailability: '/scheduling/availability',
  /** Where a booking is created, updated or removed. */
  schedules: '/scheduling/schedules',

  // Drawer modules awaiting implementation — served by ModuleController.
  timeTracking: '/time-tracking',
  billing: '/billing',
  jobCosting: '/job-costing',
  documents: '/documents',
  notifications: '/notifications',
  settings: '/settings',
  security: '/security',
  breezeBucks: '/breeze-bucks',
} as const

/** Per-record URLs. */
export const routeTo = {
  processing: (projectId: number) => `/processing/${projectId}`,
  processingCancel: (projectId: number) => `/processing/${projectId}/cancel`,
  processingRetry: (projectId: number) => `/processing/${projectId}/retry`,
  processingRestart: (projectId: number) => `/processing/${projectId}/restart`,
  results: (projectId: number) => `/results/${projectId}`,
  takeoff: (projectId: number) => `/takeoffs/${projectId}`,
  takeoffRestore: (projectId: number) => `/takeoffs/${projectId}/restore`,
  job: (jobId: number) => `/jobs/${jobId}`,
  jobEdit: (jobId: number) => `/jobs/${jobId}/edit`,
  jobRestore: (jobId: number) => `/jobs/${jobId}/restore`,
  jobArchive: (jobId: number) => `/jobs/${jobId}/archive`,
  jobUnarchive: (jobId: number) => `/jobs/${jobId}/unarchive`,
  jobDuplicate: (jobId: number) => `/jobs/${jobId}/duplicate`,
  jobStatus: (jobId: number) => `/jobs/${jobId}/status`,
  jobsBulk: '/jobs/bulk',
  jobTeam: (jobId: number) => `/jobs/${jobId}/team`,
  jobTeamMember: (jobId: number, memberId: number) => `/jobs/${jobId}/team/${memberId}`,
  jobNotes: (jobId: number) => `/jobs/${jobId}/notes`,
  jobNote: (jobId: number, noteId: number) => `/jobs/${jobId}/notes/${noteId}`,
  jobAttachments: (jobId: number) => `/jobs/${jobId}/attachments`,
  jobAttachment: (jobId: number, attachmentId: number) =>
    `/jobs/${jobId}/attachments/${attachmentId}`,
  jobEstimates: (jobId: number) => `/jobs/${jobId}/estimates`,
  jobEstimateConvert: (jobId: number, estimateId: number) =>
    `/jobs/${jobId}/estimates/${estimateId}/convert`,
  estimate: (estimateId: number) => `/estimates/${estimateId}`,
  estimateRestore: (estimateId: number) => `/estimates/${estimateId}/restore`,
  estimateEdit: (estimateId: number) => `/estimates/${estimateId}/edit`,
  estimateItems: (estimateId: number) => `/estimates/${estimateId}/items`,
  estimateItem: (estimateId: number, itemId: number) =>
    `/estimates/${estimateId}/items/${itemId}`,
  estimatePdf: (estimateId: number) => `/estimates/${estimateId}/pdf`,
  estimateCsv: (estimateId: number) => `/estimates/${estimateId}/export/csv`,

  jobAssignments: (jobId: number) => `/jobs/${jobId}/assignments`,
  jobAssignment: (jobId: number, assignmentId: number) =>
    `/jobs/${jobId}/assignments/${assignmentId}`,

  /** Live state of a run, polled by the processing screen. */
  processingStatus: (projectId: number) => `/processing/${projectId}/status`,

  // AI Review
  review: (resultId: number) => `/reviews/${resultId}`,
  reviewOriginalJson: (resultId: number) => `/reviews/${resultId}/original.json`,
  reviewFinalise: (resultId: number) => `/reviews/${resultId}/finalise`,
  reviewReopen: (resultId: number) => `/reviews/${resultId}/reopen`,
  reviewMerge: (resultId: number) => `/reviews/${resultId}/merge`,
  reviewBulk: (resultId: number) => `/reviews/${resultId}/bulk`,
  reviewApproveRemaining: (resultId: number) => `/reviews/${resultId}/approve-remaining`,
  reviewPage: (resultId: number, page: number) => `/reviews/${resultId}/pages/${page}`,
  symbolApprove: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/approve`,
  symbolReject: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/reject`,
  symbolReset: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/reset`,
  symbolCount: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/count`,
  symbolRename: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/rename`,
  symbolNote: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/note`,
  symbolSplit: (resultId: number, reviewId: number) =>
    `/reviews/${resultId}/symbols/${reviewId}/split`,

  /** The drawing itself: the PDF plus everything the engine read off it. */
  drawingDetails: (projectId: number) => `/takeoffs/${projectId}/pdf`,
  drawingFile: (projectId: number) => `/takeoffs/${projectId}/pdf/file`,

  // Signed-off takeoff
  finalSymbols: (resultId: number) => `/takeoffs/${resultId}/final`,
  finalExport: (resultId: number, format: 'json' | 'csv' | 'xlsx') =>
    `/takeoffs/${resultId}/final/export/${format}`,
  finalAnnotatedPdf: (resultId: number) => `/takeoffs/${resultId}/annotated.pdf`,
  finalCreateJob: (resultId: number) => `/takeoffs/${resultId}/job`,
  finalCreateEstimate: (resultId: number) => `/takeoffs/${resultId}/estimate`,
} as const
