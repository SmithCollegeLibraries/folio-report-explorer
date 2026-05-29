# Clarification Learning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add structured clarification prompts for high-risk NL2SQL ambiguity and persist user choices so repeated patterns can be promoted into training hints.

**Architecture:** Add a focused backend clarification service that detects deterministic ambiguities before model SQL generation. Return the existing `needsClarification` response shape with richer options, store selected resolutions in a new MySQL table, and let the frontend rerun the clarified prompt after the user chooses an option or enters free text.

**Tech Stack:** Yii2/PHP backend, MySQL migrations, React/Vite frontend, existing PHP script tests and Vitest frontend tests.

---

### Task 1: Backend Clarification Detection

**Files:**
- Create: `backend/services/ClarificationService.php`
- Modify: `backend/services/GeminiService.php`
- Test: `backend/tests/ClarificationServiceTest.php`

- [ ] Write failing tests for MRBC ambiguity, MRBC Reference, MRBC Collection, and `5 Collegse` normalization.
- [ ] Implement `ClarificationService::detectPromptAmbiguity(string $prompt): ?array`.
- [ ] Call it at the start of `GeminiService::generateSqlWithShadow`.
- [ ] Verify `php backend/tests/ClarificationServiceTest.php`.

### Task 2: Clarification Event Storage

**Files:**
- Modify: `mysql/init.sql`
- Create: `mysql/migrations/030_ai_clarification_events.sql`
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/config/web.php`
- Test: `backend/tests/ClarificationEventSchemaTest.php`

- [ ] Write failing schema test requiring `ai_clarification_events` in init and migration.
- [ ] Add table with original question, clarification key, options JSON, selected options JSON, free text, resolved filter JSON, generated SQL, status, promoted hint ID, user ID, and timestamps.
- [ ] Add `POST /api/clarifications/resolve` to persist a user resolution.
- [ ] Verify `php backend/tests/ClarificationEventSchemaTest.php`.

### Task 3: Frontend Clarification UI

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/Ask.tsx`

- [ ] Extend `NlResponse` with clarification metadata and option types.
- [ ] Render clarification cards with recommended single-choice options and a free-text fallback.
- [ ] On selection, persist the clarification event and rerun Ask AI with a clarified prompt suffix.
- [ ] Verify `npm test -- Ask.errorFormatting.test.ts` or the closest available frontend test command.
