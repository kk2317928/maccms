# T-072 API v1 Locale and Canonical Redirect Implementation Plan

Status: approved under the established automatic-execution policy (2026-09-19)

1. Mark T-072 in progress and add a RED regression contract for locale normalization, fallback order, response metadata, canonical 308 redirects, unpublished-target fail-closed behavior, and the T-071 detail-field constant regression.
2. Implement the pure `ApiV1Locale` value object.
3. Make video and taxonomy DTO display fields locale-aware while preserving multilingual maps.
4. Extend the catalog service with injectable canonical resolution and published-target validation.
5. Add controller locale propagation and stable relative 308 responses for detail and episode aliases.
6. Reconcile source inventory and workflow lint coverage.
7. Run PHP, MySQL 5.7, MySQL 8.0, and native-video verification.
8. Request independent review, fix all Critical/Important findings with RED→GREEN coverage, then mark T-072 done and T-073 ready.
