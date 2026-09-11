# Mapping Control & Reliability (Phase 3-C)

## 1. Purpose

Phase 3-A/3-Bまで、Analysis Template経由のColumn Mappingは「AIが提案し、
Laravelが検証した結果」がそのまま最終Factとして使われていた。AIの確信度が
不十分で`required_fields`/`required_field_groups`を満たせなかった場合、
AnalysisJobはそのままFailedになっていた(`RuntimeException`)。

Phase 3-Cの目的は、AI Column Mappingを「AIが決めた最終Fact」から、以下の
プロセスへ変更することである。

```text
AI proposes
↓
Laravel validates
↓
必要ならUser override
↓
Laravel re-validates
↓
Effective Mapping = 唯一の正
```

優先順位: **Manual Mapping > Validated AI Mapping > Unmapped**。

ただしManual Mappingも無条件には信用しない。既存の型・実在性・
duplicate・required判定(`ValidateColumnMappingAction`)を、AI提案と
全く同じ厳密さでmanual提案にも適用する。

既存の大原則は変更しない。

```text
Laravel = Facts / Validation / Deterministic Processing
AI      = Semantic Interpretation / Planning
```

---

## 2. Architecture

```text
Create AnalysisJob
↓
Queue: ExecuteAnalysisJob (ShouldBeUnique, analysisJobId単位)
↓
ExecuteAnalysisJobAction::execute()
├─ 既にCompleted/Failed/AwaitingMappingConfirmation? → no-op return(§8）
├─ markProcessing(): Pending|Processing → Processing
├─ DataProfilingAction / MetricAggregationAction(無変更)
└─ template_key有り?
    ├─ No → 既存Free Analysisパス(無変更、AI呼び出し2回のまま)
    └─ Yes
        ├─ effective_column_mapping 確定済み?
        │    Yes → Mapping AIを呼ばず、確定済みEffective Mappingを使用
        │    No  → column_mapping 保存済み?(§9.1 Retry Recovery)
        │           Yes → 既存column_mappingをvalidated AI mappingとして再利用
        │                 (Mapping AI**再Call禁止**、write-once監査記録)
        │           No  → ResolveAnalysisTemplateAction(Mapping AI + 検証)
        │                 → recordColumnMapping()(AI提案の記録、write-once保存)
        │           ↓(いずれの経路でも合流)
        │           BuildAnalysisTemplateColumnCandidatesAction(candidate再構築)
        │           → ResolveEffectiveColumnMappingAction(manual=[])
        │           → missing_required_fields/groupsあり?
        │                Yes → markAwaitingMappingConfirmation(), return
        │                No  → recordEffectiveMapping() (source=ai)
        ├─ recommended_derived_metricsをEffective Mapping基準で(再)filter
        ├─ PlanDerivedMetricsAction / CalculateDerivedMetricsAction(無変更)
        ├─ BuildAnalysisContextAction / AiAnalysisClient::analyze()(無変更)
        └─ markCompleted()

[AwaitingMappingConfirmationの場合]
↓
GET  .../mapping  (Mapping Preview。DataProfilingAction再実行、AI呼び出しなし)
↓
PATCH .../mapping (manual override送信)
├─ lockForUpdate + statusガード(二重処理防止)
├─ ResolveEffectiveColumnMappingAction(Full Mapping Proposal生成 + 再検証)
├─ missing_required_fields/groupsまだあり? → Awaitingのまま、dispatchなし
└─ なし → resumeAfterMappingConfirmation(): Awaiting → Pending
           ↓ commit後
           ExecuteAnalysisJob::dispatch()->afterCommit()
           ↓
           Queue → markProcessing(): Pending → Processing
           (Mapping AIは呼ばれない。以降は通常のパイプラインへ合流)
```

`ResolveAnalysisTemplateAction` / `MapAnalysisTemplateColumnsAction` /
`ValidateColumnMappingAction` / `PlanDerivedMetricsAction` /
`CalculateDerivedMetricsAction` / `BuildAnalysisContextAction` /
`AiAnalysisClient` / `MetricAggregationAction` / `DataProfilingAction` は
Phase 3-Cで**中身のロジックを変更していない**(`ResolveAnalysisTemplateAction`
は「required不足でthrowする責務」を削除しただけで、Mapping AI呼び出しと
`ValidateColumnMappingAction`呼び出し自体は無変更)。新規ロジックは全て
`ExecuteAnalysisJobAction`(オーケストレーション)・`UpdateAnalysisJobAction`
(状態遷移)・新規Action・新規Controller/Route/Viewに閉じている。

