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
  signup: '/signup',
  forgotPassword: '/forgot-password',

  // Protected
  home: '/home',
  /** AI Takeoff module landing screen — the takeoff history. */
  aiTakeoff: '/ai-takeoff',
  /** Address suggestions for the Site / Location field. JSON, not a page. */
  addressLookup: '/address-lookup',
  /** The place behind a chosen suggestion — asked once, on selection. */
  addressPlace: '/address-lookup/place',
  /**
   * Semantic alias of `aiTakeoff`: history *is* the module landing screen.
   * Kept so "view history" links read clearly at their call sites.
   */
  history: '/ai-takeoff',
  /** Upload screen, reached from any "New Takeoff" action. */
  upload: '/ai-takeoff/upload',
  /** Projects module: every project on record. */
  projects: '/projects',
  /** Where a project and its drawing PDFs are defined. */
  projectCreate: '/projects/create',
  estimates: '/estimates',
  estimateCreate: '/estimates/create',
  jobs: '/jobs',
  /** Under Jobs in the rail: work and the people who run it, across every job. */
  clients: '/clients',
  clientCreate: '/clients/create',
  takeoffFlowForget: '/takeoff-flow',
  tasks: '/tasks',
  taskCreate: '/tasks/create',
  foremen: '/foremen',
  foremanCreate: '/foremen/create',
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

  /**
   * Time Tracking lands on the entries list (Time Log Viewer) — active timer,
   * filters, weekly summary and every logged entry, all on one screen.
   * Kept as its own key, distinct from `timeEntries`, since call sites read
   * it as "this module's home" rather than "the entries list specifically" —
   * the two currently share a value, the same alias pattern `history` uses
   * for `aiTakeoff`.
   */
  timeTracking: '/time-tracking/entries',
  timeTrackingWeek: '/time-tracking/week',
  timeTrackingReports: '/time-tracking/reports',
  timeTrackingSettings: '/time-tracking/settings',
  timeEntries: '/time-tracking/entries',

  /** Billing lands on the invoices list — its only real screen so far. */
  billing: '/invoices',
  invoices: '/invoices',
  invoiceCreate: '/invoices/create',

  /** Job Costing lands on the cross-job dashboard; each job's own detail screen is `routeTo.jobCosting`. */
  jobCosting: '/job-costing',

  documents: '/documents',
  documentsCreate: '/documents/create',

  notifications: '/notifications',
  notificationsReadAll: '/notifications/read-all',

  profile: '/profile',
  settings: '/settings',
  security: '/security',
  twoFactorChallenge: '/two-factor-challenge',
  twoFactorChallengeResend: '/two-factor-challenge/resend',
  breezeBucks: '/breeze-bucks',
  breezeBucksRewards: '/breeze-bucks/rewards',
  breezeBucksHistory: '/breeze-bucks/history',
  breezeBucksAwardForm: '/breeze-bucks/award',
} as const

