<?php

use app\models\User;

acrlInviteServiceScenario('accept matches only current Shibboleth identity and rejects unusable invites', function () use ($service, $db) {
    acrlInviteServiceInsertInvite(200, 'newuser@smith.edu');
    acrlInviteServiceInsertTarget(200, 'section', 'Circulation');
    $eppnUser = User::findOne(5);
    $acceptedByEppn = acrlInviteServiceCall(function () use ($service, $eppnUser) { return $service->acceptOnSignIn($eppnUser, 'NEWUSER@SMITH.EDU', 'unrelated'); }, 'EPPN invite acceptance');
    acrlInviteServiceCheck($acceptedByEppn === true, 'EPPN match is case-insensitive and accepts the invite.');
    acrlInviteServiceCheck((int)User::findOne(5)->is_approved === 1, 'Accepted new user is approved through the ActiveRecord model.');
    acrlInviteServiceCheck((string)User::findOne(5)->role === 'user', 'Invite acceptance leaves the user role unchanged.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 5 AND institution_code = \'SC\'')->queryScalar() === 'member', 'A usable invite grants SC membership in the acceptance transaction.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 200')->queryScalar() === 'accepted', 'Matched invite is marked accepted.');
    acrlInviteServiceExpectError(function () use ($service) { $service->revokeInvite(200, acrlInviteServiceAdmin()); }, 'no longer pending', 'revoke after acceptance');
    acrlInviteServiceCheck((int)User::findOne(5)->is_approved === 1, 'A revoke attempt cannot undo an approval already granted by acceptance.');

    acrlInviteServiceInsertInvite(201, 'newuid@smith.edu');
    acrlInviteServiceInsertTarget(201, 'question', 'Library services', 'survey', '61', '');
    $uidUser = new User(['smith_id' => 'synthetic-uid-user', 'username' => 'newuid', 'role' => 'user', 'is_approved' => 0]);
    $uidUser->save(false);
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $uidUser) { return $service->acceptOnSignIn($uidUser, null, 'newuid'); }, 'UID invite acceptance') === false, 'UID-only matching does not accept an invite when eppn is absent.');
    acrlInviteServiceCheck((int)User::findOne($uidUser->id)->is_approved === 0, 'UID-only sign-in does not approve the invitee.');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $uidUser) { return $service->acceptOnSignIn($uidUser, 'newuid@amherst.edu', 'newuid'); }, 'Equal uid across colleges') === false, 'A Smith invite does not match a same-uid Amherst sign-in.');
    acrlInviteServiceInsertInvite(207, 'newuid@amherst.edu');
    acrlInviteServiceInsertTarget(207, 'question', 'Library services', 'survey', '61', '');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $uidUser) { return $service->acceptOnSignIn($uidUser, 'newuid@amherst.edu', 'newuid'); }, 'Legacy out-of-domain invite') === true, 'A pre-existing out-of-domain invite remains acceptable by exact full eppn.');

    acrlInviteServiceInsertInvite(216, 'reverseuid@amherst.edu');
    acrlInviteServiceInsertTarget(216, 'section', 'Circulation');
    $reverseUidUser = new User(['smith_id' => 'synthetic-reverse-uid-user', 'username' => 'reverseuid', 'role' => 'user', 'is_approved' => 0]);
    $reverseUidUser->save(false);
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $reverseUidUser) { return $service->acceptOnSignIn($reverseUidUser, 'reverseuid@smith.edu', 'reverseuid'); }, 'Reverse equal uid across colleges') === false,
        'An Amherst invite does not match a same-uid Smith sign-in.');
    acrlInviteServiceCheck((int)User::findOne($reverseUidUser->id)->is_approved === 0
        && (string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 216')->queryScalar() === 'pending',
        'The reverse cross-college uid mismatch leaves the account unapproved and invite pending.');

    acrlInviteServiceInsertInvite(202, 'old-address@example.test');
    acrlInviteServiceInsertTarget(202, 'section', 'Circulation');
    $storedEmailUser = User::findOne(9);
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $storedEmailUser) { return $service->acceptOnSignIn($storedEmailUser, 'different@smith.edu', 'different'); }, 'Stored-email mismatch') === false, 'Stored users.email is ignored when matching sign-in.');

    acrlInviteServiceInsertInvite(203, 'nobody@smith.edu');
    acrlInviteServiceInsertTarget(203, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(10), 'another@smith.edu', 'another'); }, 'No invite match') === false, 'A non-matching sign-in leaves invite and user unchanged.');
    acrlInviteServiceInsertInvite(204, 'expired-user@smith.edu', 'pending', 1, date('Y-m-d H:i:s', time() - 1));
    acrlInviteServiceInsertTarget(204, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(11), 'expired-user@smith.edu', 'expired-user'); }, 'Expired invite') === false, 'Expired pending invite is ignored.');
    acrlInviteServiceInsertInvite(205, 'revoked-user@smith.edu', 'revoked');
    acrlInviteServiceInsertTarget(205, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(12), 'revoked-user@smith.edu', 'revoked-user'); }, 'Revoked invite') === false, 'Revoked invite is ignored.');
    acrlInviteServiceInsertInvite(206, 'no-target@smith.edu');
    acrlInviteServiceInsertTarget(206, 'question', 'Removed section', 'survey', 'missing', '');
    $before = (int)User::findOne(13)->is_approved;
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(13), 'no-target@smith.edu', 'no-target'); }, 'Invite without usable targets') === false, 'Invite with no current published target is not accepted.');
    acrlInviteServiceCheck((int)User::findOne(13)->is_approved === $before && $before === 0, 'Invite with no usable target does not approve the new user.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 206')->queryScalar() === 'pending', 'Invite with no usable target remains pending.');
});

