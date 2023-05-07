<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A report to display the courses status (stats, counters, general information)
 *
 * @package    report
 * @subpackage usersgrades
 * @copyright  2017 David Herney Bernal - cirano
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once '../../config.php';
require_once $CFG->libdir . '/adminlib.php';
require_once 'locallib.php';
require_once $CFG->dirroot . '/user/filters/lib.php';
require_once $CFG->dirroot . '/grade/report/lib.php';

$sort           = optional_param('sort', 'firstname', PARAM_ALPHANUM);
$dir            = optional_param('dir', 'ASC', PARAM_ALPHA);
$page           = optional_param('page', 0, PARAM_INT);
$perpage        = optional_param('perpage', 30, PARAM_INT);
$format         = optional_param('format', '', PARAM_ALPHA);

admin_externalpage_setup('reportusersgrades', '', null, '', ['pagelayout' => 'report']);

$baseurl = new moodle_url('/report/usersgrades/index.php', ['sort' => $sort, 'dir' => $dir, 'perpage' => $perpage]);

// create the user filter form
$filtering = new user_filtering();

list($extrasql, $params) = $filtering->get_sql_filter();

if ($format) {
    $perpage = 0;
}

$context = context_system::instance();
$site = get_site();

$extracolumns = get_extra_user_fields($context);
// Get all user name fields as an array.
$allusernamefields = get_all_user_name_fields(false, null, null, null, true);
$columns = array_merge($allusernamefields, $extracolumns);

foreach ($columns as $column) {
    $string[$column] = get_user_field_name($column);
    if ($sort != $column) {
        $columnicon = "";
        $columndir = "ASC";
    } else {
        $columndir = $dir == "ASC" ? "DESC":"ASC";
        $columnicon = ($dir == "ASC") ? "sort_asc" : "sort_desc";
        $columnicon = $OUTPUT->pix_icon('t/' . $columnicon, '');

    }
    $$column = "<a href=\"index.php?sort=$column&amp;dir=$columndir\">".$string[$column]."</a>$columnicon";
}

// We need to check that alternativefullnameformat is not set to '' or language.
// We don't need to check the fullnamedisplay setting here as the fullname function call further down has
// the override parameter set to true.
$fullnamesetting = $CFG->alternativefullnameformat;
// If we are using language or it is empty, then retrieve the default user names of just 'firstname' and 'lastname'.
if ($fullnamesetting == 'language' || empty($fullnamesetting)) {
    // Set $a variables to return 'firstname' and 'lastname'.
    $a = new stdClass();
    $a->firstname = 'firstname';
    $a->lastname = 'lastname';
    // Getting the fullname display will ensure that the order in the language file is maintained.
    $fullnamesetting = get_string('fullnamedisplay', null, $a);
}

// Order in string will ensure that the name columns are in the correct order.
$usernames = order_in_string($allusernamefields, $fullnamesetting);
$fullnamedisplay = array();
foreach ($usernames as $name) {
    // Use the link from $$column for sorting on the user's name.
    $fullnamedisplay[] = ${$name};
}
// All of the names are in one column. Put them into a string and separate them with a /.
$fullnamedisplay = implode(' / ', $fullnamedisplay);
// If $sort = name then it is the default for the setting and we should use the first name to sort by.
if ($sort == "name") {
    // Use the first item in the array.
    $sort = reset($usernames);
}

list($extrasql, $params) = $filtering->get_sql_filter();
$users = get_users_listing($sort, $dir, $page*$perpage, $perpage, '', '', '',
        $extrasql, $params, $context);
$usercount = get_users(false);
$usersearchcount = get_users(false, '', false, null, "", '', '', '', '', '*', $extrasql, $params);

$strall = get_string('all');


