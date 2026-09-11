<?php

declare(strict_types=1);

namespace App\Actions\Report;

use Illuminate\Contracts\View\Factory;

class RenderHtmlReportAction
{
    public const string RENDERER_VERSION = 'report_renderer_v1.1';

    public function __construct(private readonly Factory $view) {}

    /** @param array<string, mixed> $snapshot */
    public function execute(array $snapshot): string
    {
        return $this->view->make('reports.partials.document', ['snapshot' => $snapshot])->render();
    }
}
