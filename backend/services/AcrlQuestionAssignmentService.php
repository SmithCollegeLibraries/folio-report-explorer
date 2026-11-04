<?php

namespace app\services;

class AcrlQuestionAssignmentService
{
    public function __construct($repository = null, $users = null) {}

    public function saveQuestion(int $year, string $sheet, string $questionId, string $part, array $userIds, string $assignedBy) {}

    public function listForYear(int $year) {}

    public function forEntry(int $year, ?string $userId) {}

    public function copyFromPrevious(int $year, string $assignedBy) {}

    public function copyOnPublish(int $year, string $assignedBy) {}
}
