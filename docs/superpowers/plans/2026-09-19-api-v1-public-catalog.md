# T-071 API v1 Public Catalog Implementation Plan

Status: approved (2026-09-19)

1. Mark T-071 in progress and add a regression suite that specifies exact routes, public visibility, allowlisted DTO fields, pagination/filter validation, and the no-playback-URL invariant.
2. Run the new suite in CI and record the expected RED failure.
3. Implement pure public video, taxonomy, and episode presenters/DTOs.
4. Implement the catalog repository with the shared published-and-canonical predicate and parameterized filters.
5. Implement the catalog service and v1 catalog controller.
6. Extend the entrypoint bootstrap with exact GET route matching and 405 handling.
7. Register the regression suite in the PHP workflow and update source inventory documentation.
8. Run PHP, MySQL 5.7/8.0, and native-video verification; request independent code review and resolve all Critical/Important findings.
9. Mark T-071 done, advance T-072 to ready, and record immutable evidence in CURRENT_STATE.md.
