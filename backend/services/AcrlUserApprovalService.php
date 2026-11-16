<?php

namespace app\services;

use app\models\User;
use Yii;

require_once __DIR__ . '/AcrlInstitutionIdentityService.php';

/** Applies approval transitions and grants the initial Smith membership atomically. */
class AcrlUserApprovalService
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    public function setApproved(int $userId, bool $approved): ?User
    {
        $transaction = $this->db->beginTransaction();
        try {
            $suffix = $this->db->driverName === 'sqlite' ? '' : ' FOR UPDATE';
            $row = $this->db->createCommand('SELECT id, is_approved, email FROM users WHERE id = :id' . $suffix, [':id' => $userId])->queryOne();
            if (!$row) {
                $transaction->commit();
                return null;
            }
            $wasApproved = (int)$row['is_approved'] === 1;
            if ($wasApproved !== $approved) {
                $user = User::findOne($userId);
                $user->is_approved = $approved ? 1 : 0;
                if (!$user->save(false)) throw new \RuntimeException('Could not save user approval.');
                if (!$wasApproved && $approved
                    && AcrlInstitutionIdentityService::collegeForEmail($row['email'] === null ? null : (string)$row['email']) === 'SC') {
                    $sql = $this->db->driverName === 'sqlite'
                        ? "INSERT INTO acrl_institution_members (user_id, institution_code, role, granted_by, granted_at)"
                            . " SELECT :id, 'SC', 'member', NULL, :grantedAt WHERE NOT EXISTS"
                            . " (SELECT 1 FROM acrl_institution_members WHERE user_id = :id AND institution_code = 'SC')"
                        : "INSERT IGNORE INTO acrl_institution_members (user_id, institution_code, role, granted_by, granted_at)"
                            . " VALUES (:id, 'SC', 'member', NULL, :grantedAt)";
                    $this->db->createCommand($sql, [':id' => $userId, ':grantedAt' => date('Y-m-d H:i:s')])->execute();
                }
            }
            $transaction->commit();
            return User::findOne($userId);
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }
}