acrlInviteServiceScenario('accepts a question target after republishing moves its catalogue section', function () use ($service, $db) {
    $db->createCommand()->insert('acrl_questions', [
        'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'ACR29MOVE', 'part' => '',
        'section' => 'Original section', 'prompt' => 'Synthetic moved question', 'survey_order' => 99,
    ])->execute();
    acrlInviteServiceInsertInvite(214, 'moved-question@smith.edu');
    acrlInviteServiceInsertTarget(214, 'question', 'Original section', 'survey', 'ACR29MOVE', '');
    $invitee = new User(['smith_id' => 'synthetic-moved-question-user', 'username' => 'moved-question', 'role' => 'user', 'is_approved' => 0]);
    $invitee->save(false);
    $db->createCommand()->update('acrl_questions', ['section' => 'Republished section'], [
        'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'ACR29MOVE', 'part' => '',
    ])->execute();

    $accepted = acrlInviteServiceCall(function () use ($service, $invitee) {
        return $service->acceptOnSignIn($invitee, 'moved-question@smith.edu', 'moved-question');
    }, 'Acceptance after catalogue section move');
    acrlInviteServiceCheck($accepted === true, 'A question invite remains usable when only the catalogue section changes.');
    acrlInviteServiceCheck((int)User::findOne($invitee->id)->is_approved === 1, 'A question invite with the unchanged tuple approves the invitee after the section move.');
    acrlInviteServiceCheck((int)$db->createCommand(
        'SELECT COUNT(*) FROM acrl_question_assignments WHERE survey_year = 2026 AND sheet = :sheet'
            . ' AND question_id = :question AND part = :part AND user_id = :user',
        [':sheet' => 'survey', ':question' => 'ACR29MOVE', ':part' => '', ':user' => $invitee->id]
    )->queryScalar() === 1, 'Acceptance assigns the current catalogue question tuple exactly once.');
});

