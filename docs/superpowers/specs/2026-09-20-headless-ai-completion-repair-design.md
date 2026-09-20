# MACCMS Headless AI Completion Repair Design

日期：2026-09-20  
狀態：待使用者書面審閱  
目標分支：`feature/headless-ai-v1`  
基準提交：`151b7587042c90fc69d1e45f47ee2c07c5c9feeb`

## 1. 背景與目標

本修復不重寫現有 MACCMS Headless AI 擴充層，而是完成已存在但尚未串接的功能。最終必須讓全新安裝後的影片可從原生新增、採集或匯入 API，完整通過：

1. 建立擴充資料與背景工作；
2. AI 整理、多語翻譯及分類建議；
3. 正式詞典映射與人工審核；
4. 重複候選、人工合併及完整還原；
5. TMDB 搜尋、候選選擇及資料補全；
6. 最終人工驗證與發布；
7. 由 `/api/v1` 讀取已發布資料。

不得破壞 MACCMS 原生影片、採集、會員、播放及管理員權限功能。系統不得自動合併影片，也不得覆蓋人工鎖定欄位。

## 2. 修復原則

- 保留現有 migrations、`vod_ext`、`content_job`、AI run、TMDB review、duplicate candidate、merge snapshot 及 API v1 資料結構。
- 新增集中式工作流協調服務，不把狀態轉移散落在控制器。
- 所有大量或外部操作仍由 MySQL 任務佇列與短時 Cron 執行。
- 自動流程可提出候選及推進安全階段；合併、TMDB 採用、人工欄位覆蓋與發布仍須管理員確認。
- 詞典關聯是分類真實來源；原生 `vod_area`、`vod_class`、`vod_tag` 是相容投影。
- 每項修復必須先有失敗測試，再實作至通過，並獨立提交。

## 3. 工作流協調

新增 `ContentWorkflowCoordinator`，負責合法狀態轉移、階段完成時間及下一工作入隊。唯一允許的主流程為：

```text
imported
  -> ai_processing
  -> duplicate_review
  -> tmdb_matching
  -> manual_review
  -> published
```

旁路狀態：

- AI 或 TMDB 終止失敗：`failed`
- 人工拒絕：`rejected`
- 重複合併副本：`merged`
- 從 `failed` 重試時回到失敗階段的安全入口。

### 3.1 建立影片

原生管理員新增、採集新增及匯入 API 成功建立影片後：

- 保證 `vod_ext` 存在；
- 以 `video:<vod_id>:ai:<content_fingerprint>` 建立冪等 AI 工作；
- 更新狀態為 `ai_processing`；
- 原生更新若沒有影響 AI 輸入，不重複入隊；
- 人工只修改播放來源時不得清除 AI/TMDB 結果。

### 3.2 AI 完成

AI 結果通過 schema 驗證後：

- 保存所有候選、模型、Prompt 版本、Token 與可用的供應商用量；
- 建立分類建議，但不直接建立無限制新詞；
- 執行重複候選偵測；
- 更新 `ai_completed_at`；
- 狀態轉為 `duplicate_review`。

若沒有達門檻的重複候選，可安全進入 `tmdb_matching` 並建立 TMDB 工作。若有候選，等待管理員標示不同作品或完成合併。

### 3.3 重複審核

- 所有候選標示為不同作品後，主影片可進入 `tmdb_matching`。
- 完成合併後，主影片進入 `tmdb_matching`，副影片進入 `merged`。
- 還原後恢復兩部影片的合併前狀態與後續工作資格。
- 不允許 HTTP 請求內直接呼叫 AI 或 TMDB。

### 3.4 TMDB 與人工審核

- TMDB 工作保存候選後維持 `tmdb_matching`。
- 管理員選擇候選或明確標示無匹配後，更新 `tmdb_completed_at` 並進入 `manual_review`。
- AI 欄位與分類審核必須在最終發布前完成；拒絕個別非必要欄位不阻止流程，但所有待決策項目必須關閉。
- 發布服務繼續要求 `manual_review`、revision 一致、必填資料完整及明確確認。

## 4. 正式分類與詞典映射

AI 與 TMDB 不再直接把分類結果視為正式關聯。

新增 `TaxonomySuggestionService`：

- 將 AI/TMDB 的 region、genre、tag 名稱以 slug、三語名稱及同義詞比對現有啟用詞條；
- 唯一匹配的既有詞條可成為候選；
- 無匹配或多重匹配項目進入人工分類審核；
- 不自動建立 `meta_term`；
- 管理員批准後以 `VodExtensionService::replaceTerms()` 寫入 `vod_meta_term`；
- 完成後呼叫 `syncNativeTaxonomy()` 生成原生相容字串；
- 人工已鎖定的分類不得由後台工作覆蓋。

AI 欄位審核介面增加 region、genre、tag 的詞條候選顯示。原本直接產生的 `vod_area`、`vod_class`、`vod_tag` 字串僅作遷移期間預覽，不再作正式分類完成依據。

## 5. 完整重複合併與還原

`DuplicateMergeService` 在同一資料庫交易中處理：

- 主／副影片播放來源與集數去重合併；
- 副影片別名、舊片名合併到主影片；
- `content_lang`：缺少語系補入；衝突時保留主影片並在快照記錄；
- `vod_meta_term`：聯集去重；
- `ext_source_map`：不衝突的外部識別碼移到主影片；衝突保留於快照供人工辨識；
- 需要保留的來源資料與欄位 provenance；
- 副影片標記 `merged` 並設定 canonical 主影片；
- 候選決策及審計事件。

快照格式升級為 version 2，包含所有會變動的主／副資料及關聯列。還原必須：

