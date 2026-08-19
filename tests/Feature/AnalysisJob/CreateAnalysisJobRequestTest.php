<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Http\Requests\AnalysisJob\CreateAnalysisJobRequest;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CreateAnalysisJobRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No Controller/Route exists for AnalysisJob creation yet, and this
        // test must not add one to the application. Registering a throwaway
        // route here (test-only, never touches routes/web.php) lets
        // CreateAnalysisJobRequest be exercised through the real Laravel
        // HTTP request/validation pipeline instead of calling its methods
        // directly.
        Route::post('/__test/analysis-jobs', function (CreateAnalysisJobRequest $request) {
            return response()->json([
                'title' => $request->title(),
                'prompt' => $request->prompt(),
            ]);
        });
    }

    /**
     * title: required
     */
    public function test_title_is_required(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'prompt' => 'プロンプト',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    /**
     * title: string
     */
    public function test_title_must_be_a_string(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => ['not', 'a', 'string'],
            'prompt' => 'プロンプト',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    /**
     * title: max:255
     */
    public function test_title_must_not_exceed_255_characters(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => str_repeat('a', 256),
            'prompt' => 'プロンプト',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    /**
     * prompt: required
     */
    public function test_prompt_is_required(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => 'タイトル',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('prompt');
    }

    /**
     * title: 空白のみ不可(TrimStrings + ConvertEmptyStringsToNull の
     * グローバルミドルウェアが検証前に "" -> null へ変換するため required
     * で弾かれる。bootstrap/app.php でこれらのミドルウェアが除外された場合
     * にも検知できるよう、実際のHTTPパイプラインを通して確認する)
     */
    public function test_title_must_not_be_only_whitespace(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => '   ',
            'prompt' => 'プロンプト',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    /**
     * prompt: string
     */
    public function test_prompt_must_be_a_string(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => 'タイトル',
            'prompt' => ['not', 'a', 'string'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('prompt');
    }

    /**
     * prompt: 空文字不可
     */
    public function test_prompt_must_not_be_empty(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => 'タイトル',
            'prompt' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('prompt');
    }

    /**
     * prompt: 空白のみ不可(title と同様、グローバルミドルウェアによる変換に依存)
     */
    public function test_prompt_must_not_be_only_whitespace(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => 'タイトル',
            'prompt' => '   ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('prompt');
    }

    /**
     * prompt: max:5000
     */
    public function test_prompt_must_not_exceed_5000_characters(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => 'タイトル',
            'prompt' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('prompt');
    }

    /**
     * 正常な入力はバリデーションを通過する
     */
    public function test_it_passes_validation_with_valid_input(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => '売上傾向分析',
            'prompt' => 'CSVを分析して傾向を要約してください。',
        ]);

        $response->assertOk();
    }

    /**
     * typed accessor: title() / prompt() が validated value を string として返す
     */
    public function test_typed_accessors_return_the_validated_values_as_strings(): void
    {
        $response = $this->postJson('/__test/analysis-jobs', [
            'title' => '地域別売上比較',
            'prompt' => '地域別の売上を比較してください。',
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'title' => '地域別売上比較',
            'prompt' => '地域別の売上を比較してください。',
        ]);
    }
}
