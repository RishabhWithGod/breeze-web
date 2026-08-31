import { useState } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { FolderKanban, Lightbulb, TriangleAlert } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  RadioGroup,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ProjectPdfPicker } from '@/components/projects'
import { PROJECT_TYPE_OPTIONS, ROUTES } from '@/constants'
import type {
  ProjectDocumentLimits,
  ProjectDraft,
  ProjectType,
  RejectedUploadFile,
} from '@/types'

export interface ProjectCreateProps {
  /** Clients already on record, offered in the Client select. */
  clients: readonly string[]
  disciplines: readonly string[]
  limits: ProjectDocumentLimits
}

/**
 * Create New Project.
 *
 * Two things are defined here: the project itself, and the drawing PDFs it holds.
 * Both are posted in one multipart request — `documents[i]` with its label in
 * `document_titles[i]` — so a project and its drawing set are created together or
 * not at all.
 *
 * Nothing is sent to the AI engine: this screen records the work, and a takeoff is
 * started from the AI Takeoff module when the drawings are ready.
 */
export default function ProjectCreate({
  clients,
  disciplines,
  limits,
}: ProjectCreateProps) {
  /** Files the browser refused outright — never sent, so reported client-side. */
  const [rejected, setRejected] = useState<readonly RejectedUploadFile[]>([])

  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectDraft>({
      name: '',
      code: '',
      client: '',
      location: '',
      discipline: disciplines[0] ?? 'Electrical',
      project_type: '',
      due_date: '',
      notes: '',
      documents: [],
      document_titles: [],
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Client is required" sitting under a field the user has just filled in, so
   * each edit clears its own message.
   */
  const update = <K extends FormDataKeys<ProjectDraft>>(
    field: K,
    value: FormDataValues<ProjectDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const addDocuments = (files: File[]) => {
    setData('documents', [...data.documents, ...files])
    // Labels are positional, so every file gets a slot whether it is labelled or not.
    setData('document_titles', [...data.document_titles, ...files.map(() => '')])
    if (errors.documents) clearErrors('documents')
  }

  const removeDocument = (index: number) => {
    setData(
      'documents',
      data.documents.filter((_, position) => position !== index),
    )
    setData(
      'document_titles',
      data.document_titles.filter((_, position) => position !== index),
    )
  }

  const setDocumentTitle = (index: number, title: string) => {
    setData(
      'document_titles',
      data.document_titles.map((current, position) =>
        position === index ? title : current,
      ),
    )
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    // A single name field now stands in for both — the server still records
    // a separate `client` value, so it's carried across at submit time
    // rather than asked for twice.
    transform((payload) => ({ ...payload, client: payload.name }))
    // `forceFormData` because the PDFs cannot travel as JSON.
    post(ROUTES.projects, { forceFormData: true, preserveScroll: true })
  }

  const disciplineOptions = disciplines.map((discipline) => ({
    label: discipline,
    value: discipline,
  }))

  return (
    <PageTransition>
      <Head title="Create New Client" />

      <PageHeader
        title="Create New Client"
        subtitle="Define the client, then attach the drawing PDFs it will be taken off from."
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.projects },
          { label: 'New Client' },
        ]}
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this client can be created.
            </Alert>
          )}

          {rejected.length > 0 && (
            <Alert
              key="rejected"
              tone="warning"
              title={`${rejected.length} file(s) could not be added`}
              icon={TriangleAlert}
              onDismiss={() => setRejected([])}
            >
              <ul className="mt-1 space-y-1">
                {rejected.map((item) => (
                  <li key={item.name} className="text-sm">
                    <span className="font-medium text-white">{item.name}</span> —{' '}
                    {item.reason}
                  </li>
                ))}
              </ul>
            </Alert>
          )}

          {/* PHP's own upload ceiling, when it is the binding constraint. */}
          {limits.serverHint && (
            <Alert key="php-limit" tone="info" title="Server upload limit">
              {limits.serverHint}
            </Alert>
          )}
        </AnimatePresence>

        {/* ------------------------------------------------- Project details --- */}
        <Card padding="lg">
          <CardHeader
            title="Client details"
            subtitle="Who the client is for, and where the work is"
          />

          <div className="space-y-6">
            {/*
              One field rather than a separate name + client pair: clients
              already on record are offered as suggestions via the datalist,
              and a client new to the workspace is typed straight in.
            */}
            <TextInput
              id="project-name"
              label="Client Name*"
              placeholder="e.g. Harborview Data Hall"
              list="project-known-clients"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(clients.length > 0
                ? { hint: 'Existing clients are suggested as you type.' }
                : {})}
              {...(errors.name ? { error: errors.name } : {})}
            />
            <datalist id="project-known-clients">
              {clients.map((client) => (
                <option key={client} value={client} />
              ))}
            </datalist>

            <div className="grid gap-6 lg:grid-cols-2">
              <TextInput
                id="project-location"
                label="Site / Location"
                placeholder="Enter the site address"
                value={data.location}
                onChange={(event) => update('location', event.target.value)}
                {...(errors.location ? { error: errors.location } : {})}
              />
              <SelectField
                id="project-discipline"
                label="Discipline"
                options={disciplineOptions}
                value={data.discipline}
                onChange={(event) => update('discipline', event.target.value)}
                {...(errors.discipline ? { error: errors.discipline } : {})}
              />
            </div>

            <TextInput
              id="project-due"
              type="date"
              label="Takeoff Due"
              value={data.due_date}
              onChange={(event) => update('due_date', event.target.value)}
              {...(errors.due_date ? { error: errors.due_date } : {})}
            />

            <RadioGroup
              name="project-type"
              label="Client Type"
              options={PROJECT_TYPE_OPTIONS}
              value={data.project_type}
              onChange={(value) => update('project_type', value as ProjectType)}
              {...(errors.project_type ? { error: errors.project_type } : {})}
            />

            <TextArea
              id="project-notes"
              label="Notes for the takeoff"
              rows={4}
              maxLength={2000}
              placeholder="Anything the estimator should know before counting — revisions, exclusions, scope splits."
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
              addon={`${data.notes.length}/2000`}
              {...(errors.notes ? { error: errors.notes } : {})}
            />
          </div>
        </Card>

        {/* ---------------------------------------------------- Drawing PDFs --- */}
        <Card padding="lg" index={1}>
          <CardHeader
            title="Drawing PDFs"
            subtitle={
              limits.maxFiles === 1
                ? 'Add one drawing PDF, and label it so it reads clearly later.'
                : `Up to ${limits.maxFiles} PDFs. Label each one so the set reads clearly later.`
            }
          />

          <ProjectPdfPicker
            files={data.documents}
            titles={data.document_titles}
            onAdd={addDocuments}
            onRemove={removeDocument}
            onTitleChange={setDocumentTitle}
            onReject={(items) => setRejected(items)}
            maxFiles={limits.maxFiles}
            maxFileSizeMb={limits.maxFileSizeMb}
            disabled={processing}
            fileErrors={errors as Record<string, string>}
            {...(errors.documents ? { error: errors.documents } : {})}
          />
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={ROUTES.projects} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={FolderKanban} isLoading={processing}>
            Create Client
          </Button>
        </div>
      </form>

      {/* ------------------------------------------------------- Pro tip ---- */}
      <aside className="mt-6 flex items-start gap-4 rounded-card border border-hairline glass p-5 shadow-panel sm:p-6">
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
          <Lightbulb size={20} aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="text-md font-semibold text-brand">Pro Tip</p>
          <p className="mt-1 text-md text-white">
            Drawings can be added to the client later, and a client created here is
            ready for AI Takeoff — run the analysis from the AI Takeoff module when the
            set is complete.
          </p>
        </div>
      </aside>
    </PageTransition>
  )
}

ProjectCreate.layout = appLayout
