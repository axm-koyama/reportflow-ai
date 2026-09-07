<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AnalysisJobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnalysisJobStatusTest extends TestCase
{
    #[DataProvider('presentations')]
    public function test_it_exposes_the_canonical_presentation(
        AnalysisJobStatus $status,
        string $label,
        string $badgeClass,
    ): void {
        $this->assertSame($label, $status->label());
        $this->assertSame($badgeClass, $status->badgeClass());
    }

    /** @return iterable<string, array{AnalysisJobStatus, string, string}> */
    public static function presentations(): iterable
    {
        yield 'pending' => [AnalysisJobStatus::Pending, 'Pending', 'badge-pending'];
        yield 'processing' => [AnalysisJobStatus::Processing, 'Processing', 'badge-processing'];
        yield 'mapping confirmation' => [
            AnalysisJobStatus::AwaitingMappingConfirmation,
            'Mapping confirmation required',
            'badge-awaiting-mapping-confirmation',
        ];
        yield 'completed' => [AnalysisJobStatus::Completed, 'Completed', 'badge-completed'];
        yield 'failed' => [AnalysisJobStatus::Failed, 'Failed', 'badge-failed'];
    }
}