acrlInviteServiceScenario('accept preserves preassigned targets across overlapping pending invites', function () use ($service, $db) {
    acrlInviteServiceInsertInvite(210, 'additive@smith.edu');
    acrlInviteServiceInsertTarget(210, 'section', 'Circulation');
    acrlInviteServiceInsertTarget(210, 'question', 'Circulation', 'survey', '60', 'A');
    acrlInviteServiceInsertInvite(213, 'additive@smith.edu');
    acrlInviteServiceInsertTarget(213, 'section', 'Circulation');
    acrlInviteServiceInsertTarget(213, 'question', 'Circulation', 'survey', '60', 'A');
    acrlInviteServiceInsertTarget(213, 'section', 'Library services');
    $db->createCommand()->insert('acrl_section_assignments', ['institution_code' => 'SC', 'survey_year' => 2026, 'section' => 'Circulation', 'user_id' => 6, 'assigned_by' => 1])->execute();
    $db->createCommand()->insert('acrl_question_assignments', ['institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 6, 'assigned_by' => 1])->execute();
    $invitee = new User(['smith_id' => 'synthetic-additive-user', 'username' => 'additive', 'role' => 'user', 'is_approved' => 0]);
    $invitee->save(false);
    $db->createCommand()->insert('acrl_section_assignments', ['institution_code' => 'SC', 'survey_year' => 2026, 'section' => 'Circulation', 'user_id' => $invitee->id, 'assigned_by' => 1])->execute();
    $db->createCommand()->insert('acrl_question_assignments', ['institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => $invitee->id, 'assigned_by' => 1])->execute();
    $before = $db->createCommand(
        'SELECT section, user_id, assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section'
            . ' AND user_id IN (6, :invitee) ORDER BY section, user_id',
        [':section' => 'Circulation', ':invitee' => $invitee->id]
    )->queryAll();
    $accepted = acrlInviteServiceCall(function () use ($service, $invitee) { return $service->acceptOnSignIn($invitee, 'additive@smith.edu', 'additive'); }, 'Overlapping pending invite acceptance');
    acrlInviteServiceCheck($accepted === true, 'A pending invite is accepted even when both target assignments already exist.');
    acrlInviteServiceCheck((int)$db->createCommand("SELECT COUNT(*) FROM acrl_invites WHERE id IN (210, 213) AND status = 'accepted'")->queryScalar() === 2, 'Both overlapping pending invites are accepted.');
    $sectionRows = $db->createCommand(
        'SELECT user_id, assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section'
            . ' AND user_id IN (:existingUser, :invitee) ORDER BY user_id',
        [':section' => 'Circulation', ':existingUser' => 6, ':invitee' => $invitee->id]
    )->queryAll();
    $questionRows = $db->createCommand(
        'SELECT user_id, assigned_by FROM acrl_question_assignments WHERE survey_year = 2026 AND question_id = :question'
            . ' AND user_id IN (:existingUser, :invitee) ORDER BY user_id',
        [':question' => '60', ':existingUser' => 6, ':invitee' => $invitee->id]
    )->queryAll();
    acrlInviteServiceCheck(count($sectionRows) === 2 && count($questionRows) === 2
        && (int)$sectionRows[1]['user_id'] === (int)$invitee->id && (int)$questionRows[1]['user_id'] === (int)$invitee->id,
        'Overlapping invites create no duplicates and retain exactly one invitee row for each already-held target kind.');
    acrlInviteServiceCheck((int)($sectionRows[0]['user_id'] ?? 0) === 6 && (int)($questionRows[0]['user_id'] ?? 0) === 6, 'Existing section and question assignees are not replaced.');
    $after = $db->createCommand(
        'SELECT section, user_id, assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section'
            . ' AND user_id IN (6, :invitee) ORDER BY section, user_id',
        [':section' => 'Circulation', ':invitee' => $invitee->id]
    )->queryAll();
    acrlInviteServiceCheck($before === $after, 'Existing section assignments remain unchanged after accepting overlapping invites.');
    $addedSectionActor = $db->createCommand(
        'SELECT assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section AND user_id = :invitee',
        [':section' => 'Library services', ':invitee' => $invitee->id]
    )->queryScalar();
    acrlInviteServiceCheck((int)$addedSectionActor === 1, 'A newly added target records the invite creator as assigned_by.');
    acrlInviteServiceCheck((string)User::findOne($invitee->id)->role === 'user', 'Acceptance never changes the role.');

    acrlInviteServiceInsertInvite(211, 'deleted-admin@smith.edu', 'pending', 7);
    acrlInviteServiceInsertTarget(211, 'section', 'Library services');
    User::deleteAll(['id' => 7]);
    $deletedInviterUser = new User(['smith_id' => 'synthetic-deleted-inviter-invitee', 'username' => 'deleted-admin', 'role' => 'user', 'is_approved' => 0]);
    $deletedInviterUser->save(false);
    acrlInviteServiceCall(function () use ($service, $deletedInviterUser) { return $service->acceptOnSignIn($deletedInviterUser, 'deleted-admin@smith.edu', 'deleted-admin'); }, 'Acceptance after inviter deletion');
    $assignedBy = $db->createCommand('SELECT assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section AND user_id = :user', [':section' => 'Library services', ':user' => $deletedInviterUser->id])->queryScalar();
    acrlInviteServiceCheck($assignedBy === null, 'Deleted invite creator produces NULL assigned_by on accepted assignments.');

    acrlInviteServiceInsertInvite(212, 'already@smith.edu');
    acrlInviteServiceInsertTarget(212, 'section', 'Trends');
    $approvedAdmin = User::findOne(8);
    acrlInviteServiceCall(function () use ($service, $approvedAdmin) { return $service->acceptOnSignIn($approvedAdmin, 'already@smith.edu', 'approved-admin'); }, 'Already-approved acceptance');
    acrlInviteServiceCheck((int)User::findOne(8)->is_approved === 1 && (string)User::findOne(8)->role === 'admin', 'Already-approved user keeps approval and role while receiving targets.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 8 AND institution_code = \'SC\'')->queryScalar() === 'admin', 'Invite acceptance preserves an existing college-admin membership.');
    acrlInviteServiceCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section AND user_id = 8', [':section' => 'Trends'])->queryScalar() === 1, 'Already-approved user receives invite targets.');
});

acrlInviteServiceScenario('membership grant failure rolls invite acceptance back', function () use ($service, $db) {
    acrlInviteServiceInsertInvite(215, 'rollback@smith.edu');
    acrlInviteServiceInsertTarget(215, 'section', 'Circulation');
    $invitee = new User(['id' => 9999, 'smith_id' => 'synthetic-rollback-user', 'username' => 'rollback', 'email' => 'rollback@smith.edu', 'role' => 'user', 'is_approved' => 0]);
    $invitee->save(false);
    $error = null;
    try {
        $service->acceptOnSignIn($invitee, 'rollback@smith.edu', 'rollback');
    } catch (Throwable $caught) {
        $error = $caught;
    }
    acrlInviteServiceCheck($error !== null && (int)User::findOne(9999)->is_approved === 0
        && (string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 215')->queryScalar() === 'pending'
        && (int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 9999')->queryScalar() === 0,
        'Membership insertion failure rolls approval, assignments, and accepted status back together.');
});