---

## 3. AnalysisJob Status遷移

```php
enum AnalysisJobStatus: int
{
    case Pending = 0;
    case Processing = 1;
    case Completed = 2;
    case Failed = 3;
    case AwaitingMappingConfirmation = 4; // Phase 3-C
}
```

`analysis_jobs.status`は`unsignedTinyInteger`でDB制約(CHECK等)を持たない
ため、この追加に**migrationは不要**(既存の空き整数値を使うだけ)。

新規遷移(`UpdateAnalysisJobAction`):

| メソッド | 許可される遷移元 | 遷移先 |
|---|---|---|
| `markAwaitingMappingConfirmation()` | Processing | AwaitingMappingConfirmation |
| `resumeAfterMappingConfirmation()` | AwaitingMappingConfirmation | Pending |

いずれも既存の`markProcessing()`/`markCompleted()`/`markFailed()`と同じ
「許可されない遷移元ならRuntimeExceptionを投げる」厳密ガード方針を踏襲する。

**`AwaitingMappingConfirmation → Processing`への直接遷移は行わない。**
必ず`AwaitingMappingConfirmation → Pending`を経由し、コミット成功後に
`ExecuteAnalysisJob::dispatch()->afterCommit()`する。理由:

- `Processing`は「Queue Workerが実際に処理中」という意味を保つ
- DB commitが成功してもdispatchが失敗した場合、AnalysisJobは安全に
  `Pending`のまま残る(再開可能)。直接`Processing`にしてしまうと、
  実際には誰も処理していないのに"処理中"と主張する状態になり得る

---

## 4. AI Mapping / Manual Mapping / Effective Mappingの区別

`analysis_job_details`の3つのJSON列は、それぞれ独立した意味を持つ。

| カラム | 意味 | 更新タイミング |
|---|---|---|
| `column_mapping` | AI提案をValidateColumnMappingActionで検証した**記録**。一度書かれたら変更されない | 初回Mapping AI呼び出し直後のみ(write-once。§9.1) |
| `manual_column_mapping` | Userが明示的に触ったfieldのみのsparse override diff。キーが無いfieldはAI mappingへ委ねる、column=nullは明示的な未設定 | Mapping確認画面からの送信時のみ |
| `effective_column_mapping` | Manual > Validated AI > Unmappedで確定した、Planning/Calculation/Analyzeが実際に使う唯一のFact。全fieldに`source: ai\|manual`付き | 自動確定パス・手動確定パスいずれでも必ず保存 |

いずれもnullable JSON、既存データ互換(Free Analysisは常に3列ともnull)。

`column_mapping`は**Manual Override後も書き換えない**。「AIが最初に何を
提案したか」の監査記録として独立に残す。

---

## 5. ResolveEffectiveColumnMappingAction

新規Action。責務は「Validated AI Mapping + Manual sparse override →
Full Mapping Proposal生成 → 再検証 → Effective Mapping確定」の一連の
流れをオーケストレーションすること。

**Cross-source duplicateの検出**が設計上の核心である。

```text
AI:     product  → 商品名
Manual: category → 商品名
```

このケースを検出するには、AI mappingとManual mappingを**別々に検証して
後でmergeする**設計では不十分である。それぞれ単独では重複していないため
(AIのproductは単独では正しく、manualのcategoryも単独では正しい)、
mergeした後で初めて「同じ実列を2つのsemantic fieldが主張している」こと
が分かる。

そのためこのActionは、まず全semantic field分のFull Mapping Proposal
(`[{field, column, confidence}]`)を1つ組み立ててから、
`ValidateColumnMappingAction`(既存、無変更)へ**1回だけ**渡して検証する。
`ValidateColumnMappingAction`の既存high+high ambiguousルールが、
AI由来かManual由来かを区別せず、そのまま両方を拾う。

Full Mapping Proposal構築ルール:

```text
manual_overridesにキーが存在するfield:
    column !== null → confidence: high (人間の明示的選択)
    column === null → confidence: unmapped (明示的な未設定)

manual_overridesにキーが存在しないfield:
    AI側のstatus === 'mapped' → confidence: high としてそのまま採用
    それ以外(low/unmapped/ambiguous/ignored) → confidence: unmapped
    (AIが信頼していなかった値を、再検証だからといって
     highへ格上げして復活させない)
```

