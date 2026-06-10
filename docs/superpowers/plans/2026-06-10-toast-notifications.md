# Toast Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a reusable toast notification system and use it for Ask AI feedback and execution errors.

**Architecture:** A `ToastProvider` owns toast state and exposes `useToast()` for feature code. `App` mounts the provider once. `Ask.tsx` keeps inline error context, but also emits concise success/error toasts for user-triggered feedback and query execution failures.

**Tech Stack:** React 18, TypeScript, Tailwind CSS, Vitest, Testing Library.

---

### Task 1: Toast Provider

**Files:**
- Create: `frontend/src/components/ToastProvider.tsx`
- Test: `frontend/src/components/ToastProvider.test.tsx`
- Modify: `frontend/src/App.tsx`

- [ ] Write a failing test that renders a component inside `ToastProvider`, calls `toast.success('Feedback saved')`, and expects an element with role `status` containing `Feedback saved`.
- [ ] Implement `ToastProvider`, `useToast`, automatic dismissal, and manual close buttons.
- [ ] Wrap `App` routes with `ToastProvider`.
- [ ] Run `npm test -- ToastProvider`.

### Task 2: Ask AI Error Formatting

**Files:**
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`

- [ ] Write a failing test for `formatExecutionError('SQLSTATE[22003]: Numeric value out of range: 7 ERROR: value "4253292441626" is out of range for type integer')`.
- [ ] Implement numeric-overflow detection and a readable message that explains the integer cast overflow.
- [ ] Run `npm test -- Ask.errorFormatting.test.ts`.

### Task 3: Ask AI Toast Wiring

**Files:**
- Modify: `frontend/src/pages/Ask.tsx`

- [ ] Import `useToast`.
- [ ] Emit success toast when `feedbackMut` succeeds.
- [ ] Emit error toast when `feedbackMut` fails.
- [ ] Emit error toast when query submit or job execution fails.
- [ ] Keep inline messages for detailed context.
- [ ] Run focused tests and `npm run build`.
