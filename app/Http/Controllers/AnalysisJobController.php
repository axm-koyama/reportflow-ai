<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AnalysisJob\BuildAnalysisTemplateColumnCandidatesAction;
use App\Actions\AnalysisJob\CreateAnalysisJobAction;
use App\Actions\AnalysisJob\RecoverFailedAnalysisJobAction;
use App\Actions\AnalysisJob\ResolveAnalysisTemplateAction;
use App\Actions\AnalysisJob\ResolveEffectiveColumnMappingAction;
use App\Actions\AnalysisJob\UpdateAnalysisJobAction;
use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Http\Requests\AnalysisJob\CreateAnalysisJobRequest;
use App\Http\Requests\AnalysisJob\UpdateAnalysisJobMappingRequest;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\DataFile;
use App\Models\Project;
use App\Queries\ActionProposal\GetControlledActionViewDataQuery;
use App\Queries\AnalysisJob\ListAnalysisJobsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisJobController extends Controller
{
    public function index(Project $project, ListAnalysisJobsQuery $query): View
    {
        $analysisJobs = $query->execute($project);

        return view('analysis-jobs.index', compact('project', 'analysisJobs'));
    }

    /**
     * Display the form for creating a new analysis job.
     */
    public function create(Project $project, DataFile $dataFile): View
    {
        $this->ensureDataFileBelongsToProject($project, $dataFile);
        $this->ensureProjectIsActive($project);

        $analysisTemplates = config('analysis_templates');

        return view('analysis-jobs.create', compact('project', 'dataFile', 'analysisTemplates'));
    }

    /**
     * Store a new analysis job.
     */
    public function store(
        CreateAnalysisJobRequest $request,
        Project $project,
        DataFile $dataFile,
        CreateAnalysisJobAction $action,
    ): RedirectResponse {
        $this->ensureDataFileBelongsToProject($project, $dataFile);
        $this->ensureProjectIsActive($project);

        $analysisJob = $action->execute(
            $dataFile,
            $request->title(),
            $request->prompt(),
            $request->templateKey(),
        );

        return redirect()->route('projects.analysis-jobs.show', [$project, $analysisJob]);
    }

    public function recover(
        Project $project,
        AnalysisJob $analysisJob,
        RecoverFailedAnalysisJobAction $action,
    ): RedirectResponse {
        $analysisJob->loadMissing('dataFile');
        $this->ensureAnalysisJobBelongsToProject($project, $analysisJob);
        $this->ensureProjectIsActive($project);

        $result = $action->execute($project, $analysisJob);

        $message = $result['created']
            ? 'A new recovery attempt was created. This attempt may make new AI calls and incur additional cost.'
            : 'A recovery attempt already exists. Opening the existing attempt.';

        return redirect()
            ->route('projects.analysis-jobs.show', [$project, $result['analysis_job']])
            ->with('success', $message);
    }

    /**
     * Display the details of an analysis job.
     *
     * Phase 4-B: also computes, per EvaluationFact, whether it was a
     * Diagnosis candidate at all (see DetermineDiagnosisEligibilityAction)
     * — a pure, deterministic, side-effect-free re-derivation, never a
     * stored flag (see docs/product/DIAGNOSIS_ENGINE.md "Diagnosis
     * unavailable UI"). This is what lets the view distinguish "not
     * eligible" (no Diagnosis section) from "eligible but no
     * DiagnosisResult" (Diagnosis unavailable — a per-entity soft-fail;
     * see RunDiagnosisForAnalysisJobAction) without a dedicated
     * diagnosis_status column.
     *
     * Phase 4-C: Priority Eligibility is, by definition, the exact same
     * condition as Diagnosis Eligibility (see
     * PrioritizeAnalysisJobAction's docblock and
     * docs/product/PRIORITY_ENGINE.md "Priority Eligibility") — the same
     * $diagnosisEligibility array is reused for the Priority column
     * rather than computed a second time, and passed to the view under
     * both names so the Blade template can express its own intent
     * (Diagnosis section vs. Priority column) without implying two
     * different computations exist.
     */
    public function show(
        Project $project,
        AnalysisJob $analysisJob,
        DetermineDiagnosisEligibilityAction $determineDiagnosisEligibilityAction,
        GetControlledActionViewDataQuery $getControlledActionViewDataQuery,
    ): View {
        $analysisJob->loadMissing([
            'dataFile',
            'analysisJobDetail',
            'evaluationFacts.diagnosisResult',
            'evaluationFacts.priorityResult',
            'actionProposals.evaluationFact',
            'actionProposals.priorityResult',
            'recoveredFrom',
            'recoveryAttempt',
        ]);

        $this->ensureAnalysisJobBelongsToProject($project, $analysisJob);

        $diagnosisEligibility = [];

        if ($analysisJob->template_key !== null) {
            foreach ($analysisJob->evaluationFacts as $fact) {
                $diagnosisEligibility[$fact->evaluation_fact_id] = $determineDiagnosisEligibilityAction->execute(
                    $fact,
                    $analysisJob->template_key,
                );
            }
        }

        $priorityEligibility = $diagnosisEligibility;
        $controlledActionViewData = $getControlledActionViewDataQuery->execute($analysisJob);

        return view('analysis-jobs.show', compact(
            'project',
            'analysisJob',
            'diagnosisEligibility',
            'priorityEligibility',
            'controlledActionViewData',
        ));
    }

    /**
     * Display the Mapping Preview / manual override form (Phase 3-C, see
     * docs/product/MAPPING_CONTROL.md). Only meaningful while the
     * AnalysisJob is AwaitingMappingConfirmation; visiting this URL for a
     * Job in any other status (stale link, already confirmed, already
     * completed) redirects back to show() rather than erroring, since
     * nothing here is unsafe to view again — it just no longer applies.
     *
     * Rebuilds column_candidates from a fresh DataProfilingAction run
     * (CSV re-streamed, no AI call) — column_candidates are never
     * persisted (see docs/product/DATA_PROFILING.md §39/§40) and Mapping
     * AI is never re-invoked just to render this page.
     */
    public function editMapping(
        Project $project,
        AnalysisJob $analysisJob,
        DataProfilingAction $dataProfilingAction,
        BuildAnalysisTemplateColumnCandidatesAction $buildAnalysisTemplateColumnCandidatesAction,
    ): View|RedirectResponse {
        $analysisJob->loadMissing(['dataFile', 'analysisJobDetail']);

        $this->ensureAnalysisJobBelongsToProject($project, $analysisJob);
        $this->ensureAnalysisJobHasTemplate($analysisJob);

        if ($analysisJob->status !== AnalysisJobStatus::AwaitingMappingConfirmation) {
            return redirect()
                ->route('projects.analysis-jobs.show', [$project, $analysisJob])
                ->with('success', 'このAnalysisJobは現在Mapping確認待ちではありません。');
        }

        $template = config("analysis_templates.{$analysisJob->template_key}");
        $dataProfile = $dataProfilingAction->execute($analysisJob->dataFile);
        $columnCandidates = $buildAnalysisTemplateColumnCandidatesAction->execute($template['fields'], $dataProfile);

        $detail = $analysisJob->analysisJobDetail;

        return view('analysis-jobs.mapping', [
            'project' => $project,
            'analysisJob' => $analysisJob,
            'template' => $template,
            'columnCandidates' => $columnCandidates,
            'aiColumnMapping' => $detail->column_mapping ?? [],
            'manualColumnMapping' => $detail->manual_column_mapping ?? [],
        ]);
    }

    /**
     * Confirm (with optional manual overrides) the Column Mapping for an
     * AwaitingMappingConfirmation AnalysisJob, and resume its analysis
     * (Phase 3-C, see docs/product/MAPPING_CONTROL.md).
     *
     * Only {field: column} pairs are ever read from the request — the
     * candidate list, inferred_type, confidence, status, and source are
     * always rebuilt/decided server-side and never trusted from client
     * input (see UpdateAnalysisJobMappingRequest / docs/product/MAPPING_CONTROL.md
     * "Security").
     *
     * lockForUpdate() + a strict status check inside the transaction is
     * this endpoint's idempotency guard: a double click, a page refresh,
     * or a second browser tab submitting the same form all resolve to
     * "status is no longer AwaitingMappingConfirmation" on every submit
     * after the first, and are treated as already handled rather than
     * re-processed or re-dispatched.
     */
    public function updateMapping(
        UpdateAnalysisJobMappingRequest $request,
        Project $project,
        AnalysisJob $analysisJob,
        DataProfilingAction $dataProfilingAction,
        BuildAnalysisTemplateColumnCandidatesAction $buildAnalysisTemplateColumnCandidatesAction,
        ResolveAnalysisTemplateAction $resolveAnalysisTemplateAction,
        ResolveEffectiveColumnMappingAction $resolveEffectiveColumnMappingAction,
        UpdateAnalysisJobAction $updateAnalysisJobAction,
    ): RedirectResponse {
        // Project ownership and "has a Template" are already enforced by
        // UpdateAnalysisJobMappingRequest::authorize() (a 403) before this
        // method body ever runs — unlike editMapping() (no FormRequest),
        // no body-level guard is needed here.
        $analysisJob->loadMissing(['dataFile', 'analysisJobDetail']);

        $manualOverrides = $request->manualOverrides();

        // Rebuilding the Data Profile / column_candidates is pure CSV
        // read + Laravel compute (no AI, no database write), so it is
        // deliberately done *before* opening the transaction/lock below —
        // see docs/product/MAPPING_CONTROL.md "Resume処理" for why this
        // is safe (DataFile content is immutable after creation; Phase
        // 3-C does not implement DataFile replacement).
        $template = config("analysis_templates.{$analysisJob->template_key}");
        $dataProfile = $dataProfilingAction->execute($analysisJob->dataFile);
        $columnCandidates = $buildAnalysisTemplateColumnCandidatesAction->execute($template['fields'], $dataProfile);

        $outcome = DB::transaction(function () use (
            $analysisJob,
            $template,
            $columnCandidates,
            $manualOverrides,
            $resolveEffectiveColumnMappingAction,
            $updateAnalysisJobAction,
        ): array {
            /** @var AnalysisJob $lockedAnalysisJob */
            $lockedAnalysisJob = AnalysisJob::query()
                ->with('analysisJobDetail')
                ->lockForUpdate()
                ->findOrFail($analysisJob->analysis_job_id);

            if ($lockedAnalysisJob->status !== AnalysisJobStatus::AwaitingMappingConfirmation) {
                return ['result' => 'already_handled'];
            }

            $effective = $resolveEffectiveColumnMappingAction->execute(
                $template['fields'],
                $columnCandidates,
                $lockedAnalysisJob->analysisJobDetail->column_mapping ?? [],
                $manualOverrides,
                $template['required_fields'] ?? [],
                $template['required_field_groups'] ?? [],
            );

            if ($effective['missing_required_fields'] !== [] || $effective['missing_required_field_groups'] !== []) {
                $updateAnalysisJobAction->recordManualMappingAttempt($lockedAnalysisJob, $manualOverrides);

                return [
                    'result' => 'still_missing',
                    'missing_required_fields' => $effective['missing_required_fields'],
                    'missing_required_field_groups' => $effective['missing_required_field_groups'],
                ];
            }

            $updateAnalysisJobAction->resumeAfterMappingConfirmation(
                $lockedAnalysisJob,
                $manualOverrides,
                $effective['effective_mapping'],
            );

            return ['result' => 'confirmed'];
        });

        if ($outcome['result'] === 'already_handled') {
            return redirect()
                ->route('projects.analysis-jobs.show', [$project, $analysisJob])
                ->with('success', 'このAnalysisJobは既に処理済みです。');
        }

        if ($outcome['result'] === 'still_missing') {
            $missingMessage = $resolveAnalysisTemplateAction->missingRequiredFieldsMessage(
                $template,
                $template['fields'] ?? [],
                $template['required_field_groups'] ?? [],
                $outcome['missing_required_fields'],
                $outcome['missing_required_field_groups'],
            );

            return redirect()
                ->route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob])
                ->withErrors(['mapping' => $missingMessage])
                ->withInput();
        }

        ExecuteAnalysisJob::dispatch($analysisJob->analysis_job_id)->afterCommit();

        return redirect()
            ->route('projects.analysis-jobs.show', [$project, $analysisJob])
            ->with('success', 'Mappingを確定し、分析を再開しました。');
    }

    /**
     * Guard against an AnalysisJob whose DataFile belongs to a different
     * Project, or that no longer exists (e.g. it was soft-deleted after
     * the AnalysisJob referencing it was created).
     */
    private function ensureAnalysisJobBelongsToProject(Project $project, AnalysisJob $analysisJob): void
    {
        $this->ensureDataFileBelongsToProject($project, $analysisJob->dataFile);
    }

    /**
     * Guard against a DataFile that belongs to a different Project, or that
     * no longer exists (e.g. it was soft-deleted after the AnalysisJob
     * referencing it was created).
     */
    private function ensureDataFileBelongsToProject(Project $project, ?DataFile $dataFile): void
    {
        abort_if($dataFile === null || $dataFile->project_id !== $project->project_id, 404);
    }

    /**
     * Mapping Preview/confirmation only exists for Template-based
     * AnalysisJobs — Free Analysis (template_key === null) never produces
     * a Column Mapping and can never reach AwaitingMappingConfirmation,
     * so its Mapping route is simply not a valid destination.
     */
    private function ensureAnalysisJobHasTemplate(AnalysisJob $analysisJob): void
    {
        abort_if($analysisJob->template_key === null, 404);
    }

    /**
     * Analysis jobs may only be created for an active Project.
     *
     * The "Analyze" link is already hidden in data-files/index.blade.php
     * for non-active projects, so this is a defense-in-depth check reached
     * only via a direct GET/POST (e.g. a stale tab, or the project being
     * archived between page load and submit). ValidationException is safe
     * to throw here even for the GET create() action: Laravel redirects
     * back (typically to the data-files index) with the error flashed to
     * the session, and that page renders $errors->any(). See
     * AnalysisJobControllerTest::test_create_is_rejected_for_an_archived_project().
     */
    private function ensureProjectIsActive(Project $project): void
    {
        if ($project->status !== ProjectStatus::Active) {
            throw ValidationException::withMessages([
                'project' => ['Analysis jobs can only be created for an active project.'],
            ]);
        }
    }
}