このActionは`recommended_derived_metrics`のfilteringを行わない
(§7参照、責務分離)。AI呼び出し・DB書き込みも行わない。

---

## 6. Manual Mapping Validation

新しい型検証ロジックは実装しない。`ValidateColumnMappingAction`の既存の
仕組みをそのまま再利用する。

```text
dimension -> inferred_type === 'string'
measure   -> inferred_type in ['integer', 'decimal']
temporal  -> inferred_type in ['date', 'datetime']
```

`revenue`(measure)へユーザーが`商品名`(string)を選んでも、`商品名`は
`revenue`の`column_candidates`に存在しないため、既存の「unknown
candidate → unmapped強制」ルールがそのまま働く。

| 操作 | 扱い |
|---|---|
| 同じ実列を複数semantic fieldへmanual mapping | 安全側: 既存のhigh+high ambiguousルールをそのまま適用。AI/Manualを問わず両方とも不採用 |
| required fieldへのmanual mapping | 既存required判定がそのまま効く |
| optional fieldへのmanual mapping | 同上 |
| 未設定へ戻す | `{field: {column: null}}`をmanual overrideとして送信 |
| AI mappingをmanualで解除 | 同上 |
| ambiguousの解消 | ユーザーが別の列を選べば、Full Mapping Proposal再検証で自然に解消 |
| ignored fieldの復活 | 同じmanual override機構で実現 |

### 6.1 Laravelが保証するもの / 保証しないもの(意味的責任境界)

Laravel(`ValidateColumnMappingAction`経由)がManual Mappingに対して
**deterministicに保証するもの**は以下に限られる。

- semantic fieldがそのTemplateに実在すること
- columnがそのDataFileの`column_candidates`に実在すること
- `inferred_type`がfieldの`kind`に適合すること(型互換性)
- duplicate / cross-source ambiguityの検出
- `required_fields` / `required_field_groups`の充足判定

**Laravelが保証しないもの**: ユーザーが選んだ列が、そのsemantic field
の**業務上の意味として本当に正しいか**。

例えば`revenue`(measure)へ数値型の`cost`列を明示的に割り当てた場合、
`cost`は`integer`/`decimal`型であり`revenue`の`column_candidates`にも
含まれるため、型validation・存在検証はいずれも通過する。しかし
「`cost`が業務上`revenue`(売上)を意味するかどうか」はLaravelの
検証範囲外であり、ここはユーザー自身の業務判断に委ねられる。

Phase 3-Cの設計では、**Manual Mappingはユーザーの明示的な business
decisionとして扱う**。ユーザーがMapping確定(`resumeAfterMappingConfirmation()`)
を行った時点で、その選択は`effective_column_mapping`へsemantic factと
して記録され、以降のPlanning / Calculation / Final Analysisはその値を
無条件に信頼する。AIに対して「このmanual mappingは意味的に正しいか」
を再判断させることは一切行わない(§9.1 recommended_derived_metrics
filteringも含め、AIはEffective Mappingを常にFactとして扱うだけで、
その意味的妥当性を検証する余地を与えない設計になっている)。

これは既存の`Manual Mapping > Validated AI Mapping`という優先順位
原則と矛盾しない — 優先順位は「どちらの提案を採用するか」を決める
ルールであり、「採用した提案の業務的な正しさをどこまで保証するか」
とは別の軸である。Laravelは後者について、列の存在・型・duplicate・
required条件という**構造的な正しさ**のみを保証し、**意味的な正しさ**
の最終責任はユーザーに残る。

---

## 7. recommended_derived_metrics filtering の再評価

`FilterRecommendedDerivedMetricsAction`(Phase 3-Cで
`ResolveAnalysisTemplateAction`から抽出)は、Mapping解決とは独立した
責務として、config由来の生の`recommended_derived_metrics`と、任意の
`{field: {column, status}}`形式のmapping(AI validatedでもEffectiveでも
同じ形なので区別しない)を受け取り、`left_field`/`right_field`が両方
`status === 'mapped' && column !== null`のものだけを残す。

- **自動確定パス**: `ResolveAnalysisTemplateAction`が内部でこのAction
  を使い、AI validated mappingに対してfilterする(既存動作、無変更)。