- 驗證快照 hash；
- 驗證合併後資料沒有未預期變更；
- 還原 `vod`、`vod_ext`、多語、詞典關聯、外部映射及欄位狀態；
- 重開候選；
- 產生還原審計；
- 對既有 version 1 快照保持可讀與原有還原能力。

## 6. 自動查重策略

- 仍採確定性評分，不使用生成式模型決定合併。
- 門檻及最大候選掃描量改為後台設定。
- 優先以 TMDB ID、年份、媒體類型及正規化片名縮小候選集合。
- 不以固定「最近 2,000 筆」作唯一搜尋範圍。
- AI 完成、TMDB ID 採用或影響身份的人工欄位變更時，失效舊候選並重新查重。
- 系統只自動建立候選，永不自動合併。

## 7. AI 設定與用量

後台 AI 設定補充：

- Prompt 版本；
- schema 驗證／供應商失敗最大重試次數；
- 重試基礎延遲；
- 查重門檻及候選上限；
- 自動採用空白欄位開關；
- 每日預算。

用量策略：

- 若供應商回傳 Token 用量，保存真實值；
- 未提供時才使用明確標記的估算值；
- 費用由可配置的每百萬 input/output Token 單價計算；
- 不再固定寫入 `estimated_cost_micros = 0`；
- 預算耗盡時工作安全失敗／延後，不呼叫供應商。

API Key 不回填 HTML，不寫入日誌或錯誤摘要。

## 8. 後台補全

智能內容首頁增加：

- AI 欄位／分類審核入口；
- 待處理影片入口；
- 清楚顯示目前階段與下一動作。

工作管理增加：

- 暫停 queued 工作；
- 恢復 paused 工作；
- 略過仍未執行的工作並記錄原因；
- 重新入隊；
- 依明確 ID 批量操作；
- 所有動作維持權限、CSRF、二次確認及審計。

設定整合現有 MACCMS 設定頁，不建立第二套設定中心。

## 9. API 補全

### 9.1 影片匯入 API

新增受獨立憑證保護的 `POST /api/v1/import/videos`：

- 不使用會員 Access Token；
- 使用獨立 HMAC 或高熵 Bearer 憑證；
- 支援冪等鍵；
- 驗證 allowlist 欄位、播放 URL 及 payload 大小；
- 寫入原生 `vod` 相容資料；
- 建立 `vod_ext` 與 AI 工作；
- 回傳 `public_id`、工作 ID 及穩定結果；
- 不接受直接發布或直接合併。

### 9.2 People API

新增 `GET /api/v1/people/{slug}`，沿用原生演員／導演資料或既有可安全重用來源，只輸出已發布影片關聯。若目前資料沒有穩定 slug，使用確定性六位公開 slug 映射，不暴露內部 ID。

### 9.3 Site Config API

新增 `GET /api/v1/site-config`，只輸出白名單公開設定，例如站名、公開 Logo、支援語系與功能旗標。不得輸出資料庫、郵件、AI、TMDB、S3、JWT 或播放簽名秘密。

三個端點須加入路由、DTO、限流、CORS、OpenAPI 與回歸測試。

## 10. 相容與遷移

新增 migration 只能向前增加必要欄位／索引／審核表，不修改已套用 migration checksum。

現有資料修復命令：

```bash
php think maccms:repair-content --dry-run
php think maccms:repair-content --apply
```

用途：

- 找出沒有有效 workflow 狀態的影片；
- 將已有 AI/TMDB 結果但狀態停留在 imported 的影片修正到安全階段；
- 將可唯一映射的原生分類字串建立為候選，不自動建立詞條；
- 為需要重新處理的影片建立冪等工作；
- 輸出計數與錯誤，不輸出秘密。

`--apply` 需要明確確認選項；命令可重複執行且不產生重複工作或關聯。

## 11. 測試與驗收

每項任務使用 RED→GREEN，最後必須通過：

1. PHP 8.1 syntax 與全部 regression；
2. MySQL 5.7／8.0 migration、工作流、分類、合併／還原整合；
3. 原生影片新增／更新、採集新增／更新及播放無損回歸；
4. 完整 API v1 contract 與 OpenAPI；
5. 禁止官方出站與 SSRF/security 回歸；
6. RC rollback rehearsal；
7. 全新 Web 安裝人工 smoke。

新增端到端整合案例：

- 建立影片後只生成一個 AI 工作；
- AI 完成後產生分類建議與重複候選；
- 無重複或人工處理後進入 TMDB；
- TMDB 選擇後進入 manual review；
- 分類關聯批准並同步原生欄位；
- 發布成功後 API 可讀；
- 合併後 canonical 正確，多語／分類／外部映射保留；
- 還原後所有資料與合併前快照一致；
- 重跑整條流程不建立重複工作；
- 人工鎖定值不被 AI、TMDB 或 repair 命令覆蓋。

## 12. 發布與文件

- 更新 `tasks.md` 與 `CURRENT_STATE.md`，新增完成修復 checkpoint，不覆寫舊版本歷史。
- 更新 `使用說明.md`、部署文件、Cron、匯入 API 及故障排查。
- `headless-ai-v1.0.2` 保持不可變，只作歷史追蹤。
- 自動測試全綠後建立 `headless-ai-v1.0.3-rc1` 候選包。
- 只有全新 Web 安裝及瀏覽器 smoke 全部通過，才可建立正式 `headless-ai-v1.0.3`。

## 13. 非目標

- 不建立 Next.js 前端。
- 不處理文章、漫畫或圖片 AI 管線。
- 不引入 Redis、Docker、常駐 worker 或向量資料庫。
- 不自動合併影片。
- 不自動建立無限制分類詞條。
- 不重寫 MACCMS 原生會員或播放模型。
