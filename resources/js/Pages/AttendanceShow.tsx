import { Head } from '@inertiajs/react'
import { Camera, MapPin } from 'lucide-react'
import { Badge, ButtonLink, Card, SectionHeading } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { AttendanceDetail, AttendanceEventDetail } from '@/types'
import { formatDate, formatHours } from '@/utils'

export interface AttendanceShowProps {
  attendance: AttendanceDetail
}

const METHOD_LABEL: Record<NonNullable<AttendanceEventDetail['method']>, string> = {
  manual: 'Manual',
  automatic: 'GPS match',
  photo: 'Photo',
}

const formatTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—'

const formatMeters = (value: number | null) =>
  value === null ? '—' : value >= 1000 ? `${(value / 1000).toFixed(1)} km` : `${Math.round(value)} m`

const formatCoords = (lat: number | null, lng: number | null) =>
  lat === null || lng === null ? '—' : `${lat.toFixed(5)}, ${lng.toFixed(5)}`

/**
 * Attendance detail — a single GPS check-in/check-out, in full. Distinct
 * from a Time Entry: no task, no approval workflow, just where and when a
 * technician was on site.
 */
export default function AttendanceShow({ attendance }: AttendanceShowProps) {
  return (
    <PageTransition>
      <Head title={`Check-In #${attendance.id}`} />

      <PageHeader
        title={`Check-In #${attendance.id}`}
        subtitle={`${attendance.employee.name}${attendance.job ? ` · ${attendance.job.name}` : ''}`}
        breadcrumbs={[
          { label: 'Time Tracking', href: ROUTES.timeTracking },
          { label: `#${attendance.id}` },
        ]}
        actions={
          <ButtonLink href={ROUTES.timeEntries} variant="secondary" size="sm">
            Back to Entries
          </ButtonLink>
        }
      />

      <div className="mb-6 flex flex-wrap items-center gap-3">
        {attendance.status === 'checkedIn' ? (
          <Badge tone="success">On site</Badge>
        ) : (
          <Badge tone="neutral">Checked out</Badge>
        )}
        <span className="text-sm text-white/70">
          {formatDate(attendance.date)} · {formatHours(attendance.hours)} on site
        </span>
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="brand" padding="lg">
          <SectionHeading as="h3" title="Employee & Job" />
          <dl className="grid gap-4 sm:grid-cols-2">
            <Field label="Employee" value={attendance.employee.name} />
            <Field label="Date" value={formatDate(attendance.date)} />
            <Field label="Job" value={attendance.job?.name ?? '—'} />
            <Field label="Client" value={attendance.job?.client ?? '—'} />
          </dl>
          {attendance.job && (
            <div className="mt-4 border-t border-hairline pt-4">
              <ButtonLink href={routeTo.job(attendance.job.id)} size="sm" variant="secondary">
                Open job
              </ButtonLink>
            </div>
          )}
        </Card>

        <Card accent="success" padding="lg">
          <SectionHeading as="h3" title="Time on Site" />
          <dl className="grid gap-4 sm:grid-cols-2">
            <Field label="Total Hours" value={formatHours(attendance.hours)} strong />
            <Field label="Banked Seconds" value={String(attendance.bankedSeconds)} />
          </dl>
        </Card>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card accent="neutral" padding="lg">
          <SectionHeading as="h3" title="Check In" />
          <dl className="grid gap-4 sm:grid-cols-2">
            <Field label="Time" value={formatTime(attendance.checkIn.at)} />
            <Field
              label="Method"
              value={attendance.checkIn.method ? METHOD_LABEL[attendance.checkIn.method] : '—'}
            />
            <Field label="Distance from site" value={formatMeters(attendance.checkIn.distanceMeters)} />
            <Field label="GPS accuracy" value={formatMeters(attendance.checkIn.accuracyMeters)} />
            <Field label="Coordinates" value={formatCoords(attendance.checkIn.lat, attendance.checkIn.lng)} />
          </dl>
          {attendance.checkIn.photoUrl && (
            <div className="mt-4 border-t border-hairline pt-4">
              <dt className="mb-2 text-2xs tracking-wide text-white/80 uppercase">Photo</dt>
              <a href={attendance.checkIn.photoUrl} target="_blank" rel="noreferrer">
                <img
                  src={attendance.checkIn.photoUrl}
                  alt="Check-in photo"
                  className="h-40 w-40 rounded-panel border border-hairline object-cover"
                />
              </a>
            </div>
          )}
        </Card>

        <Card accent="warning" padding="lg">
          <SectionHeading as="h3" title="Check Out" />
          {attendance.checkOut.at ? (
            <dl className="grid gap-4 sm:grid-cols-2">
              <Field label="Time" value={formatTime(attendance.checkOut.at)} />
              <Field
                label="Method"
                value={attendance.checkOut.method ? METHOD_LABEL[attendance.checkOut.method] : '—'}
              />
              <Field label="Distance from site" value={formatMeters(attendance.checkOut.distanceMeters)} />
              <Field label="GPS accuracy" value={formatMeters(attendance.checkOut.accuracyMeters)} />
              <Field label="Coordinates" value={formatCoords(attendance.checkOut.lat, attendance.checkOut.lng)} />
            </dl>
          ) : (
            <p className="flex items-center gap-2 text-md text-white/70">
              <MapPin size={15} aria-hidden />
              Still on site — no check-out recorded yet.
            </p>
          )}
        </Card>
      </div>

      {attendance.checkIn.method === 'automatic' && (
        <p className="mt-6 flex items-center gap-2 text-sm text-white/60">
          <Camera size={14} aria-hidden />
          Verified by GPS match against the job site.
        </p>
      )}
    </PageTransition>
  )
}

function Field({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-2xs tracking-wide text-white/80 uppercase">{label}</dt>
      <dd className={strong ? 'mt-1 text-lg font-semibold text-white' : 'mt-1 truncate text-md text-white'} title={value}>
        {value}
      </dd>
    </div>
  )
}

AttendanceShow.layout = appLayout