- **手動確定パス**: `ExecuteAnalysisJobAction`が、config由来の
  **生の**`recommended_derived_metrics`を読み直し、確定済み
  `effective_column_mapping`に対して同じActionで**再filter**する。
  `ResolveAnalysisTemplateAction`が最初にAI mapping基準で計算した
  filter結果は、手動確定パスでは使わない(古いままだと、manualで
  復活したfieldのhintが反映されない)。

例:

```text
AI:     orders = unmapped
Manual: orders = 注文件数
↓ Effective Mapping基準で再filter
→ average_order_value が使えるようになる

AI:     quantity = 販売数量 (mapped)
Manual: quantity = null (明示的unset)
↓ Effective Mapping基準で再filter
→ average_unit_price がfilterされる
```

---

## 8. Queue no-op guards

`ExecuteAnalysisJobAction::execute()`は、パイプライン本体に入る前に
以下のstatusを無条件no-opとする:

```text
Completed                     → no-op (既に完了済み)
Failed                        → no-op (既に失敗確定済み)
AwaitingMappingConfirmation   → no-op (ユーザー確認待ち中)
```

`Pending`/`Processing`のみ通常通り処理を進める(`Processing`は
`markProcessing()`が既に持つ「既にProcessingならno-op」という
retry-safe性でカバーされる)。

背景: 過去のE2Eで、既にCompletedなAnalysisJobへ残存Queueメッセージが
届き、`markProcessing()`が`RuntimeException("...is not pending")`を
投げて`failed_jobs`へ入る事象があった。Phase 3-CはdispatchポイントをA
つからB(Mapping確認後の再開)へと2箇所に増やすため、この種の事故の
発生面が単純に倍になる。この防御はSales/Ad固有ではなく、Framework
共通の頑健性改善である。

`AwaitingMappingConfirmation`のno-opも同じ理由に加え、もう1つ重要な
理由がある: このstatusの間、`column_mapping`はユーザーが今まさに
確認中の値である。これはもともと、ここでMapping AIが再実行されると
画面に表示中のAI提案と異なる新しい提案で`column_mapping`が上書き
されてしまうリスクだったが、現在は以下の多重防御で防止済みである:
このno-op guard自体(§8)に加え、`effective_column_mapping`が
`null`でも`column_mapping`が既にあればMapping AIを再度呼ばない
retry recovery(§9.1)、および`recordColumnMapping()`自体の
write-once guard(§9.1)。

---

## 9. Queue Resume / Idempotency

### 二重防御

1. **本命**: `AnalysisJobController::updateMapping()`の
   `AnalysisJob::lockForUpdate()` + `status !== AwaitingMappingConfirmation`
   ガード。double click・ブラウザ更新・2タブ同時送信は、いずれも
   「2回目以降はstatusが既にAwaitingMappingConfirmationでない」ため
   `already_handled`として安全に無視される。
2. **補助防御**: `ExecuteAnalysisJob implements ShouldBeUnique`
   (`uniqueId()`は`analysisJobId`)。同一AnalysisJobに対するQueue
   投入がロック期間中(`uniqueFor = 300`秒)は1つに制限される。

### ShouldBeUnique判断

実装前に以下を確認した。

| 項目 | 値 |
|---|---|
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` |
| atomic lock対応 | **対応**(`cache_locks`テーブルがLaravelデフォルトの`0001_01_01_000001_create_cache_table`migrationに含まれ、既に適用済み。`database` cache driverはLaravel 9以降`LockProvider`を実装しており、`Cache::lock()`が安全に使える) |
| 採用可否 | **採用**(補助防御として) |

`ShouldBeUnique`は**唯一の防御にはしない**。cache driverが将来
atomic lockに対応しないものへ変更された場合でも、本命の
`lockForUpdate` + statusガード + `ExecuteAnalysisJobAction`自身の
no-opガードは独立して機能し続ける。

### DB transaction / dispatchタイミング

`updateMapping()`のDB書き込み(manual/effective mapping保存 +
status遷移)は1つのtransaction内で行う。DataProfilingActionによる
candidate再構築(CSV読み込みを伴う)は、`CreateAnalysisJobAction`の
既存パターンに倣い**transactionの外側**で先に行い、lock保持時間を
最小化する(DataFile内容はAnalysisJob作成後不変という前提があるため
安全 — §12参照)。`ExecuteAnalysisJob::dispatch()->afterCommit()`は
`CreateAnalysisJobAction`と同じ既存パターンをそのまま再利用する。

### 9.1 Retry Recovery(column_mappingの再利用)

`column_mapping`は「AIが最初に提案し、Laravelが検証したMappingの
**write-once監査記録**」である。Manual Override後も、Queue retry後も
**一度保存されたら変更されない**。

**なぜこの契約が必要か**: `recordColumnMapping()`(Mapping AI成功直後)
と、`markAwaitingMappingConfirmation()`または`recordEffectiveMapping()`
(required判定確定後)の間には、理論上はごく短いが実際にexceptionが
起こり得るwindowが存在する(DB書き込み失敗等)。ここで例外が起きQueue
retryが走った場合、`effective_column_mapping`は未確定のままだが、
`column_mapping`は既に保存済み、という状態になる。

**再利用条件**: `ExecuteAnalysisJobAction`は、`effective_column_mapping`
が`null`の場合に**必ず`column_mapping`の有無を先に確認する**。

```text
effective_column_mapping !== null
  → 確定済みEffective Mappingをそのまま使う(Mapping AI呼ばない)