/** Per-record URLs. */
export const routeTo = {
  /** Adds a site to a client from whichever screen needed it. */
  clientAddresses: (clientId: number) => `/clients/${clientId}/addresses`,
  clientAddress: (clientId: number, addressId: number) =>
    `/clients/${clientId}/addresses/${addressId}`,
  /** The step after Create Job: laying the job out in tasks. */
  jobTaskSetup: (jobId: number) => `/jobs/${jobId}/tasks/setup`,
  /*
   * The same step, opened to add work to a job already running. `from` is what
   * Back and the save redirect read — the server turns it into a real URL, so
   * only these two markers exist.
   */
  jobTaskSetupFromList: (jobId: number) => `/jobs/${jobId}/tasks/setup?from=tasks`,
  jobTaskSetupFromJob: (jobId: number) => `/jobs/${jobId}/tasks/setup?from=job`,
  project: (projectId: number) => `/projects/${projectId}`,
  /** AI Takeoff upload, opened with this client already picked. */
  uploadForProject: (projectId: number) => `/ai-takeoff/upload?project=${projectId}`,
  /** Starts an AI takeoff run against the project's drawing already on file. */
  projectTakeoffStart: (projectId: number) => `/projects/${projectId}/takeoff`,
  /** One of a client's drawing PDFs — opened, or removed. */
  projectDocument: (projectId: number, documentId: number) =>
    `/projects/${projectId}/documents/${documentId}`,
  /** Makes this the drawing the next takeoff runs against. */
  projectDocumentSelect: (projectId: number, documentId: number) =>
    `/projects/${projectId}/documents/${documentId}/select`,

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

  jobSchedule: (jobId: number) => `/jobs/${jobId}/schedule`,
  jobTasksStore: (jobId: number) => `/jobs/${jobId}/schedule/tasks`,
  jobTasksReorder: (jobId: number) => `/jobs/${jobId}/schedule/reorder`,
  scheduleTask: (taskId: number) => `/schedule-tasks/${taskId}`,
  /** The setup screen aimed at one task, with where Back should return to. */
  /** One takeoff's own paperwork, and the form that adds to it. */
  projectDocuments: (projectId: number) => `/documents?project=${projectId}`,
  projectDocumentCreate: (projectId: number) => `/documents/create?project=${projectId}`,
  client: (clientId: number) => `/clients/${clientId}`,
  clientEdit: (clientId: number) => `/clients/${clientId}/edit`,
  /** Create Project, opened with this client already picked. */
  projectCreateForClient: (clientId: number) => `/projects/create?client=${clientId}`,
  foreman: (foremanId: number) => `/foremen/${foremanId}`,
  foremanEdit: (foremanId: number) => `/foremen/${foremanId}/edit`,
  taskEdit: (taskId: number) => `/tasks/${taskId}/edit?from=tasks`,
  taskEditFromJob: (taskId: number) => `/tasks/${taskId}/edit?from=job`,
  /** Removing a task hands its estimate lines back to be planned again. */
  taskRemove: (taskId: number) => `/tasks/${taskId}`,
  taskRemoveFromJob: (taskId: number) => `/tasks/${taskId}?from=job`,
  scheduleTaskComplete: (taskId: number) => `/schedule-tasks/${taskId}/complete`,
  scheduleTaskDelay: (taskId: number) => `/schedule-tasks/${taskId}/delay`,
  scheduleTaskMove: (taskId: number) => `/schedule-tasks/${taskId}/move`,
  scheduleTaskAssign: (taskId: number) => `/schedule-tasks/${taskId}/assignments`,
  scheduleTaskUnassign: (taskId: number, assignmentId: number) =>
    `/schedule-tasks/${taskId}/assignments/${assignmentId}`,
  scheduleTaskDependencyStore: (taskId: number) => `/schedule-tasks/${taskId}/dependencies`,
  scheduleTaskDependencyDestroy: (taskId: number, dependencyId: number) =>
    `/schedule-tasks/${taskId}/dependencies/${dependencyId}`,
  scheduleTaskComment: (taskId: number) => `/schedule-tasks/${taskId}/comments`,
  estimate: (estimateId: number) => `/estimates/${estimateId}`,
  /*
   * The same estimate, opened as a step of the takeoff rather than on its own:
   * `flow` is what draws the roadmap and the button on to Create Job. Every
   * link that is part of the flow has to carry it, or the flow ends there.
   */
  estimateInFlow: (estimateId: number) => `/estimates/${estimateId}?flow=1`,
  estimateRestore: (estimateId: number) => `/estimates/${estimateId}/restore`,
  estimateEdit: (estimateId: number) => `/estimates/${estimateId}/edit`,
  /*
   * Opened from a job's own list. `from_job` is what lets Back come back here
   * rather than dropping into the estimates index — the server checks the job
   * really owns the estimate before believing it.
   */
  estimateFromJob: (estimateId: number, jobId: number) =>
    `/estimates/${estimateId}?from_job=${jobId}`,
  estimateEditFromJob: (estimateId: number, jobId: number) =>
    `/estimates/${estimateId}/edit?from_job=${jobId}`,
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
  symbolOccurrence: (resultId: number, reviewId: number, key: string) =>
    `/reviews/${resultId}/symbols/${reviewId}/occurrences/${key}`,
  symbolOccurrenceMove: (resultId: number, reviewId: number, key: string) =>
    `/reviews/${resultId}/symbols/${reviewId}/occurrences/${key}/move`,
  symbolOccurrenceDuplicate: (resultId: number, reviewId: number, key: string) =>
    `/reviews/${resultId}/symbols/${reviewId}/occurrences/${key}/duplicate`,
  symbolOccurrenceDelete: (resultId: number, reviewId: number, key: string) =>
    `/reviews/${resultId}/symbols/${reviewId}/occurrences/${key}`,
  symbolManualAdd: (resultId: number) => `/reviews/${resultId}/symbols/manual`,
  reviewUndo: (resultId: number) => `/reviews/${resultId}/undo`,

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

  // Time Tracking
  /** Create is its own route (`entries/create`); this is the show/update/destroy URL. */
  timeEntryCreate: () => `/time-tracking/entries/create`,
  timeEntry: (entryId: number) => `/time-tracking/entries/${entryId}`,
  timeEntryEdit: (entryId: number) => `/time-tracking/entries/${entryId}/edit`,
  timeEntrySubmit: (entryId: number) => `/time-tracking/entries/${entryId}/submit`,
  timeEntryApprove: (entryId: number) => `/time-tracking/entries/${entryId}/approve`,
  timeEntryReject: (entryId: number) => `/time-tracking/entries/${entryId}/reject`,
  timeEntryReopen: (entryId: number) => `/time-tracking/entries/${entryId}/reopen`,
  timeEntriesExport: (format: 'csv' | 'xlsx') => `/time-tracking/entries/export/${format}`,
  jobTimeEntryTasks: (jobId: number) => `/time-tracking/jobs/${jobId}/tasks`,
  timeTrackingReportsExport: (format: 'csv' | 'xlsx') =>
    `/time-tracking/reports/export/${format}`,

  timerStart: '/time-tracking/timer/start',
  timerPause: '/time-tracking/timer/pause',
  timerResume: '/time-tracking/timer/resume',
  timerStop: '/time-tracking/timer/stop',
  timerDiscard: '/time-tracking/timer/discard',

  // Billing
  /** The show/update/destroy URL. */
  invoice: (invoiceId: number) => `/invoices/${invoiceId}`,
  invoiceEdit: (invoiceId: number) => `/invoices/${invoiceId}/edit`,
  invoiceRestore: (invoiceId: number) => `/invoices/${invoiceId}/restore`,
  invoiceItems: (invoiceId: number) => `/invoices/${invoiceId}/items`,
  invoiceItem: (invoiceId: number, itemId: number) => `/invoices/${invoiceId}/items/${itemId}`,
  invoiceSend: (invoiceId: number) => `/invoices/${invoiceId}/send`,
  invoiceMarkPaid: (invoiceId: number) => `/invoices/${invoiceId}/mark-paid`,
  invoicePdf: (invoiceId: number) => `/invoices/${invoiceId}/pdf`,
  invoicePay: (invoiceId: number) => `/invoices/${invoiceId}/pay`,

  // Job Costing
  jobCostingExport: (format: 'csv' | 'xlsx') => `/job-costing/export/${format}`,
  jobCosting: (jobId: number) => `/jobs/${jobId}/costing`,
  jobCostEntries: (jobId: number) => `/jobs/${jobId}/costing/entries`,
  jobCostEntry: (jobId: number, entryId: number) => `/jobs/${jobId}/costing/entries/${entryId}`,

  // Documents
  document: (documentId: number) => `/documents/${documentId}`,
  documentPreview: (documentId: number) => `/documents/${documentId}/preview`,
  documentDownload: (documentId: number) => `/documents/${documentId}/download`,
  documentHistory: (documentId: number) => `/documents/${documentId}/history`,
  documentVersions: (documentId: number) => `/documents/${documentId}/versions`,
  documentFavorite: (documentId: number) => `/documents/${documentId}/favorite`,
  documentArchive: (documentId: number) => `/documents/${documentId}/archive`,
  documentRestore: (documentId: number) => `/documents/${documentId}/restore`,
  documentShare: (documentId: number) => `/documents/${documentId}/share`,
  documentFoldersStore: '/document-folders',

  // Notifications
  notificationRead: (notificationId: number) => `/notifications/${notificationId}/read`,

  // Payment Settings
  paymentProcessorConnect: (processorId: number) => `/settings/payment/processors/${processorId}/connect`,
  paymentProcessorTest: (processorId: number) => `/settings/payment/processors/${processorId}/test`,
  paymentProcessorDisconnect: (processorId: number) => `/settings/payment/processors/${processorId}`,
  paymentMethodsStore: '/settings/payment/methods',
  paymentMethodDefault: (methodId: number) => `/settings/payment/methods/${methodId}/default`,
  paymentMethodDestroy: (methodId: number) => `/settings/payment/methods/${methodId}`,
  billingSettingsUpdate: '/settings/payment/billing',

  securityTwoFactorChallenge: '/security/2fa/challenge',
  securityTwoFactorConfirm: '/security/2fa/confirm',
  securityTwoFactorDisable: '/security/2fa/disable',
  securityRecoveryCodes: '/security/2fa/recovery-codes',
  securityMethodUpdate: '/security/method',
  securityEmailChallenge: '/security/email/challenge',
  securityEmailConfirm: '/security/email/confirm',
  securityPhoneChallenge: '/security/phone/challenge',
  securityPhoneConfirm: '/security/phone/confirm',
  securityPasswordUpdate: '/security/password',
  securityNotificationUpdate: (eventType: string) => `/security/notifications/${eventType}`,

  breezeBucksRedeem: (rewardId: number) => `/breeze-bucks/rewards/${rewardId}/redeem`,
  breezeBucksRewardsStore: '/breeze-bucks/rewards',
  breezeBucksRewardUpdate: (rewardId: number) => `/breeze-bucks/rewards/${rewardId}`,
  breezeBucksRewardDestroy: (rewardId: number) => `/breeze-bucks/rewards/${rewardId}`,
  breezeBucksAward: '/breeze-bucks/award',
  breezeBucksAdjust: '/breeze-bucks/adjust',
} as const
