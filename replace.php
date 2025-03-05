<?php
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// course id
$course   = required_param('course', PARAM_INT);

$syscontext = context_system::instance();
$url = new moodle_url("/admin/tool/coursepacker/index.php");

$PAGE->set_url($url);
$PAGE->set_context($syscontext);
$PAGE->set_title('Search and replace');
$PAGE->set_heading('Custom search and replace tool');

// must be admin user to access this page
require_admin();

echo($OUTPUT->header());
$c = $DB->get_record('course', array('id'=>$course));
$url = $CFG->wwwroot . '/course/view.php?id=' . $course;
echo('<h2><a href="' . $url . '">' . s($c->fullname) . '<a></h2>');
?>
<h3>Scanning for all matches:</h3>
<table class="table">
    <tr><th>Resource type</th><th>Resource</th><th>Name</th><th>Found</th><th>Replace with</th></tr>
<?php

// get all matches from lessons
$lessons = $DB->get_records_sql('SELECT * FROM {lesson} WHERE course=?', [$course]);
foreach($lessons as $s) {    
    $lesson = s($s->name);
    // get all matches from lesson pages
    $pages = $DB->get_records_sql('SELECT * FROM {lesson_pages} WHERE lessonid=?', [$s->id]);
    foreach($pages as $p) {
        $re = '/Time.to.do.Activity.+?([0-9]+).*?!/';
        preg_match_all($re, $p->contents, $matches, PREG_SET_ORDER, 0);
        foreach($matches as $match) {
            $page = $match[1];
            $text = $match[0];
            $name = s($p->title);
            $changeTo = "Time to do Activity $page!";
            $p->contents = str_replace($text, $changeTo, $p->contents);
            $DB->update_record('lesson_pages', $p);
            echo("<tr><td>Lesson page</td><td>$lesson</td><td>$name</td><td>$text</td><td>$changeTo</td></tr>");
        }
    }
}

//get all matches from pages
$pages = $DB->get_records_sql('SELECT * FROM {page} WHERE course=?', [$course]);
foreach($pages as $s) {
    $re = '/Time.to.do.Activity.+?([0-9]+).*?!/';
    $count = preg_match_all($re, $s->content, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $page = $match[1];
        $text = $match[0];
        $name = s($s->name);
        $changeTo = "Time to do Activity $page!";
        $s->content = str_replace($text, $changeTo, $s->content);
        $DB->update_record('page', $s);
        echo("<tr><td>Page</td><td>-</td><td>$name</td><td>$text</td><td>$changeTo</td></tr>");
    }
}


?>
</table>
<?php
?>
<?php
echo($OUTPUT->footer());
?>