effective_column_mapping === null かつ column_mapping !== null
  → 既存column_mappingを「validated AI mapping」として再利用する
    (Mapping AIを再度呼ばない)

effective_column_mapping === null かつ column_mapping === null
  → 初回実行: Mapping AIを呼び、column_mappingを保存する(write-once)
```

**再利用後の処理**: 既存`column_mapping`(または初回のMapping AI結果)
のどちらであっても、その先の処理は完全に同一の1つの経路に合流する
— `BuildAnalysisTemplateColumnCandidatesAction`でcandidateを再構築し
(CSV再read、AI呼び出しなし)、`ResolveEffectiveColumnMappingAction`
(manual override=空)へ渡して**再検証**する。この再検証は「不要な
二重validationの追加」ではなく、自動確定パスが元々毎回行っていたのと
**全く同じ呼び出し**であり、retry recoveryのために新しく増やした
処理ではない(コード上も1つの分岐に統合されている)。この再検証で
`missing_required_fields`/`missing_required_field_groups`が判明する
ため、専用の判定ロジックを別途持つ必要もない。

- 不足あり → `markAwaitingMappingConfirmation()`(Mapping AI再Callなし)
- 不足なし → `recordEffectiveMapping()`して通常Pipelineへ合流

**`recordColumnMapping()`自体のwrite-once guard**: `column_mapping`が
既に設定されている状態で呼ばれた場合、値を上書きせず無視する
(warningログのみ)。`ExecuteAnalysisJobAction`は設計上このメソッドを
同一AnalysisJobへ二度呼ばない(既に値がある場合は呼び出し自体をskip
する)ため、このguardに実際に到達するのはコードの不具合時のみだが、
「最初のAI Mappingを永久に保持する」という契約を、たとえ将来の実装
ミスがあっても壊さないための最終防御として実装した。例外を投げる
案ではなく無視する案を採用した理由は、この不変条件の違反自体は
無害(値を保持するだけ)であり、それをJob失敗に変換する方が悪い
結果になるため。

**メリット**: Mapping AI再Callなし → OpenAIコスト削減、AIの非決定性
によるmapping変更が起きない、`column_mapping`監査記録が保持される、
retry時の再現性が向上する。

**Manual Mapping確定時は`column_mapping`を書き換えない**: これは
retry recoveryとは独立した既存の契約であり、今回の変更でも維持して
いる。`resumeAfterMappingConfirmation()`は`manual_column_mapping`と
`effective_column_mapping`のみを書き込み、`column_mapping`には一切
触れない。

---

## 10. Mapping Confirmationの必要条件

新しい「確信度十分/不十分」判定エンジンは実装していない。
`ValidateColumnMappingAction`が既に返す
`missing_required_fields`/`missing_required_field_groups`
(Phase 3-A以来無変更)をそのまま再利用する。

```text
自動続行:
  missing_required_fields === []
  かつ missing_required_field_groups === []
  (optional fieldがlow/unmapped/ambiguousでも影響しない)

Confirmation必須:
  missing_required_fields !== []
  または missing_required_field_groups !== []
