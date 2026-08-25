<?php
/**
 * BL+ Learner Context Endpoint
 * ------------------------------------------------------------
 * Location:
 *   /moodle/local/aichat/context.php
 *
 * Returns Moodle-aware context for the currently logged-in user:
 *   - Moodle user ID
 *   - full name
 *   - Moodle roles
 *   - normalized role
 *   - enrolled courses
 *   - activity completion
 *   - course progress
 *   - current course/activity
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

header('Content-Type: application/json');

global $USER, $DB, $CFG;
define(
    'AICHAT_CONTEXT_SECRET',
    'hheennookkffrreehhuunnttssiiggee'
);
/*
 * ------------------------------------------------------------
 * 1. GET CURRENT USER
 * ------------------------------------------------------------
 */

$userid = (int)$USER->id;

/*
 * ------------------------------------------------------------
 * 2. DETERMINE USER ROLES
 * ------------------------------------------------------------
 *
 * We use Moodle's role assignments instead of trusting
 * anything from the browser.
 */

$roles = [];

$roleRecords = $DB->get_records_sql(
    "
    SELECT DISTINCT
        r.id,
        r.shortname,
        r.name
    FROM {role_assignments} ra
    JOIN {role} r ON r.id = ra.roleid
    WHERE ra.userid = ?
    ",
    [$userid]
);

foreach ($roleRecords as $role) {
    $roles[] = [
        'shortname' => $role->shortname,
        'name' => $role->name,
    ];
}

/*
 * ------------------------------------------------------------
 * 3. NORMALIZE ROLE
 * ------------------------------------------------------------
 *
 * Moodle can have several role names.
 *
 * We convert them into a small set that the AI can understand.
 */

$normalizedRole = 'other';

$roleShortnames = array_map(function($role) {
    return strtolower($role['shortname']);
}, $roles);

if (
    in_array('manager', $roleShortnames) ||
    in_array('admin', $roleShortnames)
) {
    $normalizedRole = 'admin';

} elseif (
    in_array('editingteacher', $roleShortnames) ||
    in_array('teacher', $roleShortnames)
) {
    $normalizedRole = 'teacher';

} elseif (
    in_array('student', $roleShortnames)
) {
    $normalizedRole = 'student';
}

/*
 * ------------------------------------------------------------
 * 4. GET ENROLLED COURSES
 * ------------------------------------------------------------
 */

$courses = enrol_get_users_courses(
    $userid,
    true,
    'id, fullname, shortname'
);

$result = [];

/*
 * ------------------------------------------------------------
 * 5. CURRENT PAGE INFORMATION
 * ------------------------------------------------------------
 */
 

$currentCourseId = isset($_GET['courseid'])
    ? (int)$_GET['courseid']
    : 0;

$currentCmId = isset($_GET['cmid'])
    ? (int)$_GET['cmid']
    : 0;

$currentLocation = null;

/*
 * ------------------------------------------------------------
 * 6. PROCESS EACH ENROLLED COURSE
 * ------------------------------------------------------------
 */

foreach ($courses as $course) {

    $courseData = [
        'id' => (int)$course->id,
        'shortname' => $course->shortname,
        'fullname' => $course->fullname,
        'progress_percent' => null,
        'completed' => [],
        'not_started' => [],
    ];

    $completion = new completion_info($course);

    $modinfo = get_fast_modinfo(
        $course,
        $userid
    );

    /*
     * --------------------------------------------------------
     * CURRENT LOCATION
     * --------------------------------------------------------
     */

    if (
        $currentCourseId &&
        (int)$course->id === $currentCourseId
    ) {

        if (
            $currentCmId &&
            isset($modinfo->cms[$currentCmId]) &&
            $modinfo->cms[$currentCmId]->uservisible
        ) {

            $cm = $modinfo->cms[$currentCmId];

            $isComplete = false;

            if ($completion->is_enabled($cm)) {

                $data = $completion->get_data(
                    $cm,
                    false,
                    $userid
                );

                $isComplete = in_array(
                    $data->completionstate,
                    [
                        COMPLETION_COMPLETE,
                        COMPLETION_COMPLETE_PASS,
                        COMPLETION_COMPLETE_FAIL
                    ]
                );
            }

            $currentLocation = [
                'course_id' => (int)$course->id,
                'course_fullname' => $course->fullname,
                'course_shortname' => $course->shortname,
                'activity_id' => (int)$cm->id,
                'activity_name' => $cm->name,
                'activity_type' => $cm->modname,
                'completed' => $isComplete,
            ];

        } else {

            /*
             * The user is on the course page itself.
             */

            $currentLocation = [
                'course_id' => (int)$course->id,
                'course_fullname' => $course->fullname,
                'course_shortname' => $course->shortname,
                'activity_id' => null,
                'activity_name' => null,
                'activity_type' => null,
                'completed' => null,
            ];
        }
    }

    /*
     * --------------------------------------------------------
     * COURSE COMPLETION
     * --------------------------------------------------------
     */

    if ($completion->is_enabled()) {

        $activities = $completion->get_activities();

        $totalTracked = 0;
        $totalCompleted = 0;

        foreach ($activities as $cm) {

            if (
                !isset($modinfo->cms[$cm->id]) ||
                !$modinfo->cms[$cm->id]->uservisible
            ) {
                continue;
            }

            $totalTracked++;

            $data = $completion->get_data(
                $cm,
                false,
                $userid
            );

            $name = $modinfo->cms[$cm->id]->name;

            $completed = in_array(
                $data->completionstate,
                [
                    COMPLETION_COMPLETE,
                    COMPLETION_COMPLETE_PASS,
                    COMPLETION_COMPLETE_FAIL
                ]
            );

            if ($completed) {

                $courseData['completed'][] = $name;
                $totalCompleted++;

            } else {

                $courseData['not_started'][] = $name;
            }
        }

        if ($totalTracked > 0) {

            $courseData['progress_percent'] = round(
                ($totalCompleted / $totalTracked) * 100
            );
        }

    } else {

        /*
         * Completion tracking is disabled.
         *
         * We cannot determine completed activities,
         * so we list visible activities as not_started.
         */

        foreach ($modinfo->cms as $cm) {

            if (
                $cm->uservisible &&
                $cm->has_view()
            ) {
                $courseData['not_started'][] = $cm->name;
            }
        }
    }

    $result[] = $courseData;
}

/*
 * ------------------------------------------------------------
 * 7. RETURN JSON
 * ------------------------------------------------------------
 */

$response = [
    'success' => true,

    'userid' => $userid,

    'fullname' => fullname($USER),

    'role' => $normalizedRole,

    'roles' => $roles,

    'courses' => $result,

    'current_location' => $currentLocation,

    // Unix timestamp makes expiry validation simple.
    'generated_at' => time(),
];

$payload = json_encode(
    $response,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

$signature = hash_hmac(
    'sha256',
    $payload,
    AICHAT_CONTEXT_SECRET
);

echo json_encode([
    'context' => $response,
    'signature' => $signature,
]);