<?php

namespace app\services;

class AcrlQuestionAssignmentRepository
{
    public function __construct($db = null) {}

    public function isPublishedYear(int $year) {}

    public function questions(int $year, ?string $sheet = null) {}

    public function question(int $year, string $sheet, string $questionId, string $part) {}

    public function assignments(int $year) {}

    public function approvedLinks(int $previousYear, int $currentYear) {}

    public function replaceQuestionAssignments(int $year, string $sheet, string $questionId, string $part, array $userIds, int $assignedBy) {}

    public function insertQuestionAssignments(array $rows) {}
}