```

「required fieldがambiguous」は既に`missing_required_fields`に
含まれる(`status !== 'mapped'`の一種のため)。新しい検出ロジックは
不要だった。

---

## 11. required不足がManual後も残る場合

```text
required revenue
↓
Userが未設定のままconfirm送信
↓
ResolveEffectiveColumnMappingActionのmissing_required_fields !== []
↓
recordManualMappingAttempt()(送信内容は保存し、入力を失わせない)
↓
AwaitingMappingConfirmationのまま
↓
Queue dispatchしない
↓
Mapping Preview画面へvalidation errorとして返す
```

`effective_column_mapping`はこの場合保存しない(前回の値もない状態を
維持)。

---

## 12. Candidate再構築(Mapping Preview)

`GET /projects/{project}/analysis-jobs/{analysisJob}/mapping`表示時、
Mapping AIは**呼ばない**。`DataProfilingAction`を再実行(CSV再streaming、
AI呼び出しなし)し、`BuildAnalysisTemplateColumnCandidatesAction`
(Phase 3-Cで`ResolveAnalysisTemplateAction`から抽出。Mapping Preview
とResolveAnalysisTemplateActionの両方から同じロジックを呼ぶため)で
候補を再構築する。

candidate情報自体はDB保存しない — `docs/product/DATA_PROFILING.md`
§39/§40の「Profileを永続化しない」既存方針をPhase 3-Cでも変更しない。

DataFileはAnalysisJob作成後、内容の差し替え機能を持たない(Phase 3-C
でも実装しない)ため、「表示時点のCSVが確定時と変わっている」という
競合は現状の設計では発生しない。

---

## 13. Route / Controller / FormRequest

```text
GET   /projects/{project}/analysis-jobs/{analysisJob}/mapping   editMapping
PATCH /projects/{project}/analysis-jobs/{analysisJob}/mapping   updateMapping
```

`AnalysisJobController`へ追加(新規Controller不要)。

`UpdateAnalysisJobMappingRequest`(新規FormRequest)は**HTTP shapeのみ**
検証する:

- `mapping`は配列
- 各keyはこのAnalysisJobのTemplateに実在するsemantic fieldのみ
  (`authorize()`でTemplate自体の有無・Project所属を先に検証。
  未知のfield名は`withValidator()`のafter hookで拒否)
- 各`column`は`nullable|string`

column実在性・型適合性・duplicate・required充足は一切FormRequestへ
入れず、`ResolveEffectiveColumnMappingAction`(= `ValidateColumnMappingAction`
の再利用)へ委ねる。

`authorize()`はroute model bindingされた`{project}`/`{analysisJob}`を
見て、Project所属とTemplate有無(`template_key !== null`)を検証する
(false→403)。これは`editMapping()`のcontroller body内404ガードより
早いタイミングで動く(FormRequestはcontroller bodyより先に解決される
ため)。

**クライアントから受け取るのは`{field: column}`のみ**。candidate一覧・
inferred_type・confidence・status・sourceは常にサーバー側で
再構築/決定し、クライアント入力を一切信用しない。

---

## 14. Security

| 懸念 | 対策 |
|---|---|
| 存在しないcolumn名 | `ValidateColumnMappingAction`の既存「unknown candidate → unmapped強制」で防御(新規コードなし) |
| 別DataFileの列 | column_candidatesは常にこのAnalysisJob自身のDataFileから再構築。クライアント送信のcandidate一覧は一切信用しない |
| 別ProjectのAnalysisJob | `UpdateAnalysisJobMappingRequest::authorize()` (403) |
| templateに存在しないsemantic field | `withValidator()`のafter hookで拒否 |
| Mass assignment | Controller/Actionが構造化して明示的に組み立てる。`$request->all()`を直接流し込まない |
| Free Analysisへのアクセス | `template_key === null`のとき`authorize()`がfalse(PATCH)/`abort_if`(GET)を返す |

---

## 15. Auditability

`effective_column_mapping`の各fieldは`source: 'ai' | 'manual'`を持つ。
これがPhase 3-Cで実装する監査情報の全てである。

`changed_by`/`changed_at`/`previous_column`は実装しない
(将来のAudit機能へ送る)。理由: 本アプリには現状、ルートへの認証
ミドルウェアが一切適用されておらず、「誰が変更したか」を記録する
土台(ログインユーザーの概念)がそもそも存在しない。`changed_at`は
`AnalysisJobDetail.updated_at`で概ね代替可能なため専用カラムを
追加していない。

---

## 16. API呼び出し回数

| ケース | Mapping | Planning | Analyze | 合計 |
|---|---|---|---|---|
| Free Analysis | - | 1 | 1 | 2 |
| Template(自動確定) | 1 | 1 | 1 | 3 |
| Template(手動確定、確認あり) | 1(初回のみ) | 1(再開時) | 1(再開時) | 3 |

上表は各ケースの**正常完了経路**(technical retryなし)における回数。
Mappingはcolumn_mapping永続化後、AnalysisJobのlifetime全体で最大1回
までしか呼ばれない一方、technical failureによるQueue retry(§17)が
発生した場合はPlanning/Analyzeがその都度再Callされるため、lifetime
合計はこの表の値を超えうる。

Mapping確認画面のGET表示・PATCH送信・Effective Mapping確定は、
いずれもLaravel処理のみでAI呼び出しを一切含まない。AIに
manual決定を判断させることはしない。

---

## 17. Failure Handling

| ケース | 挙動 |
|---|---|
| Mapping API timeout/refusal/malformed response | 従来通りQueue retry → 最終的にFailed(技術的failureとして扱う) |
| required不足 | 例外を投げない。AwaitingMappingConfirmationへ遷移し、Queueは正常終了(§8) |
| Mapping確認後のPlanning/Calculation/Analyze失敗 | 従来通りQueue retry → 最終的にFailed |
| 既にCompleted/Failed/Awaitingへの残存Queueメッセージ | no-op(§8) |
| Manual mapping validation error(required不足) | Awaitingのまま、dispatchなし(§11) |
| userが確認画面を2タブで開く/2重送信 | 2回目以降はalready_handledとして無視(§9) |

---

## 18. Backward Compatibility

- Free Analysis(`template_key === null`)は`AwaitingMappingConfirmation`
  へ構造的に到達できない(`ResolveAnalysisTemplateAction`のブロック
  自体を通らないため)。AI呼び出しは2回のまま、既存挙動は完全に無変更。
- `ad_performance`/`sales_analysis`とも、Sales固有・Ad固有の分岐は
  一切追加していない。判定は共有の`ValidateColumnMappingAction`出力
  のみに基づく。
- 既存の`column_mapping`列の意味・形状は変更していない。

---

## 19. Future Scope(Phase 3-C対象外)

- 「Mappingを確認してから分析」チェックボックス(毎回確認を強制する
  オプション)— 設計上の妨げは作っていない(`AwaitingMappingConfirmation`
  への遷移条件を「required不足」以外にも広げれば実現可能)
- AI Mapping再実行ボタン
- Mapping変更履歴(専用テーブル、`changed_by`/`changed_at`/`previous_column`)
- 同一DataFile+Templateでの過去Manual Mapping再利用
- DataFile schema hashによる再利用判定
- DataFile差し替え機能・schema変更検知
- Temporal Aggregation / YoY / MoM / WoW / Forecast(Phase 3-B方針を継続)

---

## 20. 検証状況

Browser E2E: **Case A / B / C 完了**。

- Case A(`ad_performance`、required全部mapped): Mapping確認なしで
  自動実行 → Completedを実ブラウザで確認
- Case B(`sales_analysis`、revenue不明瞭): Awaiting → Mapping Preview
  → `revenue`をユーザーが手動選択 → 確定 → Pending → Processing →
  Completedを実ブラウザで確認。あわせて、フォームが全field(未変更分含む)
  を送信しても`manual_column_mapping`には実際に変更したfieldのみが
  記録されることも確認・修正済み(§13参照)
- Case C(Manual Mappingで型不正columnを選択): `revenue`
  (`kind: measure`)のdropdownを実ブラウザのaccessibility treeで確認し、
  候補が`quantity`/`cost`(いずれもinteger/decimal)のみで、
  `product`(string)や`date`(temporal)が選択肢に一切出ないことを確認。
  `BuildAnalysisTemplateColumnCandidatesAction`の型フィルタが
  Mapping Preview UIへ正しく反映されているため、UI上で型不正な
  Manual Mappingはそもそも選択不可能。HTTPリクエスト改竄による
  型不正送信への防御は既存PHPUnit
  (`ResolveEffectiveColumnMappingActionTest`)で確認済み。

実OpenAI API E2E: sales_analysis、revenue不明瞭ケースで
Awaiting→Manual Override→Effective Mapping→Planning→Calculation→
Analyze→Completedをフル実行し、Planning/Final Analysis Contextの
column_mappingが完全一致することを確認済み。
