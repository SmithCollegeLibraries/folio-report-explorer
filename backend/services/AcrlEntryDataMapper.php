<?php

namespace app\services;

/** Shared tuple and answer mapping for ACRL entry reads and writes. */
class AcrlEntryDataMapper
{
    public static function questionKey(string $sheet, string $questionId, string $part): string
    {
        return $sheet . ':' . $questionId . ':' . $part;
    }

    public static function calculationKey(string $sheet, string $questionId, string $part): string
    {
        return $sheet . '|' . $questionId . '|' . $part;
    }

    public static function assignmentQuestionKey(string $sheet, string $questionId, string $part): string
    {
        return serialize([$sheet, $questionId, $part]);
    }

    public static function answerMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[self::questionKey($row['sheet'], $row['question_id'], $row['part'])] = $row;
        }
        return $map;
    }

    public static function emptyAnswer(): array
    {
        return [
            'value_text' => null,
            'value_number' => null,
            'note' => null,
            'source' => null,
            'source_label' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }
}