if ($users) {

    raise_memory_limit(MEMORY_EXTRA);
    foreach ($users as $user) {

        $courses = enrol_get_all_users_courses($user->id);

        $user->courses = array();
        if ($courses) {
            foreach ($courses as $course) {
                $user->courses[$course->id] = $course;

                // Get course grade_item
                $course_item = grade_item::fetch_course_item($course->id);

                // Get the stored grade
                $course_grade = new grade_grade(array('itemid'=>$course_item->id, 'userid'=>$user->id));
                $course_grade->grade_item =& $course_item;
                $finalgrade = $course_grade->finalgrade;

                // We must use the specific max/min because it can be different for
                // each grade_grade when items are excluded from sum of grades.
                if (!is_null($finalgrade)) {
                    $course_item->grademin = $course_grade->get_grade_min();
                    $course_item->grademax = $course_grade->get_grade_max();
                }

                $course->finalgrade = grade_format_gradevalue($finalgrade, $course_item, true);
            }
        }

        $user->fullname = fullname($user, true);
    }

    // Only download data.
    if ($format) {

        $fields = array('userid' => 'id', 'username' => 'username', 'firstname' => 'firstname', 'lastname' => 'lastname', 'email' => 'email');

        $data = array();
        $maxcourses = 1;
        $coursesgrades = array();

        foreach($users as $user) {

            $datarow = new stdClass();
            $datarow->userid    = $user->id;
            $datarow->username  = $user->username;
            $datarow->firstname = $user->firstname;
            $datarow->lastname  = $user->lastname;
            $datarow->email     = $user->email;

            $datarowgrades = new stdClass();
            $datarowgrades->userid    = '';
            $datarowgrades->username  = '';
            $datarowgrades->firstname = '';
            $datarowgrades->lastname  = '';
            $datarowgrades->email     = '';

            if (count($user->courses) > 0) {
                $k = 1;
                foreach($user->courses as $course) {
                    $field = 'course' . $k;
                    $datarow->$field = $course->finalgrade;
                    $datarowgrades->$field = $course->shortname;
                    $k++;
                }

                if (count($user->courses) > $maxcourses) {
                    $maxcourses = count($user->courses);
                }

            } else {
                // Not export users without enroled courses.
                continue;
            }

            $data[] = $datarowgrades;
            $data[] = $datarow;
        }

        for ($i = 1; $i <= $maxcourses; $i++) {
            $fieldname = 'course' . $i;
            $fields[$fieldname] = $fieldname;
        }

        switch ($format) {
            case 'csv' : usersgrades_download_csv($fields, $data);
            case 'ods' : usersgrades_download_ods($fields, $data);
            case 'xls' : usersgrades_download_xls($fields, $data);

        }
        die;
    }
    // End download data.
}

echo $OUTPUT->header();

if ($extrasql !== '') {
    echo $OUTPUT->heading("$usersearchcount / $usercount ".get_string('users'));
    $usercount = $usersearchcount;
} else {
    echo $OUTPUT->heading("$usercount ".get_string('users'));
}

echo $OUTPUT->paging_bar($usercount, $page, $perpage, $baseurl);

flush();


$table = null;

if (!$users) {
    $match = array();
    echo $OUTPUT->heading(get_string('nousersfound'));

    $table = NULL;

} else {

    $table = new html_table();
    $table->head = array ();
    $table->colclasses = array();
    $table->head[] = $fullnamedisplay;
    $table->attributes['class'] = 'admintable generaltable';
    foreach ($extracolumns as $field) {
        $table->head[] = ${$field};
    }
    $table->head[] = get_string('finalgrade', 'grades');
    $table->colclasses[] = 'centeralign';
    $table->id = "users";

    foreach ($users as $user) {
        $gradecolumn = '';

        if (count($user->courses) > 0) {
            $gradecolumn = '<ul>';

            foreach ($user->courses as $course) {
                $gradecolumn .= '<li>';
                $gradecolumn .=     '<strong>' . $course->fullname . ': </strong>';
                $gradecolumn .=     $course->finalgrade;
                $gradecolumn .= '</li>';
            }

            $gradecolumn .= '</ul>';
        }

        $fullname = $user->fullname;

        $row = array ();
        $row[] = "<a href=\"../user/view.php?id=$user->id&amp;course=$site->id\">$fullname</a>";
        foreach ($extracolumns as $field) {
            $row[] = $user->{$field};
        }

        if ($user->suspended) {
            foreach ($row as $k=>$v) {
                $row[$k] = html_writer::tag('span', $v, array('class'=>'usersuspended'));
            }
        }
        $row[] = $gradecolumn;
        $table->data[] = $row;
    }

}


// Add filters.
$filtering->display_add();
$filtering->display_active();

if (!empty($table)) {
    echo html_writer::start_tag('div', array('class'=>'no-overflow'));
    echo html_writer::table($table);
    echo html_writer::end_tag('div');
    echo $OUTPUT->paging_bar($usercount, $page, $perpage, $baseurl);

    // Download form.
    echo $OUTPUT->heading(get_string('download', 'admin'));

    echo $OUTPUT->box_start();
    echo '<ul>';
    echo '    <li><a href="' . $baseurl . '&format=csv">'.get_string('downloadtext').'</a></li>';
    echo '    <li><a href="' . $baseurl . '&format=ods">'.get_string('downloadods').'</a></li>';
    echo '    <li><a href="' . $baseurl . '&format=xls">'.get_string('downloadexcel').'</a></li>';
    echo '</ul>';
    echo $OUTPUT->box_end();
}

echo $OUTPUT->footer();
